<?php

declare(strict_types=1);

use Neniu\Verify\Application;
use Dotenv\Dotenv;
use Neniu\Verify\ClientIpResolver;
use Neniu\Verify\Clock;
use Neniu\Verify\Router;
use Neniu\Verify\TemplateRenderer;
use Neniu\Verify\ViteAssets;
use Neniu\Verify\Translator;

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
$storage = $environment['STORAGE_PATH'] ?? 'storage';
$storage = str_starts_with($storage, '/') ? $storage : $projectRoot . '/' . $storage;
$trustedProxies = array_values(array_filter(array_map(
    'trim',
    explode(',', $environment['TRUSTED_PROXIES'] ?? ''),
)));
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
    new ClientIpResolver($trustedProxies),
    $router,
);
$view = $application->handle();
$renderer = new TemplateRenderer($projectRoot . '/templates', $translator);
$assets = new ViteAssets(__DIR__ . '/build', $basePath, $viteDevServer);
echo $renderer->render($view['isVerification'] ? 'verify.html.twig' : 'capture.html.twig', [
    'assets' => $assets->forPage(!$view['isVerification']),
    'locale' => $locale,
    'messages' => $translator->messages(),
    'captureUrl' => $router->captureUrl($locale),
    'record' => $view['record'],
    'hashValid' => $view['hashValid'],
]);
