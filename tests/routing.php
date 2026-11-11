<?php

declare(strict_types=1);

use FastRoute\Dispatcher;
use Neniu\Verify\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

$code = str_repeat('a', 32);
$router = new Router();
$cases = [
    ['GET', '/', Dispatcher::FOUND, 'capture'],
    ['GET', '/index.php?lang=en', Dispatcher::FOUND, 'capture'],
    ['POST', '/', Dispatcher::FOUND, 'upload'],
    ['POST', '/index.php', Dispatcher::FOUND, 'upload'],
    ['GET', '/verify/' . $code . '?lang=en', Dispatcher::FOUND, 'verify'],
    ['HEAD', '/verify/' . $code, Dispatcher::FOUND, 'verify'],
    ['POST', '/verify/' . $code, Dispatcher::METHOD_NOT_ALLOWED, null],
    ['DELETE', '/', Dispatcher::METHOD_NOT_ALLOWED, null],
    ['GET', '/verify/invalid', Dispatcher::NOT_FOUND, null],
    ['GET', '/missing', Dispatcher::NOT_FOUND, null],
];
foreach ($cases as [$method, $uri, $status, $handler]) {
    $result = $router->dispatch($method, $uri);
    if ($result[0] !== $status || ($handler !== null && $result[1] !== $handler)) {
        throw new RuntimeException("Unexpected routing for $method $uri");
    }
}
$result = $router->dispatch('GET', '/verify/' . $code);
if ($result[2]['code'] !== $code) {
    throw new RuntimeException('Verification code was not extracted');
}
$router = new Router('/app');
if (
    $router->dispatch('GET', '/app/verify/' . $code)[0] !== Dispatcher::FOUND
    || $router->dispatch('GET', '/other/verify/' . $code)[0] !== Dispatcher::NOT_FOUND
    || $router->verificationUrl($code, 'en') !== '/app/verify/' . $code . '?lang=en'
    || $router->captureUrl('en') !== '/app/?lang=en'
) {
    throw new RuntimeException('Subdirectory routes and links');
}
echo "Routing tests passed\n";
