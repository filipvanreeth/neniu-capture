<?php

declare(strict_types=1);

use Neniu\Capture\Application;
use Dotenv\Dotenv;
use Neniu\Capture\ClientIpResolver;
use Neniu\Capture\Clock;
use Neniu\Capture\Router;
use Neniu\Capture\TemplateRenderer;
use Neniu\Capture\ViteAssets;
use Neniu\Capture\Translator;

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/vendor/autoload.php';
try {
    Dotenv::createImmutable($projectRoot)->safeLoad();
} catch (Throwable $error) {
    // Parser exceptions can contain .env values; never print or log their contents.
    error_log('Application configuration failed: ' . get_class($error));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('Application configuration unavailable.');
}
// phpdotenv preserves existing server/container variables.
$environment = $_ENV + $_SERVER;
$appUrl = rtrim(trim($environment['APP_URL'] ?? ''), '/');
if ($appUrl !== '') {
    $parts = parse_url($appUrl);
    if (
        !filter_var($appUrl, FILTER_VALIDATE_URL)
        || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !empty($parts['path'])
    ) {
        error_log('APP_URL must be an HTTP(S) origin without path, credentials, query or fragment.');
        http_response_code(500);
        exit('Application configuration unavailable.');
    }
}
$storage = $environment['STORAGE_PATH'] ?? 'storage';
$storage = str_starts_with($storage, '/') ? $storage : $projectRoot . '/' . $storage;
$viteDevServer = in_array($environment['APP_ENV'] ?? 'production', ['development', 'testing'], true)
    ? ($environment['VITE_DEV_SERVER'] ?? null) : null;

$translator = new Translator($projectRoot . '/translations', $_GET['lang'] ?? 'en');
$locale = $translator->locale();
header('Content-Language: ' . $locale);

$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/.');
$router = new Router($basePath);
$application = new Application(
    $storage,
    $translator,
    new Clock(),
    new ClientIpResolver(),
    $router,
);
$view = $application->handle();
$renderer = new TemplateRenderer($projectRoot . '/templates', $translator);
$assets = new ViteAssets(__DIR__ . '/build', $basePath, $viteDevServer);
$template = $view['template'] ?? ($view['isVerification'] ? 'verify.html.twig' : 'capture.html.twig');
echo $renderer->render($template, [
    'assets' => $assets->forPage(
        $template === 'capture.html.twig',
        $view['isVerification'] && $view['record'] !== null,
        $template === 'share.html.twig',
    ),
    'appUrl' => $appUrl,
    'locale' => $locale,
    'messages' => $translator->messages(),
    'captureUrl' => $router->captureUrl($locale),
    'record' => $view['record'],
    'hashValid' => $view['hashValid'],
    'mapTileUrl' => $environment['MAP_TILE_URL'] ?? 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
]);
