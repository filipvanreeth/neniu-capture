<?php
declare(strict_types=1);

// Router for the local PHP development server. Only built assets bypass PHP.
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'], 2)[0]);
$file = realpath(__DIR__ . '/public' . $path);
$build = realpath(__DIR__ . '/public/build/assets');
if ($file !== false && $build !== false && is_file($file)
    && str_starts_with($file, $build . DIRECTORY_SEPARATOR)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/public/index.php';
