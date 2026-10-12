<?php

declare(strict_types=1);

namespace Blipit\Tests;

use Blipit\Blipit;
use PHPUnit\Framework\TestCase;

final class BlipitTest extends TestCase
{
    public function testDsnIsBuiltFromKeyAndProject(): void
    {
        $this->assertSame('https://blipit_pk_abc@in.blipit.io/7', Blipit::dsn('blipit_pk_abc', 7));
        $this->assertSame('http://k@127.0.0.1:9000/3', Blipit::dsn('k', '3', 'http://127.0.0.1:9000/'));
    }

    public function testInitWithoutKeyWarnsAndStaysOff(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'blipit');
        $previous = ini_set('error_log', $log);
        try {
            $this->assertFalse(Blipit::init(['project' => 7]));
            $this->assertFalse(Blipit::init(['key' => null, 'project' => 7]));
            $this->assertFalse(Blipit::init(['key' => 'blipit_pk_abc', 'project' => '']));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $written = (string) file_get_contents($log);
        unlink($log);
        $this->assertSame(2, substr_count($written, 'got no key'));
        $this->assertSame(1, substr_count($written, 'got no project id'));
    }

    public function testEventsReachBlipit(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'blipit');
        $port = $this->freePort();
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/server.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['BLIPIT_TEST_LOG' => $log])
        );
        $this->assertIsResource($server);
        $this->waitForPort($port);

        try {
            Blipit::init([
                'key' => 'blipit_sk_abc',
                'project' => 7,
                'environment' => 'test',
                'endpoint' => "http://127.0.0.1:{$port}",
                'default_integrations' => false,
            ]);
            Blipit::setTag('region', 'test-1');
            Blipit::captureMessage('hello from php');
            Blipit::captureSecurity('login_failed', 'ana@example.com', 'wrong password', '42', '10.0.0.1', 'phpunit');
            Blipit::flush();
            $received = array_map(
                static function (string $line): array {
                    return json_decode($line, true);
                },
                array_filter(explode("\n", (string) file_get_contents($log)))
            );
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($log);
        }

        $this->assertCount(2, $received, 'expected two envelopes');
        $this->assertSame('/api/7/envelope/', $received[0]['path']);

        $event = json_decode(explode("\n", $received[0]['body'])[2], true);
        $this->assertSame('hello from php', $event['message']);
        $this->assertSame('test', $event['environment']);
        $this->assertSame('test-1', $event['tags']['region']);

        $security = json_decode(explode("\n", $received[1]['body'])[2], true);
        $this->assertSame('login_failed for ana@example.com', $security['message']);
        $this->assertSame('warning', $security['level']);
        $this->assertSame('login_failed', $security['tags']['security.kind']);
        $this->assertSame('10.0.0.1', $security['contexts']['security']['ip']);
        $this->assertArrayNotHasKey('target', $security['contexts']['security']);
    }

    public function testIdentityAndUserBeforeSend(): void
    {
        $result = $this->sendTwice('test', '', static function ($event) {
            $event->setTags(['mine' => 'yes']);

            return $event;
        });
        $event = json_decode(explode("\n", $result['received'][0]['body'])[2], true);
        $this->assertSame('blipit-php', $event['sdk']['name']);
        $this->assertSame(Blipit::VERSION, $event['sdk']['version']);
        $this->assertSame('yes', $event['tags']['mine']);
    }

    public function testNoticeOnceInDevelopment(): void
    {
        $result = $this->sendTwice('development', rawurlencode('{"version":"0.2.9","severity":"normal","note":"Faster."}'));
        $this->assertSame(1, substr_count($result['log'], 'is available'));
        $this->assertStringContainsString('[blipit] 0.2.9 is available (you have ' . Blipit::VERSION . ') — Faster. — composer update blipit/blipit', $result['log']);
    }

    public function testNoNoticeInProduction(): void
    {
        $result = $this->sendTwice('production', rawurlencode('{"version":"0.2.9","note":"Faster."}'));
        $this->assertStringNotContainsString('is available', $result['log']);
    }

    public function testMalformedHeaderIsSilent(): void
    {
        foreach (['', 'not%20json', rawurlencode('{"version":3}')] as $header) {
            $this->assertStringNotContainsString('is available', $this->sendTwice('development', $header)['log']);
        }
        $this->assertSame('[blipit] 0.2.9 is available (you have 0.2.1) — composer update blipit/blipit', Blipit::updateNotice(rawurlencode('{"version":"0.2.9","note":""}'), '0.2.1'));
    }

    private function sendTwice(string $environment, string $header, ?callable $beforeSend = null): array
    {
        $log = tempnam(sys_get_temp_dir(), 'blipit');
        $errors = tempnam(sys_get_temp_dir(), 'blipit');
        $port = $this->freePort();
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/server.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['BLIPIT_TEST_LOG' => $log, 'BLIPIT_TEST_LATEST' => $header])
        );
        $this->waitForPort($port);
        $previous = ini_set('error_log', $errors);
        $reset = new \ReflectionProperty(Blipit::class, 'noticed');
        $reset->setValue(null, false);
        try {
            Blipit::init(array_filter([
                'key' => 'blipit_pk_abc',
                'project' => 7,
                'environment' => $environment,
                'endpoint' => "http://127.0.0.1:{$port}",
                'default_integrations' => false,
                'before_send' => $beforeSend,
            ], static function ($value): bool {
                return $value !== null;
            }));
            Blipit::captureMessage('one');
            Blipit::flush();
            Blipit::captureMessage('two');
            Blipit::flush();
            $received = array_map(
                static function (string $line): array {
                    return json_decode($line, true);
                },
                array_values(array_filter(explode("\n", (string) file_get_contents($log))))
            );
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            proc_terminate($server);
            proc_close($server);
            @unlink($log);
        }
        $written = (string) file_get_contents($errors);
        unlink($errors);

        return ['received' => $received, 'log' => $written];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitForPort(int $port): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50000);
        }
        $this->fail("php -S did not open port {$port}");
    }
}
