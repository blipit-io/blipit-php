<?php

declare(strict_types=1);

namespace Blipit\Tests;

use Blipit\Blipit;
use Blipit\Nuggets;
use Blipit\Symfony\BlipitListener;
use PHPUnit\Framework\TestCase;

final class BlipitTest extends TestCase
{
    use Ingest;

    protected function setUp(): void
    {
        $this->startIngest();
    }

    protected function tearDown(): void
    {
        $this->stopIngest();
    }

    private function start(array $options = []): void
    {
        $this->assertTrue(Blipit::init(array_merge([
            'key' => 'blipit_sk_abc',
            'project' => 7,
            'environment' => 'test',
            'endpoint' => $this->ingestUrl,
            'default_integrations' => false,
        ], $options)));
    }

    private function boom(): void
    {
        throw new \LogicException('inner');
    }

    public function testMessageHasBlipitShape(): void
    {
        $this->start(['release' => 'shop@1.2.3', 'server_name' => 'web-1']);
        Blipit::setUser(['id' => '42', 'email' => 'ana@example.com', 'ip_address' => '10.0.0.9']);
        Blipit::setTag('region', 'ap-southeast-1');
        Blipit::addBreadcrumb('cache cleared', 'cache');
        Blipit::captureMessage('hello from php', 'warning');
        Blipit::flush();
        $received = $this->received();
        $this->assertSame('/api/v1/events', $received[0]['path']);
        $this->assertSame('blipit_sk_abc', $received[0]['key']);
        $event = $received[0]['body'];
        $this->assertSame('hello from php', $event['message']);
        $this->assertSame('warning', $event['level']);
        $this->assertSame('php', $event['platform']);
        $this->assertSame('test', $event['environment']);
        $this->assertSame('shop@1.2.3', $event['release']);
        $this->assertSame(['name' => 'blipit-php', 'version' => Blipit::VERSION], $event['sdk']);
        $this->assertSame(['id' => '42', 'email' => 'ana@example.com'], $event['user']);
        $this->assertSame(['server' => 'web-1', 'region' => 'ap-southeast-1'], $event['tags']);
        $this->assertSame('custom', $event['breadcrumbs'][0]['category']);
        $this->assertSame(['category' => 'cache'], $event['breadcrumbs'][0]['data']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $event['timestamp']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $event['event_id']);
        Blipit::setUser(null);
    }

    public function testExceptionHasFramesAndSource(): void
    {
        $this->start();
        try {
            try {
                $this->boom();
            } catch (\LogicException $inner) {
                throw new \RuntimeException('checkout failed', 0, $inner);
            }
        } catch (\RuntimeException $e) {
            $id = Blipit::captureException($e);
        }
        Blipit::flush();
        $event = $this->events()[0];
        $this->assertSame($id, $event['event_id']);
        $this->assertSame('RuntimeException', $event['exception']['type']);
        $this->assertSame('checkout failed', $event['exception']['value']);
        $this->assertTrue($event['exception']['handled']);
        $frame = end($event['exception']['stacktrace']['frames']);
        $this->assertStringContainsString("throw new \\RuntimeException('checkout failed'", $frame['context_line']);
        $this->assertSame('Blipit\Tests\BlipitTest->testExceptionHasFramesAndSource', $frame['function']);
        $this->assertSame('tests/BlipitTest.php', $frame['filename']);
        $this->assertTrue($frame['in_app']);
        $this->assertCount(5, $frame['pre_context']);
        $this->assertSame([['type' => 'LogicException', 'value' => 'inner']], $event['extra']['exception_chain']);
    }

    public function testSecurityEvent(): void
    {
        $this->start();
        Blipit::captureSecurity('login_failed', 'ana@example.com', 'wrong password', '42', '10.0.0.1', 'phpunit');
        Blipit::captureSecurity('custom_kind', 'bob');
        Blipit::flush();
        [$security, $custom] = $this->events();
        $this->assertSame(['kind' => 'login_failed', 'actor' => 'ana@example.com', 'actor_id' => '42', 'ip' => '10.0.0.1', 'user_agent' => 'phpunit', 'outcome' => 'wrong password'], $security['security']);
        $this->assertSame('login_failed for ana@example.com', $security['message']);
        $this->assertSame('warning', $security['level']);
        $this->assertSame('login_failed', $security['tags']['security.kind']);
        $this->assertArrayNotHasKey('security', $custom);
    }

    public function testPublicKeyNeverSendsSecurity(): void
    {
        $log = $this->errorLog(function (): void {
            $this->start(['key' => 'blipit_pk_abc']);
            Blipit::captureSecurity('login_failed', 'ana@example.com');
            Blipit::captureSecurity('login_failed', 'ana@example.com');
            Blipit::flush();
        });
        $this->assertSame([], $this->events());
        $this->assertSame(1, substr_count($log, '[blipit] login attempts need'));
    }

    public function testInitWithoutKeyWarnsAndStaysOff(): void
    {
        $log = $this->errorLog(function (): void {
            $this->assertFalse(Blipit::init(['project' => 7]));
            $this->assertFalse(Blipit::init(['key' => null, 'project' => 7]));
        });
        $this->assertSame(2, substr_count($log, 'got no key'));
    }

    public function testScrubbing(): void
    {
        $this->start();
        Blipit::setContext('form', ['email' => 'ana@example.com', 'password' => 'hunter2', 'nested' => ['api_key' => 'k']]);
        Blipit::captureMessage('card 4111 1111 1111 1111 Authorization: Bearer abcdefghijklmnop token=s3cr3t');
        Blipit::flush();
        $event = $this->events()[0];
        $this->assertSame(['email' => 'ana@example.com', 'password' => '[Filtered]', 'nested' => ['api_key' => '[Filtered]']], $event['extra']['form']);
        $this->assertStringNotContainsString('4111', $event['message']);
        $this->assertStringNotContainsString('abcdefghijklmnop', $event['message']);
        $this->assertStringNotContainsString('s3cr3t', $event['message']);
        $this->assertSame('q=1&access_token=[Filtered]', Nuggets::scrubQuery('q=1&access_token=abc'));
        $request = Nuggets::requestFromServer(['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'shop.test', 'REQUEST_URI' => '/a?session=x', 'HTTP_COOKIE' => 'sid=1', 'HTTP_AUTHORIZATION' => 'Bearer x', 'HTTP_USER_AGENT' => 'ua']);
        $this->assertSame(['url' => 'http://shop.test/a', 'method' => 'GET', 'query_string' => 'session=[Filtered]', 'headers' => ['user-agent' => 'ua']], $request);
        Blipit::setContext('form', null);
    }

    public function testRateLimitPausesSending(): void
    {
        $this->start();
        $this->reply(429, ['Retry-After' => '30'], ['retry_after' => 30]);
        Blipit::captureMessage('one');
        Blipit::flush();
        Blipit::captureMessage('two');
        Blipit::flush();
        $this->assertSame(['one'], array_column($this->events(), 'message'));
        $this->assertGreaterThan(microtime(true) + 25, Blipit::client()->limitedUntil);
    }

    public function testServerErrorIsRetried(): void
    {
        $this->start();
        $this->reply(503, [], []);
        Blipit::captureMessage('again');
        Blipit::flush(5);
        $events = $this->events();
        $this->assertCount(2, $events);
        $this->assertSame($events[0]['event_id'], $events[1]['event_id']);
    }

    public function testQueueIsBounded(): void
    {
        $client = new Nuggets('blipit_pk_abc', 'http://127.0.0.1:9', ['batch_size' => 1000]);
        for ($i = 0; $i < 150; $i++) {
            $client->capture(['message' => (string) $i]);
        }
        $this->assertCount(100, $client->queue);
        $this->assertSame('50', $client->queue[0]['message']);
    }

    public function testCliSendsInBatches(): void
    {
        $this->start(['batch_size' => 3]);
        Blipit::captureMessage('a');
        Blipit::captureMessage('b');
        $this->assertSame([], $this->events());
        Blipit::captureMessage('c');
        $this->assertSame(['a', 'b', 'c'], array_column($this->events(), 'message'));
    }

    public function testBeforeSendCanChangeOrDrop(): void
    {
        $this->start(['before_send' => static function (array $event): ?array {
            if ($event['message'] === 'drop') {
                return null;
            }
            $event['tags'] = ['mine' => 'yes'];

            return $event;
        }]);
        Blipit::captureMessage('drop');
        Blipit::captureMessage('keep');
        Blipit::flush();
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame(['mine' => 'yes'], $events[0]['tags']);
    }

    public function testNoticeOnceInDevelopment(): void
    {
        $latest = ['version' => '0.3.9', 'severity' => 'normal', 'note' => 'Faster.'];
        $this->reply(202, [], ['id' => 'a', 'latest' => $latest]);
        $this->reply(202, [], ['id' => 'b', 'latest' => $latest]);
        $log = $this->errorLog(function (): void {
            $this->start(['environment' => 'development']);
            Blipit::captureMessage('one');
            Blipit::captureMessage('two');
            Blipit::flush();
        });
        $this->assertSame(1, substr_count($log, 'is available'));
        $this->assertStringContainsString('[blipit] 0.3.9 is available (you have ' . Blipit::VERSION . ') — Faster. — composer update blipit/blipit', $log);
    }

    public function testNoNoticeInProduction(): void
    {
        $this->reply(202, [], ['id' => 'a', 'latest' => ['version' => '0.3.9']]);
        $log = $this->errorLog(function (): void {
            $this->start(['environment' => 'production']);
            Blipit::captureMessage('one');
            Blipit::flush();
        });
        $this->assertStringNotContainsString('is available', $log);
    }

    public function testUpdateNoticeHelper(): void
    {
        $this->assertNull(Blipit::updateNotice('not%20json'));
        $this->assertNull(Blipit::updateNotice(rawurlencode('{"version":3}')));
        $this->assertSame('[blipit] 0.2.9 is available (you have 0.2.1) — composer update blipit/blipit', Blipit::updateNotice(rawurlencode('{"version":"0.2.9","note":""}'), '0.2.1'));
    }

    public function testTraceHelperNestsSpans(): void
    {
        $this->start(['traces_sample_rate' => 1.0]);
        $client = Blipit::client();
        $root = $client->startSpan('nightly import', 5, [], true);
        $client->stack[] = $root;
        $result = Blipit::trace('SELECT pgsql', static function (): int {
            return 7;
        }, ['db.system' => 'postgresql']);
        Blipit::captureMessage('inside');
        $client->finishSpan(array_pop($client->stack));
        Blipit::flush();
        $this->assertSame(7, $result);
        $spans = $this->spans();
        $this->assertCount(2, $spans);
        $this->assertSame($spans[1]['spanId'], $spans[0]['parentSpanId']);
        $this->assertSame($spans[1]['traceId'], $this->events()[0]['tags']['trace_id']);
    }

    public function testUncaughtExceptionWarningAndFatalInCli(): void
    {
        $script = sys_get_temp_dir() . '/blipit-cli-' . getmypid() . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
            Blipit\Blipit::init(["key" => "blipit_pk_abc", "endpoint" => ' . var_export($this->ingestUrl, true) . ']);
            $x = [];
            echo $x["missing"];
            if (($argv[1] ?? "") === "fatal") { eval("function twice() {} function twice() {}"); }
            throw new DomainException("crash on boot");');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $this->assertSame(255, $code);
        $events = $this->events();
        $this->assertSame(['Warning', 'DomainException'], array_map(static function (array $e): string {
            return $e['exception']['type'];
        }, $events));
        $this->assertSame('Undefined array key "missing"', $events[0]['exception']['value']);
        $this->assertSame('warning', $events[0]['level']);
        $this->assertFalse($events[1]['exception']['handled']);
        $this->assertSame('fatal', $events[1]['level']);
        $this->clearReceived();
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg($script) . ' fatal 2>&1', $out, $code);
        unlink($script);
        $types = array_map(static function (array $e): string {
            return $e['exception']['type'];
        }, $this->events());
        $this->assertSame(['Warning', 'Compile Error'], $types);
    }

    public function testWebRequestIsSentAfterTheResponseWithItsSpan(): void
    {
        $port = $this->freePort();
        $app = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', "127.0.0.1:{$port}", __DIR__ . '/app.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['BLIPIT_TEST_INGEST' => $this->ingestUrl])
        );
        $this->waitForPort($port);
        try {
            $context = stream_context_create(['http' => ['ignore_errors' => true, 'header' => "User-Agent: phpunit\r\nCookie: sid=1"]]);
            @file_get_contents("http://127.0.0.1:{$port}/orders/9?token=abc", false, $context);
            $deadline = microtime(true) + 5;
            while (count($this->spans()) === 0 && microtime(true) < $deadline) {
                usleep(50000);
            }
        } finally {
            proc_terminate($app);
            proc_close($app);
        }
        $event = $this->events()[0];
        $this->assertSame('RuntimeException', $event['exception']['type']);
        $this->assertSame(['url' => "http://127.0.0.1:{$port}/orders/9", 'method' => 'GET', 'query_string' => 'token=[Filtered]', 'headers' => ['user-agent' => 'phpunit']], $event['request']);
        $this->assertSame('navigation', $event['breadcrumbs'][0]['category']);
        $span = $this->spans()[0];
        $this->assertSame('GET /orders/9', $span['name']);
        $this->assertSame(2, $span['kind']);
        $this->assertSame($span['traceId'], $event['tags']['trace_id']);
    }

    public function testSymfonyListener(): void
    {
        $this->start(['traces_sample_rate' => 1.0]);
        $listener = new BlipitListener();
        $make = static function (\Throwable $error) {
            return new class($error) {
                private \Throwable $error;

                public function __construct(\Throwable $error)
                {
                    $this->error = $error;
                }

                public function getThrowable(): \Throwable
                {
                    return $this->error;
                }
            };
        };
        $notFound = new class('missing') extends \RuntimeException {
            public function getStatusCode(): int
            {
                return 404;
            }
        };
        $listener->onKernelException($make($notFound));
        $listener->onKernelException($make(new \UnexpectedValueException('controller broke')));
        $listener->onKernelTerminate();
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('controller broke', $events[0]['exception']['value']);
        $this->assertFalse($events[0]['exception']['handled']);
    }

    public function testNoCompetitorCodeLeft(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge(glob($root . '/src/*.php'), glob($root . '/src/*/*.php'), [$root . '/composer.json']);
        foreach ($files as $file) {
            $this->assertStringNotContainsStringIgnoringCase('sentry', (string) file_get_contents($file), $file);
        }
    }
}
