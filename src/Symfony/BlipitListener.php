<?php

declare(strict_types=1);

namespace Blipit\Symfony;

use Blipit\Blipit;

final class BlipitListener
{
    private $router;

    public function __construct($router = null)
    {
        $this->router = $router;
    }

    public function onKernelRequest($event): void
    {
        $main = method_exists($event, 'isMainRequest') ? $event->isMainRequest() : (method_exists($event, 'isMasterRequest') ? $event->isMasterRequest() : true);
        if (!$main) {
            return;
        }
        $name = $event->getRequest()->attributes->get('_route');
        $route = is_string($name) && $this->router !== null && method_exists($this->router, 'getRouteCollection') ? $this->router->getRouteCollection()->get($name) : null;
        if ($route !== null) {
            Blipit::nameRequest((string) $route->getPath());
        }
    }

    public function onKernelException($event): void
    {
        $error = method_exists($event, 'getThrowable') ? $event->getThrowable() : $event->getException();
        if (!$error instanceof \Throwable) {
            return;
        }
        if (method_exists($error, 'getStatusCode') && (int) $error->getStatusCode() < 500) {
            return;
        }
        Blipit::captureUnhandled($error);
    }

    public function onConsoleError($event): void
    {
        $error = $event->getError();
        if ($error instanceof \Throwable) {
            Blipit::captureUnhandled($error);
        }
    }

    public function onKernelTerminate($event = null): void
    {
        Blipit::flush();
    }
}
