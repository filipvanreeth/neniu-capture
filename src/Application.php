<?php
declare(strict_types=1);

namespace Neniu\Verify;

use Throwable;

final class Application
{
    private const VERSION = '1.1.0';

    public function __construct(
        private string $storage,
        private string $locale,
        private Clock $clock,
        private ClientIpResolver $ipResolver,
    ) {
    }

    public function handle(): array
    {
        $locale = $this->locale;
        $receivedAt = $this->clock->now();
        $requestId = bin2hex(random_bytes(16));

        try {
            $db = (new Database($this->storage))->connection();
            $records = new RecordRepository($db, $this->storage);
            $verification = new VerificationService($db, $records, $this->clock);
        } catch (Throwable $error) {
            error_log('Database initialization failed: ' . $error->getMessage());
            $this->respond(['error' => t('storage_unavailable')], 500);
        }

        // Upload endpoint
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_POST['action'] ?? '') === 'session') {
                try {
                    $this->respond($verification->issue());
                } catch (Throwable $error) {
                    error_log('Session creation failed: ' . $error->getMessage());
                    $this->respond(['error' => t('storage_unavailable')], 500);
                }
            }
            if (($_POST['action'] ?? '') !== 'save') {
                $this->respond(['error' => t('invalid_action')], 400);
            }

            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8_000_000) {
                $this->respond(['error' => t('upload_too_large')], 413);
            }

            $token = $_POST['session_token'] ?? '';
            if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
                $this->respond(['error' => t('invalid_session')], 409);
            }
            $capturedAt = $this->clock->fromBrowserTimestamp($_POST['captured_at'] ?? null);
            $locationAt = $this->clock->fromBrowserTimestamp($_POST['location_timestamp'] ?? null);
            if ($capturedAt === null || $locationAt === null) {
                $this->respond(['error' => t('invalid_timestamps')], 400);
            }
            $dataUrl = $_POST['image'] ?? '';
            if (!is_string($dataUrl)) {
                $this->respond(['error' => t('invalid_image')], 400);
            }
            if (
                !preg_match(
                    '#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#',
                    $dataUrl,
                    $matches
                )
            ) {
                $this->respond(['error' => t('invalid_image')], 400);
            }

            $bytes = base64_decode($matches[1], true);

            $imageInfo = $bytes !== false ? @getimagesizefromstring($bytes) : false;
            if (
                $bytes === false || strlen($bytes) > 5_000_000 ||
                !$imageInfo || $imageInfo[2] !== IMAGETYPE_JPEG
            ) {
                $this->respond(['error' => t('invalid_image_data')], 400);
            }

            $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['lon'] ?? null, FILTER_VALIDATE_FLOAT);
            $accuracy = filter_var($_POST['accuracy'] ?? null, FILTER_VALIDATE_FLOAT);

            if (
                $lat === false || $lon === false ||
                $lat < -90 || $lat > 90 ||
                $lon < -180 || $lon > 180 ||
                $accuracy === false || !is_finite($accuracy) || $accuracy < 0
            ) {
                $this->respond(['error' => t('invalid_gps')], 400);
            }

            $code = bin2hex(random_bytes(16));

            $record = [
                'code' => $code,
                'created_at' => $receivedAt,
                'metadata' => [
                    'request' => [
                        'source' => 'server',
                        'id' => $requestId,
                        'received_at' => $receivedAt,
                        'ip' => $this->ipResolver->resolve($_SERVER),
                        'application_version' => self::VERSION,
                    ],
                    'browser' => [
                        'source' => 'browser_unverified',
                        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 2048),
                        'captured_at' => $capturedAt,
                        'location_measured_at' => $locationAt,
                    ],
                    'image' => [
                        'source' => 'server_from_received_file',
                        'width' => $imageInfo[0],
                        'height' => $imageInfo[1],
                        'size_bytes' => strlen($bytes),
                    ],
                ],
                'location' => [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'accuracy_meters' => $accuracy,
                    'source' => 'browser_geolocation'
                ],
                'image' => [
                    'mime' => 'image/jpeg',
                    'sha256' => hash('sha256', $bytes),
                    'base64' => base64_encode($bytes)
                ]
            ];

            try {
                if (!$verification->save($record, $bytes, $token)) {
                    $this->respond(['error' => t('invalid_session')], 409);
                }
            } catch (Throwable $error) {
                error_log('Record storage failed: ' . $error->getMessage());
                $this->respond(['error' => t('storage_failed')], 500);
            }

            $this->respond([
                'code' => $code,
                'url' => '?verify=' . $code . '&lang=' . rawurlencode($locale)
            ]);
        }

        // Verification endpoint
        $record = null;
        $hashValid = false;

        if (isset($_GET['verify'])) {
            try {
                $record = is_string($_GET['verify']) ? $records->find($_GET['verify']) : null;
            } catch (Throwable $error) {
                error_log('Record lookup failed: ' . $error->getMessage());
                $this->respond(['error' => t('storage_unavailable')], 500);
            }

            if ($record) {
                $bytes = base64_decode($record['image']['base64'], true);
                $hashValid = $bytes !== false &&
                    hash_equals(
                        $record['image']['sha256'],
                        hash('sha256', $bytes)
                    );
            }
        }
        return ['record' => $record, 'hashValid' => $hashValid];
    }

    private function respond(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
