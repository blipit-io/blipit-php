<?php

declare(strict_types=1);

namespace Blipit\Tests;

use Blipit\Blipit;
use Blipit\Laravel\BlipitServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

if (!class_exists('Orchestra\Testbench\TestCase')) {
    return;
}

final class SendInvoice implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        DB::select('select 1 where 2 = 2');
        throw new \RuntimeException('smtp down');
    }
}

final class LaravelTest extends \Orchestra\Testbench\TestCase
{
    use Ingest;

    protected function setUp(): void
    {
        $this->startIngest();
        (new \ReflectionProperty(Blipit::class, 'client'))->setValue(null, null);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->stopIngest();
    }

    protected function getPackageProviders($app): array
    {
        return [BlipitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('services.blipit', [
            'key' => 'blipit_sk_abc',
            'environment' => 'testing',
            'endpoint' => $this->ingestUrl,
            'traces_sample_rate' => 1.0,
            'default_integrations' => false,
        ]);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineRoutes($router): void
    {
        Route::get('/orders/{id}', static function (string $id) {
            Log::info('loading order');
            DB::select("select * from sqlite_master where name = 'orders'");
            throw new \LogicException("order {$id} broke");
        });
        Route::get('/missing', static function () {
            abort(404);
        });
    }

    public function testControllerErrorIsReportedWithBreadcrumbs(): void
    {
        $this->get('/orders/9')->assertStatus(500);
        Blipit::flush();
        $events = $this->events();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('LogicException', $event['exception']['type']);
        $this->assertFalse($event['exception']['handled']);
        $this->assertSame('testing', $event['environment']);
        $categories = array_column($event['breadcrumbs'], 'category');
        $this->assertContains('console', $categories);
        $this->assertContains('query', $categories);
        $query = $event['breadcrumbs'][array_search('query', $categories, true)];
        $this->assertSame('select * from sqlite_master where name = ?', $query['message']);
    }

    public function testNotFoundIsNotReported(): void
    {
        $this->get('/missing')->assertStatus(404);
        Blipit::flush();
        $this->assertSame([], $this->events());
    }

    public function testQueuedJobFailureIsReportedWithSpans(): void
    {
        try {
            SendInvoice::dispatch();
        } catch (\RuntimeException $e) {
            $this->assertSame('smtp down', $e->getMessage());
        }
        Blipit::flush();
        $event = $this->events()[0];
        $this->assertSame('smtp down', $event['exception']['value']);
        $this->assertSame(SendInvoice::class, $event['tags']['job']);
        $spans = $this->spans();
        $job = array_values(array_filter($spans, static function (array $s): bool {
            return $s['kind'] === 5;
        }));
        $db = array_values(array_filter($spans, static function (array $s): bool {
            return $s['kind'] === 3;
        }));
        $this->assertCount(1, $job);
        $this->assertSame(SendInvoice::class, $job[0]['name']);
        $this->assertSame(2, $job[0]['status']['code']);
        $this->assertSame($job[0]['spanId'], $db[0]['parentSpanId']);
    }
}
