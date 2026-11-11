<?php
declare(strict_types=1);

use Neniu\Verify\Application;
use Neniu\Verify\ClientIpResolver;
use Neniu\Verify\Clock;
use Neniu\Verify\Router;
use Neniu\Verify\TemplateRenderer;
use Neniu\Verify\ViteAssets;

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/i18n.php';
header('Content-Language: ' . $locale);

$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/.');
$router = new Router($basePath);
$application = new Application(
    $projectRoot . '/storage',
    $locale,
    new Clock(),
    new ClientIpResolver(array_values(array_filter(array_map(
        'trim', explode(',', getenv('TRUSTED_PROXIES') ?: '')
    )))),
    $router,
);
$view = $application->handle();
$renderer = new TemplateRenderer($projectRoot . '/templates');
$assets = new ViteAssets(__DIR__ . '/build', $basePath, getenv('VITE_DEV_SERVER') ?: null);
echo $renderer->render($view['isVerification'] ? 'verify.html.twig' : 'capture.html.twig', [
    'assets' => $assets->forPage(!$view['isVerification']),
    'locale' => $locale,
    'messages' => $messages,
    'captureUrl' => $router->captureUrl($locale),
    'record' => $view['record'],
    'hashValid' => $view['hashValid'],
]);
