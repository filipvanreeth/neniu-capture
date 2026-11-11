<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/i18n.php';
header('Content-Language: ' . $locale);
$storage = __DIR__ . '/storage';
const APP_VERSION = '1.1.0';
$receivedAt = utcNow();
$requestId = bin2hex(random_bytes(16));

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $db = database($storage);
} catch (Throwable $error) {
    error_log('Database initialization failed: ' . $error->getMessage());
    respond(['error' => t('storage_unavailable')], 500);
}

// Upload endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'session') {
        try {
            respond(issueVerificationSession($db));
        } catch (Throwable $error) {
            error_log('Session creation failed: ' . $error->getMessage());
            respond(['error' => t('storage_unavailable')], 500);
        }
    }
    if (($_POST['action'] ?? '') !== 'save') {
        respond(['error' => t('invalid_action')], 400);
    }

    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8_000_000) {
        respond(['error' => t('upload_too_large')], 413);
    }

    $token = $_POST['session_token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        respond(['error' => t('invalid_session')], 409);
    }
    $capturedAt = browserTimestamp($_POST['captured_at'] ?? null);
    $locationAt = browserTimestamp($_POST['location_timestamp'] ?? null);
    if ($capturedAt === null || $locationAt === null) {
        respond(['error' => t('invalid_timestamps')], 400);
    }
    $dataUrl = $_POST['image'] ?? '';
    if (!is_string($dataUrl)) {
        respond(['error' => t('invalid_image')], 400);
    }
    if (
        !preg_match(
            '#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#',
            $dataUrl,
            $matches
        )
    ) {
        respond(['error' => t('invalid_image')], 400);
    }

    $bytes = base64_decode($matches[1], true);

    $imageInfo = $bytes !== false ? @getimagesizefromstring($bytes) : false;
    if (
        $bytes === false || strlen($bytes) > 5_000_000 ||
        !$imageInfo || $imageInfo[2] !== IMAGETYPE_JPEG
    ) {
        respond(['error' => t('invalid_image_data')], 400);
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
        respond(['error' => t('invalid_gps')], 400);
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
                'ip' => requestIp($_SERVER, array_values(array_filter(array_map(
                    'trim',
                    explode(',', getenv('TRUSTED_PROXIES') ?: '')
                )))),
                'application_version' => APP_VERSION,
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
        if (!saveVerifiedRecord($db, $record, $bytes, $token)) {
            respond(['error' => t('invalid_session')], 409);
        }
    } catch (Throwable $error) {
        error_log('Record storage failed: ' . $error->getMessage());
        respond(['error' => t('storage_failed')], 500);
    }

    respond([
        'code' => $code,
        'url' => '?verify=' . $code . '&lang=' . rawurlencode($locale)
    ]);
}

// Verification endpoint
$record = null;
$hashValid = false;

if (isset($_GET['verify'])) {
    try {
        $record = is_string($_GET['verify']) ? loadRecord($_GET['verify'], $db, $storage) : null;
    } catch (Throwable $error) {
        error_log('Record lookup failed: ' . $error->getMessage());
        respond(['error' => t('storage_unavailable')], 500);
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
?>
<!DOCTYPE html>
<html lang="<?= e($locale) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('page_title')) ?></title>
    <style>
        body {
            font: 16px system-ui, sans-serif;
            background: #f4f4f5;
            margin: 0;
            padding: 24px;
        }

        main {
            max-width: 540px;
            margin: auto;
            background: white;
            padding: 24px;
            border-radius: 14px;
        }

        video,
        canvas,
        img {
            width: 100%;
            border-radius: 8px;
            margin: 12px 0;
        }

        button {
            min-height: 48px;
            padding: 12px 18px;
            font: inherit;
            line-height: 1.3;
            background: #202020;
            color: white;
            border: 0;
            border-radius: 8px;
            cursor: pointer;
        }

        .camera-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .camera-actions #capture {
            grid-column: 1 / -1;
        }

        @media (max-width: 480px) {
            body {
                padding: 12px;
            }

            main {
                padding: 20px;
            }

            .camera-actions {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        button:disabled {
            opacity: .5;
        }

        pre {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
    </style>
</head>

<body>
    <main>
        <h1><?= e(t('app_name')) ?></h1>
        <p><?= e(t('intro')) ?></p>

        <?php if (isset($_GET['verify'])): ?>

            <?php if (!$record): ?>
                <p><?= e(t('not_found')) ?></p>
            <?php else: ?>
                <h2><?= e(t('verification_result')) ?></h2>

                <p>
                    <?= e(t('integrity')) ?>:
                    <strong>
                        <?= e(t($hashValid ? 'valid' : 'invalid')) ?>
                    </strong>
                </p>

                <?php if ($hashValid): ?>
                    <img alt="<?= e(t('photo_alt')) ?>" src="data:image/jpeg;base64,<?= htmlspecialchars(
                          $record['image']['base64']
                      ) ?>">
                <?php endif; ?>

                <p><?= e(t('code')) ?>:
                    <?= htmlspecialchars($record['code']) ?>
                </p>
                <p><?= e(t('timestamp')) ?>:
                    <?= htmlspecialchars($record['created_at']) ?>
                </p>
                <p><?= e(t('gps')) ?>:
                    <?= htmlspecialchars((string) $record['location']['latitude']) ?>,
                    <?= htmlspecialchars((string) $record['location']['longitude']) ?>
                </p>
                <p><?= e(t('accuracy')) ?>:
                    <?= htmlspecialchars((string) $record['location']['accuracy_meters']) ?>         <?= e(t('meters')) ?>
                </p>

                <p><small>
                        <?= e(t('gps_notice')) ?>
                    </small></p>
            <?php endif; ?>

            <a href="index.php?lang=<?= e($locale) ?>"><?= e(t('new_capture')) ?></a>

        <?php else: ?>

            <p>
                <?= e(t('consent_notice')) ?>
            </p>

            <video id="video" autoplay playsinline muted></video>
            <canvas id="canvas" hidden></canvas>

            <div class="camera-actions">
                <button id="start"><?= e(t('start_camera')) ?></button>
                <button id="switch-camera" disabled><?= e(t('switch_camera')) ?></button>
                <button id="capture" disabled><?= e(t('capture')) ?></button>
            </div>

            <p id="status"></p>

            <script>
                const locale = <?= json_encode($locale) ?>;
                const messages = <?= json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
                function t(key, parameters = {}) {
                    return (messages[key] ?? key).replace(/\{(\w+)\}/g,
                        (placeholder, name) => Object.hasOwn(parameters, name) ? String(parameters[name]) : placeholder);
                }

                const video = document.querySelector('#video');
                const canvas = document.querySelector('#canvas');
                const status = document.querySelector('#status');
                const start = document.querySelector('#start');
                const capture = document.querySelector('#capture');
                const switchCamera = document.querySelector('#switch-camera');

                let stream = null;
                let facingMode = 'user';

                async function openCamera(requestedMode, exact = false) {
                    start.disabled = true;
                    capture.disabled = true;
                    switchCamera.disabled = true;
                    if (stream) {
                        stream.getTracks().forEach(track => track.stop());
                        stream = null;
                    }
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({
                            video: { facingMode: exact ? { exact: requestedMode } : { ideal: requestedMode } },
                            audio: false
                        });
                        video.srcObject = stream;
                        video.hidden = false;
                        video.style.removeProperty('display');
                        await video.play();
                        canvas.hidden = true;
                        facingMode = stream.getVideoTracks()[0].getSettings().facingMode || requestedMode;
                        capture.disabled = false;
                        switchCamera.disabled = false;
                        switchCamera.textContent = facingMode === 'user'
                            ? t('rear_camera') : t('front_camera');
                        status.textContent = t('camera_ready');
                    } catch (error) {
                        if (stream) {
                            stream.getTracks().forEach(track => track.stop());
                            stream = null;
                        }
                        status.textContent = t('camera_unavailable', { message: error.message });
                        throw error;
                    } finally {
                        start.disabled = false;
                    }
                }

                start.onclick = () => openCamera(facingMode).catch(() => { });

                switchCamera.onclick = async () => {
                    const previousMode = facingMode;
                    try {
                        await openCamera(facingMode === 'user' ? 'environment' : 'user', true);
                    } catch (error) {
                        // Restore the current camera if the requested camera is unavailable.
                        try {
                            await openCamera(previousMode);
                            status.textContent = t('camera_restored');
                        } catch (restoreError) {
                            // openCamera already displays the error and enables restarting.
                        }
                    }
                };

                function getLocation() {
                    return new Promise((resolve, reject) => {
                        navigator.geolocation.getCurrentPosition(
                            resolve,
                            reject,
                            {
                                enableHighAccuracy: true,
                                timeout: 15000,
                                maximumAge: 0
                            }
                        );
                    });
                }

                capture.onclick = async () => {
                    capture.disabled = true;
                    start.disabled = true;
                    switchCamera.disabled = true;
                    status.textContent = t('requesting_location');

                    try {
                        const sessionForm = new FormData();
                        sessionForm.append('action', 'session');
                        const sessionResponse = await fetch(location.pathname + '?lang=' + encodeURIComponent(locale), {
                            method: 'POST', body: sessionForm
                        });
                        const session = await sessionResponse.json();
                        if (!sessionResponse.ok) {
                            throw new Error(session.error || t('save_failed'));
                        }
                        const position = await getLocation();

                        if (!video.videoWidth || !video.videoHeight) {
                            throw new Error(t('camera_not_ready'));
                        }

                        // Capture the original image
                        const original = document.createElement('canvas');
                        original.width = video.videoWidth;
                        original.height = video.videoHeight;
                        original.getContext('2d').drawImage(
                            video, 0, 0, original.width, original.height
                        );

                        const capturedAt = Date.now();
                        const image = original.toDataURL('image/jpeg', 0.85);

                        status.textContent = t('saving_photo');

                        const form = new FormData();
                        form.append('action', 'save');
                        form.append('image', image);
                        form.append('session_token', session.token);
                        form.append('captured_at', String(capturedAt));
                        form.append('location_timestamp', String(Math.trunc(position.timestamp)));
                        form.append('lat', position.coords.latitude);
                        form.append('lon', position.coords.longitude);
                        form.append('accuracy', position.coords.accuracy);

                        const response = await fetch(location.pathname + '?lang=' + encodeURIComponent(locale), {
                            method: 'POST',
                            body: form
                        });

                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.error || t('save_failed'));
                        }

                        // Display a separate annotated preview
                        canvas.width = original.width;
                        canvas.height = original.height;

                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(original, 0, 0);

                        const fontSize = Math.max(14, canvas.width / 35);
                        ctx.font = `${fontSize}px sans-serif`;

                        const lines = [
                            `CODE: ${data.code}`,
                            `LOCATION: ${position.coords.latitude.toFixed(5)}, ` +
                            position.coords.longitude.toFixed(5),
                            `UTC: ${new Date(capturedAt).toISOString()}`
                        ];

                        const lineHeight = fontSize * 1.4;
                        const boxHeight = lineHeight * lines.length + 20;

                        ctx.fillStyle = 'rgba(0,0,0,0.75)';
                        ctx.fillRect(
                            0,
                            canvas.height - boxHeight,
                            canvas.width,
                            boxHeight
                        );

                        ctx.fillStyle = 'white';
                        lines.forEach((line, index) => {
                            ctx.fillText(
                                line,
                                10,
                                canvas.height - boxHeight + 15 +
                                lineHeight * (index + 0.7)
                            );
                        });

                        video.hidden = true;
                        video.style.display = 'none';
                        canvas.hidden = false;

                        if (stream) {
                            stream.getTracks().forEach(track => track.stop());
                            stream = null;
                        }
                        start.disabled = false;

                        status.textContent = t('photo_saved');

                    } catch (error) {
                        status.textContent = t('error', { message: error.message });
                        capture.disabled = false;
                        start.disabled = false;
                        switchCamera.disabled = false;
                    }
                };
            </script>

        <?php endif; ?>
    </main>
</body>

</html>