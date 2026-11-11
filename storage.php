<?php
declare(strict_types=1);

function database(string $storage): PDO
{
    if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
        throw new RuntimeException('Cannot create storage directory');
    }

    $path = $storage . '/verify.sqlite';
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    chmod($path, 0600);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('CREATE TABLE IF NOT EXISTS records (
        code TEXT PRIMARY KEY,
        created_at TEXT NOT NULL,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        accuracy_meters REAL NOT NULL,
        source TEXT NOT NULL,
        mime TEXT NOT NULL,
        sha256 TEXT NOT NULL,
        image BLOB NOT NULL
    )');
    $columns = $db->query('PRAGMA table_info(records)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('metadata', $columns, true)) {
        $db->exec("ALTER TABLE records ADD COLUMN metadata TEXT NOT NULL DEFAULT '{}'");
    }
    $db->exec('CREATE TABLE IF NOT EXISTS verification_sessions (
        token_hash TEXT PRIMARY KEY,
        session_id TEXT NOT NULL,
        issued_at TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    )');
    return $db;
}

function saveRecord(PDO $db, array $record, string $bytes): void
{
    $query = $db->prepare('INSERT INTO records
        (code, created_at, latitude, longitude, accuracy_meters, source, mime, sha256, image, metadata)
        VALUES (:code, :created_at, :latitude, :longitude, :accuracy, :source, :mime, :sha256, :image, :metadata)
        ON CONFLICT(code) DO NOTHING');
    $query->bindValue(':code', $record['code']);
    $query->bindValue(':created_at', $record['created_at']);
    $query->bindValue(':latitude', $record['location']['latitude']);
    $query->bindValue(':longitude', $record['location']['longitude']);
    $query->bindValue(':accuracy', $record['location']['accuracy_meters']);
    $query->bindValue(':source', $record['location']['source']);
    $query->bindValue(':mime', $record['image']['mime']);
    $query->bindValue(':sha256', $record['image']['sha256']);
    $query->bindValue(':image', $bytes, PDO::PARAM_LOB);
    $query->bindValue(':metadata', json_encode($record['metadata'] ?? new stdClass(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    $query->execute();
}

function loadRecord(string $code, PDO $db, string $storage): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $code)) {
        return null;
    }

    $query = $db->prepare('SELECT * FROM records WHERE code = :code');
    $query->execute(['code' => $code]);
    $row = $query->fetch();
    if ($row) {
        return [
            'code' => $row['code'],
            'created_at' => $row['created_at'],
            'metadata' => json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR),
            'location' => [
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'accuracy_meters' => $row['accuracy_meters'],
                'source' => $row['source'],
            ],
            'image' => [
                'mime' => $row['mime'],
                'sha256' => $row['sha256'],
                'base64' => base64_encode($row['image']),
            ],
        ];
    }

    // Import legacy records on first access; keep the original as a backup.
    $path = "$storage/$code.json";
    if (!is_file($path)) {
        return null;
    }
    $record = json_decode(file_get_contents($path), true);
    if (!is_array($record) || ($record['code'] ?? null) !== $code) {
        return null;
    }
    $bytes = base64_decode($record['image']['base64'] ?? '', true);
    if ($bytes === false || !isset(
        $record['created_at'], $record['location']['latitude'],
        $record['location']['longitude'], $record['location']['accuracy_meters'],
        $record['location']['source'], $record['image']['mime'], $record['image']['sha256']
    )) {
        return null;
    }
    saveRecord($db, $record, $bytes);
    return $record;
}

function utcNow(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.v\\Z');
}

function issueVerificationSession(PDO $db): array
{
    $token = bin2hex(random_bytes(32));
    $session = [
        'token' => $token,
        'session_id' => bin2hex(random_bytes(16)),
        'issued_at' => utcNow(),
        'expires_at' => time() + 600,
    ];
    $db->prepare('DELETE FROM verification_sessions WHERE expires_at <= ?')->execute([time()]);
    $db->prepare('INSERT INTO verification_sessions VALUES (?, ?, ?, ?)')->execute([
        hash('sha256', $token), $session['session_id'], $session['issued_at'], $session['expires_at'],
    ]);
    return $session;
}

// Returns false for expired, unknown or already consumed tokens. Consumption and
// record insertion share one write transaction, including rollback on failure.
function saveVerifiedRecord(PDO $db, array $record, string $bytes, string $token): bool
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $query = $db->prepare('SELECT * FROM verification_sessions WHERE token_hash = ? AND expires_at > ?');
        $query->execute([hash('sha256', $token), time()]);
        $session = $query->fetch();
        if (!$session) {
            $db->exec('ROLLBACK');
            return false;
        }
        $record['metadata']['verification_session'] = [
            'source' => 'server',
            'id' => $session['session_id'],
            'issued_at' => $session['issued_at'],
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', (int) $session['expires_at']),
        ];
        saveRecord($db, $record, $bytes);
        $db->prepare('DELETE FROM verification_sessions WHERE token_hash = ?')->execute([$session['token_hash']]);
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function requestIp(array $server, array $trustedProxies): array
{
    $peer = $server['REMOTE_ADDR'] ?? '';
    if (!filter_var($peer, FILTER_VALIDATE_IP)) {
        return ['address' => null, 'source' => 'remote_addr'];
    }
    $chain = explode(',', $server['HTTP_X_FORWARDED_FOR'] ?? '');
    $address = $peer;
    $source = 'remote_addr';
    while ($chain && in_array($address, $trustedProxies, true)) {
        $candidate = trim(array_pop($chain));
        if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
            return ['address' => $peer, 'source' => 'remote_addr'];
        }
        $address = $candidate;
        $source = 'trusted_proxy_x_forwarded_for';
    }
    return ['address' => $address, 'source' => $source];
}

function browserTimestamp(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/^[0-9]{1,15}$/D', $value)) {
        return null;
    }
    $milliseconds = (int) $value;
    if ($milliseconds <= 0 || $milliseconds > 253402300799999) {
        return null;
    }
    return gmdate('Y-m-d\\TH:i:s', intdiv($milliseconds, 1000))
        . sprintf('.%03dZ', $milliseconds % 1000);
}
