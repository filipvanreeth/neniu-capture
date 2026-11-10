<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';
$storage = __DIR__ . '/storage';

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
    respond(['error' => 'Storage unavailable'], 500);
}

// Upload endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') !== 'save') {
        respond(['error' => 'Invalid action'], 400);
    }

    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8_000_000) {
        respond(['error' => 'Upload too large'], 413);
    }

    $dataUrl = $_POST['image'] ?? '';
    if (
        !preg_match(
            '#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#',
            $dataUrl,
            $matches
        )
    ) {
        respond(['error' => 'Invalid image'], 400);
    }

    $bytes = base64_decode($matches[1], true);

    if (
        $bytes === false || strlen($bytes) > 5_000_000 ||
        !getimagesizefromstring($bytes)
    ) {
        respond(['error' => 'Invalid image data'], 400);
    }

    $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lon = filter_var($_POST['lon'] ?? null, FILTER_VALIDATE_FLOAT);
    $accuracy = filter_var($_POST['accuracy'] ?? null, FILTER_VALIDATE_FLOAT);

    if (
        $lat === false || $lon === false ||
        $lat < -90 || $lat > 90 ||
        $lon < -180 || $lon > 180 ||
        $accuracy === false || $accuracy < 0
    ) {
        respond(['error' => 'Invalid GPS data'], 400);
    }

    $code = bin2hex(random_bytes(16));

    $record = [
        'code' => $code,
        'created_at' => gmdate('c'),
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
        saveRecord($db, $record, $bytes);
    } catch (Throwable $error) {
        error_log('Record storage failed: ' . $error->getMessage());
        respond(['error' => 'Storage failed'], 500);
    }

    respond([
        'code' => $code,
        'url' => "?verify=$code"
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
        respond(['error' => 'Storage unavailable'], 500);
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
<html lang="nl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Neniu Verify — Prototype</title>
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
            padding: 12px 18px;
            background: #202020;
            color: white;
            border: 0;
            border-radius: 8px;
            cursor: pointer;
        }

        button:disabled {
            opacity: .5;
        }

        pre {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        #result {
            overflow-wrap: anywhere;
        }
    </style>
</head>

<body>
    <main>
        <h1>Neniu Verify</h1>
        <p>Experimentele foto- en locatieverificatie.</p>

        <?php if (isset($_GET['verify'])): ?>

            <?php if (!$record): ?>
                <p>Verificatiecode niet gevonden.</p>
            <?php else: ?>
                <h2>Verificatieresultaat</h2>

                <p>
                    Integriteit:
                    <strong>
                        <?= $hashValid ? 'Geldig' : 'ONGELDIG' ?>
                    </strong>
                </p>

                <?php if ($hashValid): ?>
                    <img alt="Geregistreerde foto" src="data:image/jpeg;base64,<?= htmlspecialchars(
                        $record['image']['base64']
                    ) ?>">
                <?php endif; ?>

                <p>Code:
                    <?= htmlspecialchars($record['code']) ?>
                </p>
                <p>Tijdstip:
                    <?= htmlspecialchars($record['created_at']) ?>
                </p>
                <p>GPS:
                    <?= htmlspecialchars((string) $record['location']['latitude']) ?>,
                    <?= htmlspecialchars((string) $record['location']['longitude']) ?>
                </p>
                <p>Nauwkeurigheid:
                    <?= htmlspecialchars((string) $record['location']['accuracy_meters']) ?> meter
                </p>

                <p><small>
                        GPS is door de browser gerapporteerd en
                        niet onafhankelijk geverifieerd.
                    </small></p>
            <?php endif; ?>

            <a href="index.php">Nieuwe opname</a>

        <?php else: ?>

            <p>
                Je camera en locatie worden uitsluitend gebruikt
                nadat je toestemming geeft en op de opnameknop drukt.
            </p>

            <video id="video" autoplay playsinline muted></video>
            <canvas id="canvas" hidden></canvas>

            <button id="start">Camera starten</button>
            <button id="switch-camera" disabled>Wissel camera</button>
            <button id="capture" disabled>Foto maken en opslaan</button>

            <p id="status"></p>
            <div id="result"></div>

            <script>
                const video = document.querySelector('#video');
                const canvas = document.querySelector('#canvas');
                const status = document.querySelector('#status');
                const result = document.querySelector('#result');
                const start = document.querySelector('#start');
                const capture = document.querySelector('#capture');
                const switchCamera = document.querySelector('#switch-camera');

                let stream = null;
                let facingMode = 'environment';

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
                            ? 'Naar achtercamera' : 'Naar frontcamera';
                        status.textContent = 'Camera gereed.';
                    } catch (error) {
                        if (stream) {
                            stream.getTracks().forEach(track => track.stop());
                            stream = null;
                        }
                        status.textContent = 'Camera niet beschikbaar: ' + error.message;
                        throw error;
                    } finally {
                        start.disabled = false;
                    }
                }

                start.onclick = () => openCamera(facingMode).catch(() => {});

                switchCamera.onclick = async () => {
                    const previousMode = facingMode;
                    try {
                        await openCamera(facingMode === 'user' ? 'environment' : 'user', true);
                    } catch (error) {
                        // Restore the current camera if the requested camera is unavailable.
                        try {
                            await openCamera(previousMode);
                            status.textContent = 'De andere camera is niet beschikbaar. Huidige camera hersteld.';
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
                    status.textContent = 'Locatie opvragen...';

                    try {
                        const position = await getLocation();

                        if (!video.videoWidth || !video.videoHeight) {
                            throw new Error('Camera is nog niet gereed.');
                        }

                        // Capture the original image
                        const original = document.createElement('canvas');
                        original.width = video.videoWidth;
                        original.height = video.videoHeight;
                        original.getContext('2d').drawImage(
                            video, 0, 0, original.width, original.height
                        );

                        const image = original.toDataURL('image/jpeg', 0.85);

                        status.textContent = 'Foto opslaan...';

                        const form = new FormData();
                        form.append('action', 'save');
                        form.append('image', image);
                        form.append('lat', position.coords.latitude);
                        form.append('lon', position.coords.longitude);
                        form.append('accuracy', position.coords.accuracy);

                        const response = await fetch(location.pathname, {
                            method: 'POST',
                            body: form
                        });

                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.error || 'Opslaan mislukt.');
                        }

                        // Display a separate annotated preview
                        canvas.width = original.width;
                        canvas.height = original.height;

                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(original, 0, 0);

                        const fontSize = Math.max(14, canvas.width / 35);
                        ctx.font = `${fontSize}px sans-serif`;

                        const lines = [
                            `NENIU: ${data.code}`,
                            `GPS: ${position.coords.latitude.toFixed(5)}, ` +
                            position.coords.longitude.toFixed(5),
                            `UTC: ${new Date().toISOString()}`
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

                        const link = document.createElement('a');
                        link.href = new URL(data.url, location.href).href;
                        link.textContent = 'Bekijk verificatieresultaat';

                        result.replaceChildren(link);
                        status.textContent = 'Foto succesvol opgeslagen.';

                    } catch (error) {
                        status.textContent = 'Fout: ' + error.message;
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
