<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/production-test-' . bin2hex(random_bytes(8));
mkdir($directory . '/public/build/.vite', 0700, true);
try {
    copy($projectRoot . '/public/index.php', $directory . '/public/index.php');
    foreach (['vendor', 'templates', 'translations'] as $name) {
        symlink($projectRoot . '/' . $name, $directory . '/' . $name);
    }
    file_put_contents($directory . '/public/build/.vite/manifest.json', json_encode([
        'assets/css/app.css' => ['file' => 'assets/app-production.css'],
        'assets/js/capture.js' => ['file' => 'assets/capture-production.js'],
    ], JSON_THROW_ON_ERROR));
    $script = '$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/"; '
        . '$_SERVER["SCRIPT_NAME"] = "/index.php"; require $argv[1];';
    $process = proc_open([PHP_BINARY, '-r', $script, $directory . '/public/index.php'], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, array_merge(getenv(), [
        'APP_ENV' => 'production',
        'APP_URL' => 'https://capture.example.test/',
        'VITE_DEV_SERVER' => 'https://unused-vite.example.test',
        'STORAGE_PATH' => $directory . '/storage',
    ]));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run production frontcontroller');
    }
    $html = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (
        $exitCode !== 0 || $errors !== ''
        || !str_contains($html, '/build/assets/app-production.css')
        || !str_contains($html, '/build/assets/capture-production.js')
        || str_contains($html, 'unused-vite.example.test')
        || str_contains($html, '/@vite/client')
        || !str_contains($html, 'https:\/\/capture.example.test')
    ) {
        throw new RuntimeException('Production must use manifest assets regardless of VITE_DEV_SERVER');
    }
    echo "Production frontend tests passed\n";
} finally {
    foreach (['vendor', 'templates', 'translations'] as $name) {
        if (is_link($directory . '/' . $name)) {
            unlink($directory . '/' . $name);
        }
    }
    foreach (glob($directory . '/storage/*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($directory . '/storage')) {
        rmdir($directory . '/storage');
    }
    unlink($directory . '/public/index.php');
    unlink($directory . '/public/build/.vite/manifest.json');
    rmdir($directory . '/public/build/.vite');
    rmdir($directory . '/public/build');
    rmdir($directory . '/public');
    rmdir($directory);
}
