<?php

declare(strict_types=1);

namespace Blipit;

final class Blipit
{
    public const VERSION = '0.3.0';
    public const SDK_IDENTIFIER = Nuggets::SDK_NAME;
    public const UPDATE_COMMAND = 'composer update blipit/blipit';
    public const DEFAULT_ENDPOINT = 'https://in.blipit.io';
    public const PUBLIC_KEY_WARNING = "[blipit] login attempts need the project's secret key (blipit_sk_...). "
        . 'This SDK was started with the public key, so captureSecurity sends nothing. Use the secret key on the server.';

    public const MISSING_KEY_WARNING = "[blipit] Blipit::init got no key, so Blipit is off and nothing is sent. "
        . "Pass the project's key (app.blipit.io, Keys). In Laravel read it through config/services.php, not env() in app code, "
        . 'because env() returns null once config is cached (php artisan config:cache).';

    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
    private const ERROR_NAMES = [
        E_WARNING => 'Warning', E_NOTICE => 'Notice', E_USER_ERROR => 'User Error', E_USER_WARNING => 'User Warning', E_USER_NOTICE => 'User Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error', E_DEPRECATED => 'Deprecated', E_USER_DEPRECATED => 'User Deprecated',
        E_ERROR => 'Fatal Error', E_PARSE => 'Parse Error', E_CORE_ERROR => 'Core Error', E_COMPILE_ERROR => 'Compile Error',
    ];

    private static ?Nuggets $client = null;
    private static bool $publicKey = false;
    private static bool $publicKeyWarned = false;
    private static bool $hooked = false;
    private static ?array $requestSpan = null;
    private static array $seen = [];
    private static bool $uncaught = false;

    public static function client(): ?Nuggets
    {
        return self::$client;
    }

    public static function init(array $options): bool
    {
        $key = trim((string) ($options['key'] ?? ''));
        if ($key === '') {
            error_log(self::MISSING_KEY_WARNING);

            return false;
        }
        if (self::$client !== null) {
            self::$client->flush(0.5);
        }
        self::$publicKey = strpos($key, 'blipit_pk_') === 0;
        self::$publicKeyWarned = false;
        $endpoint = (string) ($options['endpoint'] ?? self::DEFAULT_ENDPOINT);
        unset($options['key'], $options['project'], $options['endpoint']);
        self::$client = new Nuggets($key, $endpoint, $options);
        self::$seen = [];
        if (($options['default_integrations'] ?? true) !== false) {
            self::hook();
            if (PHP_SAPI !== 'cli' && self::$client->tracing() && isset($_SERVER['REQUEST_METHOD'])) {
                $method = (string) $_SERVER['REQUEST_METHOD'];
                $path = substr(explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0], 0, 512);
                $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (int) round((float) $_SERVER['REQUEST_TIME_FLOAT'] * 1e6) * 1000 : null;
                self::$requestSpan = self::$client->startSpan("{$method} {$path}", 2, ['http.request.method' => $method, 'url.path' => $path], true, $start);
                if (self::$requestSpan !== null) {
                    self::$client->stack[] = self::$requestSpan;
                }
            }
        }

        return true;
    }

    private static function hook(): void
    {
        if (self::$hooked) {
            return;
        }
        self::$hooked = true;
        $previousException = set_exception_handler(null);
        set_exception_handler(static function (\Throwable $e) use ($previousException): void {
            self::$uncaught = true;
            self::captureUnhandled($e, 'fatal');
            self::flush();
            if ($previousException !== null) {
                $previousException($e);

                return;
            }
            throw $e;
        });
        $previousError = null;
        $previousError = set_error_handler(static function (int $type, string $message, string $file = '', int $line = 0) use (&$previousError): bool {
            $client = self::$client;
            $mask = $client === null ? 0 : (int) ($client->options['error_types'] ?? (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED));
            if ($client !== null && ($type & $mask) && (error_reporting() & $type)) {
                $id = "{$type}:{$file}:{$line}:{$message}";
                if (!isset(self::$seen[$id]) && count(self::$seen) < 100) {
                    self::$seen[$id] = true;
                    $error = new \ErrorException($message, 0, $type, $file, $line);
                    $event = $client->exceptionEvent($error, true, in_array($type, [E_USER_ERROR, E_RECOVERABLE_ERROR], true) ? 'error' : (in_array($type, [E_WARNING, E_USER_WARNING], true) ? 'warning' : 'info'));
                    if ($event !== null) {
                        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                        array_shift($trace);
                        $event['exception']['type'] = self::ERROR_NAMES[$type] ?? 'Error';
                        $event['exception']['stacktrace'] = ['frames' => $client->frames($file, $line, $trace)];
                        $client->capture($event);
                    }
                }
            }

            return $previousError !== null ? (bool) $previousError($type, $message, $file, $line) : false;
        });
        register_shutdown_function([self::class, 'shutdown']);
    }

    public static function shutdown(): void
    {
        $client = self::$client;
        if ($client === null) {
            return;
        }
        $last = error_get_last();
        if ($last !== null && ($last['type'] & self::FATAL) && !(self::$uncaught && strpos((string) $last['message'], 'Uncaught ') === 0)) {
            $error = new \ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']);
            $event = $client->exceptionEvent($error, false, 'fatal');
            if ($event !== null) {
                $event['exception']['type'] = self::ERROR_NAMES[$last['type']] ?? 'Fatal Error';
                $event['exception']['stacktrace'] = ['frames' => $client->frames($last['file'], (int) $last['line'], [])];
                $client->capture($event);
            }
        }
        if (self::$requestSpan !== null) {
            $status = function_exists('http_response_code') ? (int) http_response_code() : 0;
            $span = $client->stack[0] ?? self::$requestSpan;
            if ($span['sampled']) {
                $span['attrs']['http.response.status_code'] = $status;
            }
            $client->finishSpan($span, $status >= 500);
            self::$requestSpan = null;
            $client->stack = [];
        }
        register_shutdown_function([self::class, 'sendAtEnd']);
    }

    public static function sendAtEnd(): void
    {
        $client = self::$client;
        if ($client === null || (!$client->queue && !$client->spans)) {
            return;
        }
        if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request') && ($client->options['finish_request'] ?? true) !== false) {
            fastcgi_finish_request();
        }
        $client->flush((float) ($client->options['shutdown_timeout'] ?? 2.0));
    }

    public static function nameRequest(string $route): void
    {
        $client = self::$client;
        if ($client === null || !$client->stack || !$client->stack[0]['sampled'] || $client->stack[0]['kind'] !== 2) {
            return;
        }
        $route = substr('/' . ltrim($route, '/'), 0, 512);
        $client->stack[0]['name'] = ($client->stack[0]['attrs']['http.request.method'] ?? 'GET') . ' ' . $route;
        $client->stack[0]['attrs']['http.route'] = $route;
    }

    public static function captureUnhandled(\Throwable $e, string $level = 'error'): ?string
    {
        return self::captureThrowable($e, false, $level);
    }

    private static function captureThrowable(\Throwable $e, bool $handled, string $level = 'error'): ?string
    {
        $client = self::$client;
        if ($client === null) {
            return null;
        }
        $id = spl_object_hash($e);
        if (isset(self::$seen[$id])) {
            return null;
        }
        self::$seen[$id] = true;

        return $client->capture($client->exceptionEvent($e, $handled, $level), ['exception' => $e]);
    }

    public static function noticeLine($latest, string $current = self::VERSION): ?string
    {
        if (!is_array($latest) || !is_string($latest['version'] ?? null) || $latest['version'] === '' || $latest['version'] === $current) {
            return null;
        }
        $note = is_string($latest['note'] ?? null) ? trim($latest['note']) : '';
        $why = $note === '' ? '' : " — {$note}";

        return "[blipit] {$latest['version']} is available (you have {$current}){$why} — " . self::UPDATE_COMMAND;
    }

    public static function updateNotice(string $header, string $current = self::VERSION): ?string
    {
        return self::noticeLine(json_decode(rawurldecode($header), true), $current);
    }

    public static function captureException(\Throwable $exception): ?string
    {
        return self::captureThrowable($exception, true);
    }

    public static function captureMessage(string $message, string $level = 'info'): ?string
    {
        $client = self::$client;

        return $client === null ? null : $client->capture(['message' => $message, 'level' => Nuggets::LEVELS[strtolower($level)] ?? 'info']);
    }

    public static function setUser(?array $user): void
    {
        if (self::$client !== null) {
            self::$client->scope['user'] = $user ?: null;
        }
    }

    public static function setTag(string $key, string $value): void
    {
        if (self::$client !== null) {
            self::$client->scope['tags'][substr($key, 0, 64)] = substr($value, 0, 256);
        }
    }

    public static function setContext(string $name, ?array $value): void
    {
        if (self::$client === null) {
            return;
        }
        if ($value === null) {
            unset(self::$client->scope['extra'][$name]);
        } else {
            self::$client->scope['extra'][$name] = $value;
        }
    }

    public static function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
        if (self::$client !== null) {
            self::$client->addBreadcrumb(['message' => $message, 'category' => $category, 'level' => $level, 'data' => $data]);
        }
    }

    public static function captureSecurity(
        string $kind,
        string $actor,
        ?string $outcome = null,
        ?string $actorId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $target = null,
        ?string $database = null
    ): ?string {
        $client = self::$client;
        if ($client === null) {
            return null;
        }
        if (self::$publicKey) {
            if (!self::$publicKeyWarned) {
                self::$publicKeyWarned = true;
                error_log(self::PUBLIC_KEY_WARNING);
            }

            return null;
        }
        if ($kind === '' || trim($actor) === '') {
            error_log('[blipit] captureSecurity needs a kind and an actor, so this login attempt was not sent.');

            return null;
        }

        return $client->capture(Nuggets::securityEvent($kind, $actor, [
            'outcome' => $outcome,
            'actor_id' => $actorId,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'target' => $target,
            'database' => $database,
        ]));
    }

    public static function trace(string $name, callable $run, array $attributes = [], int $kind = 3)
    {
        $client = self::$client;
        $span = $client !== null ? $client->startSpan($name, $kind, $attributes) : null;
        if ($span === null) {
            return $run();
        }
        $client->stack[] = $span;
        try {
            $result = $run();
            $client->finishSpan(array_pop($client->stack));

            return $result;
        } catch (\Throwable $e) {
            $client->finishSpan(array_pop($client->stack), true);
            throw $e;
        }
    }

    public static function flush(int $timeout = 2): void
    {
        if (self::$client !== null) {
            self::$client->flush((float) $timeout);
        }
    }
}
