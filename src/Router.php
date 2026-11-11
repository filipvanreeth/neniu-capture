<?php

declare(strict_types=1);

namespace Neniu\Capture;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

final class Router
{
    private Dispatcher $dispatcher;

    public function __construct(private string $basePath = '')
    {
        $this->dispatcher = simpleDispatcher(function (RouteCollector $routes): void {
            $routes->addRoute('GET', '/', 'capture');
            $routes->addRoute('GET', '/index.php', 'capture');
            $routes->addRoute('POST', '/', 'upload');
            $routes->addRoute('POST', '/index.php', 'upload');
            $routes->addRoute('GET', '/verify/{code:[a-f0-9]{32}}', 'verify');
        });
    }

    public function dispatch(string $method, string $uri): array
    {
        $path = rawurldecode(explode('?', $uri, 2)[0]);
        if ($this->basePath !== '') {
            if (!str_starts_with($path, $this->basePath . '/')) {
                return [Dispatcher::NOT_FOUND];
            }
            $path = substr($path, strlen($this->basePath));
        }
        return $this->dispatcher->dispatch($method, $path);
    }

    public function captureUrl(string $locale): string
    {
        return $this->basePath . '/?lang=' . rawurlencode($locale);
    }

    public function verificationUrl(string $code, string $locale): string
    {
        return $this->basePath . '/verify/' . rawurlencode($code) . '?lang=' . rawurlencode($locale);
    }
}
