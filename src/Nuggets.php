<?php

declare(strict_types=1);

namespace Blipit;

final class Nuggets
{
    public const SDK_NAME = 'blipit-php';
    public const FILTERED = '[Filtered]';
    public const LEVELS = ['fatal' => 'fatal', 'critical' => 'fatal', 'error' => 'error', 'warning' => 'warning', 'warn' => 'warning', 'info' => 'info', 'log' => 'info', 'debug' => 'debug'];
    public const CATEGORIES = ['ui.click', 'ui.input', 'navigation', 'http', 'console', 'query', 'custom'];
    public const SECURITY_KINDS = ['login_failed', 'login_succeeded', 'login_blocked', 'password_reset_requested', 'privilege_granted'];
    public const SECURITY_FIELDS = ['actor_id' => 128, 'ip' => 45, 'user_agent' => 512, 'database' => 64, 'outcome' => 64, 'target' => 256];
    public const HEADER_ALLOW = ['user-agent' => 'HTTP_USER_AGENT', 'referer' => 'HTTP_REFERER', 'content-type' => 'CONTENT_TYPE', 'accept-language' => 'HTTP_ACCEPT_LANGUAGE'];
    public const MAX_EVENTS = 100;
    public const MAX_SPANS = 10000;
    public const SEND_AT = 10;
    private const SENSITIVE = '/pass(?:word|wd)?|pwd|secret|token|api[_-]?key|auth(?:orization)?|cookie|session|credit|card|cvv|ssn|csrf|private[_-]?key/i';
    private const SENSITIVE_PAIR = '/(?<![\w.-])([\w.-]+)((?:\s*=\s*|["\']\s*:\s*)["\']?)(?!\[Filtered\])([^&\s"\',;}]+)/';
    private const BEARER = '/\b(Bearer|Basic|Token)\s+[\w.~+\/=-]{8,}/';
    private const CARD_LIKE = '/\b\d(?:[ -]?\d){12,18}\b/';
    private const KEEP = ['event_id', 'trace_id', 'span_id', 'parent_span_id'];

    public string $key;
    public string $base;
    public string $ownHost;
    public array $options;
    public string $environment;
    public float $limitedUntil = 0.0;
    public bool $noticed = false;
    public array $scope = ['user' => null, 'tags' => [], 'extra' => [], 'breadcrumbs' => [], 'request' => null, 'fingerprint' => null];
    public array $queue = [];
    public array $spans = [];
    public array $stack = [];
    private array $sources = [];
    private static array $refusedKeys = [];
    private float $tracesRate;
    private float $sampleRate;
    private int $maxBreadcrumbs;
    private ?string $root;

    public function __construct(string $key, string $endpoint, array $options)
    {
        $this->key = $key;
        $this->base = rtrim($endpoint, '/');
        $parts = parse_url($this->base) ?: [];
        $host = strtolower((string) ($parts['host'] ?? ''));
        $this->ownHost = isset($parts['port']) ? "{$host}:{$parts['port']}" : $host;
        $this->options = $options;
        $this->environment = (string) ($options['environment'] ?? 'production');
        $this->tracesRate = max(0.0, min(1.0, (float) ($options['traces_sample_rate'] ?? 0.0)));
        $this->sampleRate = (float) ($options['sample_rate'] ?? 1.0);
        $this->maxBreadcrumbs = min((int) ($options['max_breadcrumbs'] ?? 100), 100);
        $this->root = isset($options['project_root']) ? rtrim((string) $options['project_root'], '/') : self::composerRoot();
    }

    public static function composerRoot(): ?string
    {
        if (!class_exists('Composer\Autoload\ClassLoader', false)) {
            return null;
        }
        $file = (string) (new \ReflectionClass('Composer\Autoload\ClassLoader'))->getFileName();

        return dirname($file, 3);
    }

    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $d = ord($digits[$length - 1 - $i]) - 48;
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }

    public static function scrubText(string $text): string
    {
        $out = (string) preg_replace(self::BEARER, '$1 ' . self::FILTERED, $text);
        if (preg_match(self::SENSITIVE, $out)) {
            $out = (string) preg_replace_callback(self::SENSITIVE_PAIR, static function (array $m): string {
                return preg_match(self::SENSITIVE, $m[1]) ? $m[1] . $m[2] . self::FILTERED : $m[0];
            }, $out);
        }

        return (string) preg_replace_callback(self::CARD_LIKE, static function (array $m): string {
            $digits = str_replace([' ', '-'], '', $m[0]);

            return strlen($digits) >= 13 && strlen($digits) <= 19 && self::luhn($digits) ? self::FILTERED : $m[0];
        }, $out);
    }

    public static function scrub($value)
    {
        if (is_string($value)) {
            return self::scrubText($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && in_array($k, self::KEEP, true)) {
                $out[$k] = $v;
            } elseif (is_string($k) && preg_match(self::SENSITIVE, $k) && $v !== null && $v !== '') {
                $out[$k] = self::FILTERED;
            } else {
                $out[$k] = self::scrub($v);
            }
        }

        return $out;
    }

    public static function text($value, int $size): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = is_scalar($value) || (is_object($value) && method_exists($value, '__toString')) ? (string) $value : (string) json_encode($value);
        if ($text === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            return mb_substr(mb_convert_encoding($text, 'UTF-8', 'UTF-8'), 0, $size, 'UTF-8');
        }

        return substr($text, 0, $size);
    }

    public static function redactSql(string $sql): string
    {
        $out = (string) preg_replace("/'(?:[^']|'')*'/", '?', $sql);
        $out = (string) preg_replace('/(?<![\w$.])-?\d+(?:\.\d+)?(?![\w.])/', '?', $out);
        $out = (string) preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?)', $out);

        return substr(trim((string) preg_replace('/\s+/', ' ', $out)), 0, 2000);
    }

    public static function sqlOp(string $sql): string
    {
        return preg_match('/^\s*(\w+)/', $sql, $m) ? strtoupper($m[1]) : 'QUERY';
    }

    public static function cleanUrl(string $raw): string
    {
        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['host'])) {
            return substr(explode('?', $raw, 2)[0], 0, 2048);
        }
        $url = ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');

        return substr($url, 0, 2048);
    }

    public static function scrubQuery(string $query): string
    {
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            $name = explode('=', $pair, 2)[0];
            $pairs[] = strpos($pair, '=') !== false && preg_match(self::SENSITIVE, $name) ? $name . '=' . self::FILTERED : $pair;
        }

        return substr(implode('&', $pairs), 0, 2048);
    }

    public static function now(): string
    {
        $t = microtime(true);

        return gmdate('Y-m-d\TH:i:s', (int) $t) . sprintf('.%03dZ', (int) (($t - floor($t)) * 1000));
    }

    public static function nowNs(): int
    {
        return (int) round(microtime(true) * 1e6) * 1000;
    }

    public static function retryAfter(?string $header, $body): float
    {
        if ($header !== null && $header !== '') {
            if (ctype_digit($header)) {
                return max((float) $header, 1.0);
            }
            $when = strtotime($header);
            if ($when !== false) {
                return max($when - time(), 1.0);
            }
        }
        if (is_array($body) && isset($body['retry_after']) && is_numeric($body['retry_after'])) {
            return max((float) $body['retry_after'], 1.0);
        }

        return 60.0;
    }

    public static function requestFromServer(array $server): ?array
    {
        if (!isset($server['REQUEST_METHOD'])) {
            return null;
        }
        $https = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off';
        $scheme = ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || $https ? 'https' : 'http';
        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = explode('?', $uri, 2)[0];
        $query = (string) ($server['QUERY_STRING'] ?? (explode('?', $uri, 2)[1] ?? ''));
        $out = ['url' => self::cleanUrl("{$scheme}://{$host}{$path}"), 'method' => substr((string) $server['REQUEST_METHOD'], 0, 16)];
        if ($query !== '') {
            $out['query_string'] = self::scrubQuery($query);
        }
        $headers = [];
        foreach (self::HEADER_ALLOW as $name => $key) {
            if (isset($server[$key]) && $server[$key] !== '') {
                $headers[$name] = substr((string) $server[$key], 0, 1024);
            }
        }
        if ($headers) {
            $out['headers'] = $headers;
        }

        return $out;
    }

    public static function securityEvent(string $kind, string $actor, array $fields): array
    {
        $level = in_array($kind, ['login_failed', 'login_blocked', 'privilege_granted'], true) ? 'warning' : 'info';
        $message = "{$kind} for {$actor}";
        if (!in_array($kind, self::SECURITY_KINDS, true)) {
            return ['message' => $message, 'level' => $level, 'tags' => ['security.kind' => $kind]];
        }
        $security = ['kind' => $kind, 'actor' => substr(trim($actor), 0, 256)];
        foreach (self::SECURITY_FIELDS as $field => $size) {
            if (isset($fields[$field]) && $fields[$field] !== '') {
                $security[$field] = substr(trim((string) $fields[$field]), 0, $size);
            }
        }

        return ['message' => $message, 'level' => $level, 'security' => $security, 'tags' => ['security.kind' => $kind]];
    }

    public function development(): bool
    {
        return !empty($this->options['debug']) || $this->environment === 'development';
    }

    public function addBreadcrumb(array $crumb, array $hint = []): void
    {
        $category = (string) ($crumb['category'] ?? 'custom');
        $mapped = in_array($category, self::CATEGORIES, true) ? $category : (in_array($category, ['db', 'db.sql.query', 'sql'], true) ? 'query' : 'custom');
        $out = ['timestamp' => self::now(), 'category' => $mapped];
        if (isset($crumb['message']) && $crumb['message'] !== '') {
            $out['message'] = self::text($crumb['message'], 1024);
        }
        $level = self::LEVELS[strtolower((string) ($crumb['level'] ?? 'info'))] ?? 'info';
        $out['level'] = $level === 'fatal' ? 'error' : $level;
        $data = (array) ($crumb['data'] ?? []);
        if ($category !== $mapped && $category !== 'default' && !isset($data['category'])) {
            $data['category'] = substr($category, 0, 64);
        }
        if ($data) {
            $out['data'] = $data;
        }
        $before = $this->options['before_breadcrumb'] ?? null;
        if (is_callable($before)) {
            try {
                $out = $before($out, $hint);
            } catch (\Throwable $e) {
                error_log('[blipit] before_breadcrumb threw ' . get_class($e));
            }
            if (!$out) {
                return;
            }
        }
        $this->scope['breadcrumbs'][] = $out;
        if (count($this->scope['breadcrumbs']) > $this->maxBreadcrumbs) {
            array_shift($this->scope['breadcrumbs']);
        }
    }

    public function exceptionEvent(\Throwable $error, bool $handled = true, string $level = 'error'): ?array
    {
        foreach ((array) ($this->options['ignore_exceptions'] ?? []) as $class) {
            if (is_string($class) && $error instanceof $class) {
                return null;
            }
        }
        $exc = ['type' => substr(get_class($error), 0, 256), 'value' => (string) self::text($error->getMessage(), 4096), 'handled' => $handled];
        $frames = $this->frames($error->getFile(), $error->getLine(), $error->getTrace());
        if ($frames) {
            $exc['stacktrace'] = ['frames' => $frames];
        }
        $event = ['exception' => $exc, 'level' => $level];
        $chain = [];
        $previous = $error->getPrevious();
        while ($previous !== null && count($chain) < 8) {
            $chain[] = ['type' => get_class($previous), 'value' => substr($previous->getMessage(), 0, 1024)];
            $previous = $previous->getPrevious();
        }
        if ($chain) {
            $event['extra'] = ['exception_chain' => $chain];
        }

        return $event;
    }

    public function frames(string $file, int $line, array $trace): array
    {
        $frames = [];
        $current = ['file' => $file, 'line' => $line];
        foreach ($trace as $call) {
            $current['function'] = isset($call['function']) ? (isset($call['class']) ? $call['class'] . ($call['type'] ?? '::') . $call['function'] : $call['function']) : null;
            $frames[] = $this->frame($current);
            $current = ['file' => $call['file'] ?? '', 'line' => $call['line'] ?? 0];
        }
        $current['function'] = null;
        $frames[] = $this->frame($current);

        return array_slice(array_reverse($frames), -128);
    }

    private function frame(array $at): array
    {
        $path = (string) $at['file'];
        $line = (int) $at['line'];
        $inApp = $path !== '' && strpos($path, '/vendor/') === false && strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) === false;
        $short = $path === '' ? '[internal]' : $path;
        if ($this->root !== null && strpos($short, $this->root . '/') === 0) {
            $short = substr($short, strlen($this->root) + 1);
        }
        $frame = ['filename' => substr($short, 0, 1024), 'lineno' => max($line, 0), 'in_app' => $inApp];
        if (!empty($at['function'])) {
            $frame['function'] = substr((string) $at['function'], 0, 512);
        }
        $context = (int) ($this->options['context_lines'] ?? 5);
        $lines = $context > 0 && $path !== '' ? $this->source($path) : null;
        if ($lines !== null && $line > 0 && $line <= count($lines)) {
            $i = $line - 1;
            $context = min($context, 5);
            $frame['context_line'] = substr(rtrim($lines[$i], "\r\n"), 0, 512);
            $frame['pre_context'] = array_map([$this, 'cutLine'], array_slice($lines, max($i - $context, 0), $i - max($i - $context, 0)));
            $frame['post_context'] = array_map([$this, 'cutLine'], array_slice($lines, $i + 1, $context));
        }

        return $frame;
    }

    private function cutLine(string $line): string
    {
        return substr(rtrim($line, "\r\n"), 0, 512);
    }

    private function source(string $path): ?array
    {
        if (array_key_exists($path, $this->sources)) {
            return $this->sources[$path];
        }
        if (count($this->sources) >= 50) {
            array_shift($this->sources);
        }
        $lines = @is_file($path) && @filesize($path) < 1000000 ? @file($path) : false;

        return $this->sources[$path] = $lines === false ? null : $lines;
    }

    public function capture(?array $partial, array $hint = []): ?string
    {
        if ($partial === null) {
            return null;
        }
        if (!isset($partial['security']) && $this->sampleRate < 1.0 && mt_rand() / mt_getrandmax() >= $this->sampleRate) {
            return null;
        }
        if (microtime(true) < $this->limitedUntil) {
            return null;
        }
        $event = [
            'event_id' => bin2hex(random_bytes(16)),
            'timestamp' => self::now(),
            'platform' => 'php',
            'level' => 'error',
            'environment' => substr($this->environment, 0, 64),
            'contexts' => [
                'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
                'os' => ['name' => PHP_OS_FAMILY, 'version' => php_uname('r')],
            ],
            'sdk' => ['name' => self::SDK_NAME, 'version' => Blipit::VERSION],
        ];
        if (isset($this->options['release']) && $this->options['release'] !== '') {
            $event['release'] = self::text($this->options['release'], 256);
        }
        $tags = [];
        if (!empty($this->options['server_name'])) {
            $tags['server'] = (string) $this->options['server_name'];
        }
        $tags = array_merge($tags, $this->scope['tags'], (array) ($partial['tags'] ?? []));
        unset($partial['tags']);
        $span = $this->current();
        if ($span !== null && $span['sampled']) {
            $tags['trace_id'] = $span['trace_id'];
        }
        $clean = [];
        foreach ($tags as $k => $v) {
            if (preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', (string) $k) && $v !== null && count($clean) < 32) {
                $clean[(string) $k] = substr(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, 0, 256);
            }
        }
        if ($clean) {
            $event['tags'] = $clean;
        }
        $extra = array_merge($this->scope['extra'], (array) ($partial['extra'] ?? []));
        unset($partial['extra']);
        if ($extra) {
            $event['extra'] = $extra;
        }
        $user = $partial['user'] ?? $this->scope['user'];
        unset($partial['user']);
        if (is_array($user)) {
            $u = [];
            foreach (['id' => 128, 'email' => 256, 'username' => 128] as $field => $size) {
                if (isset($user[$field]) && $user[$field] !== '') {
                    $u[$field] = substr((string) $user[$field], 0, $size);
                }
            }
            if ($u) {
                $event['user'] = $u;
            }
        }
        if ($this->scope['breadcrumbs']) {
            $event['breadcrumbs'] = array_slice($this->scope['breadcrumbs'], -100);
        }
        $request = $this->scope['request'] ?? (PHP_SAPI === 'cli' ? null : self::requestFromServer($_SERVER));
        if ($request) {
            $event['request'] = $request;
        }
        $fingerprint = $partial['fingerprint'] ?? $this->scope['fingerprint'];
        unset($partial['fingerprint']);
        if ($fingerprint) {
            $event['fingerprint'] = array_slice(array_map(static function ($f): string {
                return substr((string) $f, 0, 256);
            }, (array) $fingerprint), 0, 8);
        }
        $event = array_merge($event, $partial);
        if (isset($event['message'])) {
            $event['message'] = self::text($event['message'], 8192);
        }
        $event = self::scrub(array_filter($event, static function ($v): bool {
            return $v !== null;
        }));
        $before = $this->options['before_send'] ?? null;
        if (is_callable($before)) {
            try {
                $event = $before($event, $hint);
            } catch (\Throwable $e) {
                error_log('[blipit] before_send threw ' . get_class($e) . ', the event was sent unchanged');
            }
            if (!$event) {
                return null;
            }
        }
        $this->queue[] = $event;
        if (count($this->queue) > self::MAX_EVENTS) {
            array_shift($this->queue);
        }
        if (PHP_SAPI === 'cli' && count($this->queue) >= (int) ($this->options['batch_size'] ?? self::SEND_AT)) {
            $this->flush(2.0);
        }

        return $event['event_id'];
    }

    public function flush(float $timeout = 2.0): void
    {
        $deadline = microtime(true) + $timeout;
        while ($this->queue && microtime(true) < $deadline) {
            $event = array_shift($this->queue);
            $this->deliver('/api/v1/events', $event, true, $deadline);
        }
        $this->queue = [];
        while ($this->spans && microtime(true) < $deadline) {
            $batch = array_splice($this->spans, 0, 1000);
            $this->deliver('/v1/traces', $this->otlp($batch), false, $deadline);
        }
        $this->spans = [];
    }

    private function deliver(string $path, array $body, bool $event, float $deadline): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (microtime(true) < $this->limitedUntil) {
                return;
            }
            [$status, $headers, $payload] = $this->post($path, $body, max(0.5, min((float) ($this->options['http_timeout'] ?? 2.0), $deadline - microtime(true))));
            if ($status === 429) {
                $this->limitedUntil = microtime(true) + self::retryAfter($headers['retry-after'] ?? null, $payload);
                if (!empty($this->options['debug'])) {
                    error_log('[blipit] rate limited, pausing sends');
                }

                return;
            }
            if ($status > 0 && $status < 500) {
                if ($status >= 400 && !empty($this->options['debug'])) {
                    error_log("[blipit] {$path} rejected {$status} " . json_encode($payload));
                }
                if ($event && $status === 202 && $this->development() && is_array($payload) && isset($payload['latest'])) {
                    $this->notice($payload['latest']);
                }

                return;
            }
            if (microtime(true) + 0.25 * (2 ** $attempt) >= $deadline) {
                return;
            }
            usleep((int) (250000 * (2 ** $attempt)));
        }
    }

    public function post(string $path, array $body, float $timeout): array
    {
        if (isset(self::$refusedKeys[$this->key])) {
            return [409, [], null];
        }
        $answer = $this->send($path, $body, $timeout);
        if ($answer[0] === 409 && is_array($answer[2]) && ($answer[2]['reason'] ?? null) === 'platform_mismatch') {
            self::$refusedKeys[$this->key] = true;
            error_log('[blipit] ' . (is_string($answer[2]['error'] ?? null) ? $answer[2]['error'] : 'This key belongs to a project for another platform.') . ' Blipit stopped sending with this key until the process restarts.');
        }

        return $answer;
    }

    private function send(string $path, array $body, float $timeout): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $headers = ['Content-Type: application/json', 'X-Blipit-Key: ' . $this->key, 'User-Agent: ' . self::SDK_NAME . '/' . Blipit::VERSION];
        $url = $this->base . $path;
        if (function_exists('curl_init')) {
            $received = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int) (min($timeout, 2.0) * 1000),
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received): int {
                    $pair = explode(':', $line, 2);
                    if (count($pair) === 2) {
                        $received[strtolower(trim($pair[0]))] = trim($pair[1]);
                    }

                    return strlen($line);
                },
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($response === false && !empty($this->options['debug'])) {
                error_log("[blipit] {$path} not sent: " . curl_error($ch));
            }

            return [$response === false ? 0 : $status, $received, is_string($response) ? json_decode($response, true) : null];
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $stream = @fopen($url, 'r', false, $context);
        $response = $stream === false ? false : stream_get_contents($stream);
        $lines = $stream === false ? [] : (array) (stream_get_meta_data($stream)['wrapper_data'] ?? []);
        if ($stream !== false) {
            fclose($stream);
        }
        $status = $lines && preg_match('#^HTTP/\S+\s+(\d{3})#', $lines[0], $m) ? (int) $m[1] : 0;
        $received = [];
        foreach (array_slice($lines, 1) as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) {
                $received[strtolower(trim($pair[0]))] = trim($pair[1]);
            }
        }

        return [$response === false ? 0 : $status, $received, is_string($response) ? json_decode($response, true) : null];
    }

    private function notice($latest): void
    {
        if ($this->noticed) {
            return;
        }
        $line = Blipit::noticeLine($latest);
        if ($line !== null) {
            $this->noticed = true;
            error_log($line);
        }
    }

    public function tracing(): bool
    {
        return $this->tracesRate > 0;
    }

    public function current(): ?array
    {
        return $this->stack ? $this->stack[count($this->stack) - 1] : null;
    }

    public function startSpan(string $name, int $kind, array $attrs, bool $root = false, ?int $startNs = null): ?array
    {
        if ($this->tracesRate <= 0) {
            return null;
        }
        $parent = $root ? null : $this->current();
        if ($parent === null && !$root) {
            return null;
        }
        $sampled = $parent !== null ? $parent['sampled'] : mt_rand() / mt_getrandmax() < $this->tracesRate;
        if (!$sampled) {
            return ['sampled' => false, 'trace_id' => '', 'span_id' => '', 'kind' => $kind];
        }

        return [
            'trace_id' => $parent !== null ? $parent['trace_id'] : bin2hex(random_bytes(16)),
            'span_id' => bin2hex(random_bytes(8)),
            'parent_id' => $parent !== null ? $parent['span_id'] : null,
            'name' => $name,
            'kind' => $kind,
            'start' => $startNs ?? self::nowNs(),
            'end' => 0,
            'error' => false,
            'attrs' => $attrs,
            'sampled' => true,
        ];
    }

    public function finishSpan(?array $span, bool $error = false, ?int $endNs = null): void
    {
        if ($span === null || !$span['sampled'] || count($this->spans) >= self::MAX_SPANS) {
            return;
        }
        $span['end'] = $endNs ?? self::nowNs();
        $span['error'] = $span['error'] || $error;
        $this->spans[] = $span;
    }

    public function otlp(array $spans): array
    {
        $attr = static function (string $k, $v): array {
            if (is_bool($v)) {
                return ['key' => $k, 'value' => ['boolValue' => $v]];
            }
            if (is_int($v)) {
                return ['key' => $k, 'value' => ['intValue' => $v]];
            }
            if (is_float($v)) {
                return ['key' => $k, 'value' => ['doubleValue' => $v]];
            }

            return ['key' => $k, 'value' => ['stringValue' => (string) $v]];
        };
        $rows = [];
        foreach ($spans as $s) {
            $attributes = [];
            foreach (self::scrub($s['attrs']) as $k => $v) {
                $attributes[] = $attr((string) $k, $v);
            }
            $attributes[] = $attr('blipit.sample_rate', $this->tracesRate);
            $row = [
                'traceId' => $s['trace_id'],
                'spanId' => $s['span_id'],
                'name' => substr((string) $s['name'], 0, 512),
                'kind' => $s['kind'],
                'startTimeUnixNano' => (string) $s['start'],
                'endTimeUnixNano' => (string) $s['end'],
                'attributes' => $attributes,
                'status' => ['code' => $s['error'] ? 2 : 1],
            ];
            if ($s['parent_id'] !== null) {
                $row['parentSpanId'] = $s['parent_id'];
            }
            $rows[] = $row;
        }
        $host = (string) gethostname();
        $resource = [];
        foreach ([
            'service.name' => $this->options['server_name'] ?? getenv('OTEL_SERVICE_NAME') ?: 'php',
            'host.name' => $host,
            'service.instance.id' => $host . ':' . getmypid(),
            'deployment.environment' => $this->environment,
            'service.version' => $this->options['release'] ?? null,
            'process.runtime.version' => PHP_VERSION,
            'telemetry.sdk.name' => self::SDK_NAME,
        ] as $k => $v) {
            if ($v !== null && $v !== '' && $v !== false) {
                $resource[] = ['key' => $k, 'value' => ['stringValue' => (string) $v]];
            }
        }

        return ['resourceSpans' => [['resource' => ['attributes' => $resource], 'scopeSpans' => [['scope' => ['name' => self::SDK_NAME], 'spans' => $rows]]]]];
    }
}
