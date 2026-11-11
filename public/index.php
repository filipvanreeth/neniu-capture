<?php

declare(strict_types=1);

use Neniu\Verify\Application;
use Neniu\Verify\ClientIpResolver;
use Neniu\Verify\Clock;
use Neniu\Verify\Router;
use Neniu\Verify\TemplateRenderer;
use Neniu\Verify\ViteAssets;
use Neniu\Verify\Translator;

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/vendor/autoload.php';
$translator = new Translator($projectRoot . '/translations', $_GET['lang'] ?? 'en');
$locale = $translator->locale();
header('Content-Language: ' . $locale);

$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/.');
$router = new Router($basePath);
$application = new Application(
    $projectRoot . '/storage',
    $translator,
    new Clock(),
    new ClientIpResolver(array_values(array_filter(array_map(
        'trim',
        explode(',', getenv('TRUSTED_PROXIES') ?: '')
    )))),
    $router,
);
$view = $application->handle();
$renderer = new TemplateRenderer($projectRoot . '/templates', $translator);
$assets = new ViteAssets(__DIR__ . '/build', $basePath, getenv('VITE_DEV_SERVER') ?: null);
echo $renderer->render($view['isVerification'] ? 'verify.html.twig' : 'capture.html.twig', [
    'assets' => $assets->forPage(!$view['isVerification']),
    'locale' => $locale,
    'messages' => $translator->messages(),
    'captureUrl' => $router->captureUrl($locale),
    'record' => $view['record'],
    'hashValid' => $view['hashValid'],
]);
