<?php

declare(strict_types=1);

namespace Blipit\Tests;

trait Ingest
{
    private string $ingestUrl = '';
    private string $ingestLog = '';
    private $ingest;

    private function startIngest(): void
    {
        $this->ingestLog = (string) tempnam(sys_get_temp_dir(), 'blipit');
        $port = $this->freePort();
        $this->ingest = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/server.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['BLIPIT_TEST_LOG' => $this->ingestLog])
        );
        $this->waitForPort($port);
        $this->ingestUrl = "http://127.0.0.1:{$port}";
    }

    private function stopIngest(): void
    {
        proc_terminate($this->ingest);
        proc_close($this->ingest);
        @unlink($this->ingestLog);
        @unlink($this->ingestLog . '.replies');
    }

    private function reply(int $status, array $headers, array $body): void
    {
        $file = $this->ingestLog . '.replies';
        $queue = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $queue[] = [$status, $headers, $body];
        file_put_contents($file, json_encode($queue));
    }

    private function received(): array
    {
        return array_map(static function (string $line): array {
            return json_decode($line, true);
        }, array_values(array_filter(explode("\n", (string) file_get_contents($this->ingestLog)))));
    }

    private function clearReceived(): void
    {
        file_put_contents($this->ingestLog, '');
    }

    private function events(): array
    {
        return array_values(array_map(static function (array $r): array {
            return $r['body'];
        }, array_filter($this->received(), static function (array $r): bool {
            return $r['path'] === '/api/v1/events';
        })));
    }

    private function spans(): array
    {
        $out = [];
        foreach ($this->received() as $r) {
            if ($r['path'] !== '/v1/traces') {
                continue;
            }
            foreach ($r['body']['resourceSpans'] as $rs) {
                foreach ($rs['scopeSpans'] as $ss) {
                    $out = array_merge($out, $ss['spans']);
                }
            }
        }

        return $out;
    }

    private function errorLog(callable $run): string
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'blipit');
        $previous = ini_set('error_log', $log);
        try {
            $run();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $written = (string) file_get_contents($log);
        unlink($log);

        return $written;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
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
