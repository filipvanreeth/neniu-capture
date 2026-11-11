<?php
declare(strict_types=1);

use Neniu\Verify\Application;
use Neniu\Verify\ClientIpResolver;
use Neniu\Verify\Clock;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/i18n.php';
header('Content-Language: ' . $locale);

$application = new Application(
    __DIR__ . '/storage',
    $locale,
    new Clock(),
    new ClientIpResolver(array_values(array_filter(array_map(
        'trim', explode(',', getenv('TRUSTED_PROXIES') ?: '')
    )))),
);
$view = $application->handle();
$record = $view['record'];
$hashValid = $view['hashValid'];
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