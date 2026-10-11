<?php

declare(strict_types=1);

namespace Blipit;

use Sentry\Breadcrumb;
use Sentry\EventId;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;

final class Blipit
{
    public const DEFAULT_ENDPOINT = 'https://in.blipit.io';
    public const PUBLIC_KEY_WARNING = "[blipit] login attempts need the project's secret key (blipit_sk_...). "
        . 'This SDK was started with the public key, so captureSecurity sends nothing. Use the secret key on the server.';

    public const MISSING_KEY_WARNING = "[blipit] Blipit::init got no key, so Blipit is off and nothing is sent. "
        . "Pass the project's key (app.blipit.io, Keys). In Laravel read it through config/services.php, not env() in app code, "
        . 'because env() returns null once config is cached (php artisan config:cache).';
    public const MISSING_PROJECT_WARNING = '[blipit] Blipit::init got no project id, so Blipit is off and nothing is sent. '
        . "Pass 'project' with the id from the project's Keys page at app.blipit.io.";

    private static bool $publicKey = false;
    private static bool $publicKeyWarned = false;

    public static function dsn(string $key, $project, string $endpoint = self::DEFAULT_ENDPOINT): string
    {
        $scheme = strpos($endpoint, 'http://') === 0 ? 'http' : 'https';
        $host = rtrim((string) preg_replace('#^[a-z]+://#i', '', $endpoint), '/');

        return "{$scheme}://{$key}@{$host}/{$project}";
    }

    public static function init(array $options): bool
    {
        $key = trim((string) ($options['key'] ?? ''));
        if ($key === '') {
            error_log(self::MISSING_KEY_WARNING);

            return false;
        }
        if (!isset($options['project']) || trim((string) $options['project']) === '') {
            error_log(self::MISSING_PROJECT_WARNING);

            return false;
        }
        self::$publicKey = strpos($key, 'blipit_pk_') === 0;
        $project = $options['project'];
        $endpoint = (string) ($options['endpoint'] ?? self::DEFAULT_ENDPOINT);
        unset($options['key'], $options['project'], $options['endpoint']);

        \Sentry\init(array_merge(
            ['send_default_pii' => false, 'traces_sample_rate' => 0.0],
            $options,
            ['dsn' => self::dsn($key, $project, $endpoint)]
        ));

        return true;
    }

    public static function captureException(\Throwable $exception): ?EventId
    {
        return \Sentry\captureException($exception);
    }

    public static function captureMessage(string $message, string $level = 'info'): ?EventId
    {
        return \Sentry\captureMessage($message, new Severity($level));
    }

    public static function setUser(?array $user): void
    {
        \Sentry\configureScope(static function (Scope $scope) use ($user): void {
            $scope->setUser($user ?? []);
        });
    }

    public static function setTag(string $key, string $value): void
    {
        \Sentry\configureScope(static function (Scope $scope) use ($key, $value): void {
            $scope->setTag($key, $value);
        });
    }

    public static function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
        \Sentry\addBreadcrumb(new Breadcrumb($level, Breadcrumb::TYPE_DEFAULT, $category, $message, $data));
    }

    public static function captureSecurity(
        string $kind,
        string $actor,
        ?string $outcome = null,
        ?string $actorId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $target = null
    ): ?EventId {
        if (self::$publicKey) {
            if (!self::$publicKeyWarned) {
                self::$publicKeyWarned = true;
                error_log(self::PUBLIC_KEY_WARNING);
            }

            return null;
        }
        $payload = array_filter([
            'kind' => $kind,
            'actor' => $actor,
            'outcome' => $outcome,
            'actor_id' => $actorId,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'target' => $target,
        ], static function ($value): bool {
            return $value !== null;
        });
        $level = in_array($kind, ['login_failed', 'login_blocked'], true) ? 'warning' : 'info';

        return \Sentry\withScope(static function (Scope $scope) use ($payload, $kind, $actor, $level): ?EventId {
            $scope->setContext('security', $payload);
            $scope->setTag('security.kind', $kind);

            return \Sentry\captureMessage("{$kind} for {$actor}", new Severity($level));
        });
    }

    public static function flush(int $timeout = 2): void
    {
        $client = SentrySdk::getCurrentHub()->getClient();
        if ($client !== null) {
            $client->flush($timeout);
        }
    }
}
