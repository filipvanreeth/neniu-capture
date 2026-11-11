<?php

declare(strict_types=1);

use Neniu\Capture\ViteAssets;

require dirname(__DIR__) . '/vendor/autoload.php';

$directory = sys_get_temp_dir() . '/vite-test-' . bin2hex(random_bytes(8));
mkdir($directory . '/.vite', 0700, true);
try {
    $assets = new ViteAssets($directory, '/app');
    try {
        $assets->forPage(true);
        throw new LogicException('Expected a missing-build error');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'npm run build')) {
            throw $error;
        }
    }
    file_put_contents($directory . '/.vite/manifest.json', json_encode([
        'assets/css/app.css' => ['file' => 'assets/app-hash.css'],
        'assets/js/capture.js' => ['file' => 'assets/capture-hash.js'],
    ], JSON_THROW_ON_ERROR));
    $capture = $assets->forPage(true);
    if (
        $capture['stylesheet'] !== '/app/build/assets/app-hash.css'
        || $capture['capture'] !== '/app/build/assets/capture-hash.js'
        || $capture['devClient'] !== null
        || $assets->forPage(false)['capture'] !== null
    ) {
        throw new RuntimeException('Production asset URLs or page isolation');
    }
    $dev = (new ViteAssets($directory, '/app', 'http://localhost:5173/'))->forPage(true);
    if (
        $dev['devClient'] !== 'http://localhost:5173/@vite/client'
        || $dev['stylesheet'] !== 'http://localhost:5173/assets/css/app.css'
        || $dev['capture'] !== 'http://localhost:5173/assets/js/capture.js'
    ) {
        throw new RuntimeException('Development asset URLs');
    }
    echo "Asset tests passed\n";
} finally {
    if (is_file($directory . '/.vite/manifest.json')) {
        unlink($directory . '/.vite/manifest.json');
    }
    rmdir($directory . '/.vite');
    rmdir($directory);
}
