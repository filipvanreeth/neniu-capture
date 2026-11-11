<?php
declare(strict_types=1);
require dirname(__DIR__) . '/storage.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/verify-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    // Start from the old schema to exercise migration with existing data.
    $legacy = new PDO('sqlite:' . $directory . '/verify.sqlite');
    $legacy->exec('CREATE TABLE records (code TEXT PRIMARY KEY, created_at TEXT NOT NULL,
        latitude REAL NOT NULL, longitude REAL NOT NULL, accuracy_meters REAL NOT NULL,
        source TEXT NOT NULL, mime TEXT NOT NULL, sha256 TEXT NOT NULL, image BLOB NOT NULL)');
    $legacy->exec("INSERT INTO records VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '2026-01-01T00:00:00Z',
        1, 2, 3, 'browser_geolocation', 'image/jpeg', 'legacy', 'original')");
    $legacy = null;
    $db = database($directory);
    $old = loadRecord(str_repeat('a', 32), $db, $directory);
    check($old !== null && $old['metadata'] === [], 'Legacy record migration');
    check(base64_decode($old['image']['base64']) === 'original', 'Legacy bytes preserved');
    check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', utcNow()) === 1, 'UTC milliseconds');
    check(browserTimestamp('1767225600123') === '2026-01-01T00:00:00.123Z', 'Browser timestamp conversion');
    foreach ([null, [], 'NaN', '-1', '0', '253402300800000'] as $invalid) {
        check(browserTimestamp($invalid) === null, 'Reject malformed browser timestamp');
    }
    $server = ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.2'];
    check(requestIp($server, [])['address'] === '192.0.2.1', 'Ignore untrusted forwarding');
    check(requestIp($server, ['192.0.2.1'])['address'] === '198.51.100.2', 'Trusted proxy forwarding');
    $server['HTTP_X_FORWARDED_FOR'] = '203.0.113.99, 198.51.100.2';
    check(requestIp($server, ['192.0.2.1'])['address'] === '198.51.100.2', 'Ignore spoofed leftmost address');
    check(requestIp($server, ['192.0.2.1', '198.51.100.2'])['address'] === '203.0.113.99', 'Multiple trusted proxies');
    $server['HTTP_X_FORWARDED_FOR'] = 'malformed';
    check(requestIp($server, ['192.0.2.1'])['address'] === '192.0.2.1', 'Malformed proxy fallback');
    $record = [
        'code' => bin2hex(random_bytes(16)),
        'created_at' => utcNow(),
        'location' => ['latitude' => 1, 'longitude' => 2, 'accuracy_meters' => 3, 'source' => 'browser_geolocation'],
        'image' => ['mime' => 'image/jpeg', 'sha256' => hash('sha256', 'bytes')],
        'metadata' => ['browser' => ['source' => 'browser_unverified', 'captured_at' => browserTimestamp('1767225600123')]],
    ];
    check(!saveVerifiedRecord($db, $record, 'bytes', str_repeat('0', 64)), 'Unknown token rejected');
    $session = issueVerificationSession($db);
    check($db->query('SELECT token_hash FROM verification_sessions')->fetchColumn() !== $session['token'], 'Token stored hashed');
    check(saveVerifiedRecord($db, $record, 'bytes', $session['token']), 'First upload succeeds');
    $saved = loadRecord($record['code'], $db, $directory);
    check($saved['metadata']['verification_session']['id'] === $session['session_id'], 'Session linked to record');
    check($saved['metadata']['browser'] === $record['metadata']['browser'], 'Metadata round trip');
    $record['code'] = bin2hex(random_bytes(16));
    check(!saveVerifiedRecord($db, $record, 'bytes', $session['token']), 'Replay rejected');
    $expired = issueVerificationSession($db);
    $db->exec('UPDATE verification_sessions SET expires_at = 0');
    check(!saveVerifiedRecord($db, $record, 'bytes', $expired['token']), 'Expired token rejected');
    $session = issueVerificationSession($db);
    $db->exec("CREATE TRIGGER fail_insert BEFORE INSERT ON records BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    try {
        saveVerifiedRecord($db, $record, 'bytes', $session['token']);
        throw new RuntimeException('Expected storage failure');
    } catch (PDOException $expected) {
        // The transaction must restore the token as well as roll back the record.
    }
    $db->exec('DROP TRIGGER fail_insert');
    check(saveVerifiedRecord($db, $record, 'bytes', $session['token']), 'Token survives failed storage');
    echo "Verification tests passed\n";
} finally {
    $db = null;
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
