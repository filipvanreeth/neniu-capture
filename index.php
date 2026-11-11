<?php
declare(strict_types=1);

use Neniu\Verify\Application;
use Neniu\Verify\ClientIpResolver;
use Neniu\Verify\Clock;
use Neniu\Verify\Router;
use Neniu\Verify\TemplateRenderer;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/i18n.php';
header('Content-Language: ' . $locale);

$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/.');
$router = new Router($basePath);
$application = new Application(
    __DIR__ . '/storage',
    $locale,
    new Clock(),
    new ClientIpResolver(array_values(array_filter(array_map(
        'trim', explode(',', getenv('TRUSTED_PROXIES') ?: '')
    )))),
    $router,
);
$view = $application->handle();
$renderer = new TemplateRenderer(__DIR__ . '/templates');
echo $renderer->render($view['isVerification'] ? 'verify.html.twig' : 'capture.html.twig', [
    'locale' => $locale,
    'messages' => $messages,
    'captureUrl' => $router->captureUrl($locale),
    'record' => $view['record'],
    'hashValid' => $view['hashValid'],
]);
