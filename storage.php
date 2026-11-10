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
    return $db;
}

function saveRecord(PDO $db, array $record, string $bytes): void
{
    $query = $db->prepare('INSERT INTO records
        (code, created_at, latitude, longitude, accuracy_meters, source, mime, sha256, image)
        VALUES (:code, :created_at, :latitude, :longitude, :accuracy, :source, :mime, :sha256, :image)
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
