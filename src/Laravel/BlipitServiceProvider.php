<?php

declare(strict_types=1);

namespace Blipit\Laravel;

use Blipit\Blipit;
use Blipit\Nuggets;
use Illuminate\Support\ServiceProvider;

final class BlipitServiceProvider extends ServiceProvider
{
    private ?array $job = null;

    public function boot(): void
    {
        $config = $this->app['config']->get('services.blipit');
        if (Blipit::client() === null && is_array($config) && !empty($config['key'])) {
            Blipit::init($config);
        }
        $handler = $this->app->make('Illuminate\Contracts\Debug\ExceptionHandler');
        if (method_exists($handler, 'reportable')) {
            $handler->reportable(static function (\Throwable $e): void {
                Blipit::captureUnhandled($e);
            });
        }
        $events = $this->app['events'];
        $events->listen('Illuminate\Routing\Events\RouteMatched', static function ($event): void {
            Blipit::nameRequest((string) $event->route->uri());
        });
        $events->listen('Illuminate\Database\Events\QueryExecuted', [$this, 'query']);
        $events->listen('Illuminate\Log\Events\MessageLogged', static function ($event): void {
            $client = Blipit::client();
            if ($client !== null && is_string($event->message) && strpos($event->message, '[blipit]') !== 0) {
                $client->addBreadcrumb(['category' => 'console', 'message' => $event->message, 'level' => $event->level, 'data' => ['logger' => 'laravel']]);
            }
        });
        $events->listen('Illuminate\Http\Client\Events\ResponseReceived', static function ($event): void {
            $client = Blipit::client();
            if ($client === null) {
                return;
            }
            $url = Nuggets::cleanUrl((string) $event->request->url());
            $status = (int) $event->response->status();
            $client->addBreadcrumb(['category' => 'http', 'message' => $event->request->method() . " {$url} [{$status}]", 'level' => $status >= 500 ? 'error' : 'info', 'data' => ['method' => $event->request->method(), 'url' => $url, 'status_code' => $status]]);
        });
        $events->listen('Illuminate\Queue\Events\JobProcessing', [$this, 'jobStarted']);
        $events->listen('Illuminate\Queue\Events\JobProcessed', [$this, 'jobDone']);
        $events->listen('Illuminate\Queue\Events\JobFailed', [$this, 'jobFailed']);
        $events->listen('Illuminate\Queue\Events\JobExceptionOccurred', [$this, 'jobFailed']);
        $events->listen('Laravel\Octane\Events\RequestReceived', static function (): void {
            $client = Blipit::client();
            if ($client !== null) {
                $client->scope['breadcrumbs'] = [];
                $client->scope['user'] = null;
                $client->scope['request'] = null;
            }
        });
        $events->listen('Laravel\Octane\Events\RequestTerminated', static function (): void {
            Blipit::flush();
        });
    }

    public function query($event): void
    {
        $client = Blipit::client();
        if ($client === null) {
            return;
        }
        $sql = (string) $event->sql;
        $system = method_exists($event->connection, 'getDriverName') ? (string) $event->connection->getDriverName() : 'sql';
        $client->addBreadcrumb(['category' => 'query', 'message' => Nuggets::redactSql($sql), 'data' => ['db.system' => $system, 'duration_ms' => (float) $event->time]]);
        $end = Nuggets::nowNs();
        $attrs = ['db.system' => $system, 'db.operation.name' => Nuggets::sqlOp($sql), 'db.query.text' => Nuggets::redactSql($sql)];
        if (isset($event->connectionName)) {
            $attrs['db.namespace'] = substr((string) $event->connectionName, 0, 128);
        }
        $span = $client->startSpan(Nuggets::sqlOp($sql) . " {$system}", 3, $attrs, false, $end - (int) round((float) $event->time * 1e6));
        $client->finishSpan($span, false, $end);
    }

    public function jobStarted($event): void
    {
        $client = Blipit::client();
        if ($client === null) {
            return;
        }
        $name = method_exists($event->job, 'resolveName') ? (string) $event->job->resolveName() : 'job';
        $client->scope['breadcrumbs'] = [];
        $client->scope['tags']['job'] = substr($name, 0, 256);
        $this->job = $client->startSpan($name, 5, ['messaging.system' => 'laravel', 'messaging.operation.name' => 'process', 'messaging.destination.name' => (string) $event->connectionName], true);
        if ($this->job !== null) {
            $client->stack = [$this->job];
        }
    }

    public function jobDone($event): void
    {
        $this->endJob(false);
    }

    public function jobFailed($event): void
    {
        if (isset($event->exception) && $event->exception instanceof \Throwable) {
            Blipit::captureUnhandled($event->exception);
        }
        $this->endJob(true);
    }

    private function endJob(bool $failed): void
    {
        $client = Blipit::client();
        if ($client === null) {
            return;
        }
        if ($this->job !== null) {
            $client->finishSpan($client->stack[0] ?? $this->job, $failed);
            $this->job = null;
            $client->stack = [];
        }
        unset($client->scope['tags']['job']);
        $client->flush(2.0);
    }
}
