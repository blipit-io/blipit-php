# blipit/blipit

Blipit error monitoring for PHP. Know your app broke before your users tell you.

Built on Blipit's own core, blipit-nuggets. No third-party monitoring package: `composer require blipit/blipit` pulls nothing else.

## Install

```sh
composer require blipit/blipit
```

## Quick start

Call `Blipit::init` once at startup, before any request is handled.

```php
use Blipit\Blipit;

Blipit::init([
    'key' => '<public key>',
    'environment' => 'production',
    'release' => 'myapp@1.4.2',
]);
```

The key is on the project's Keys page at app.blipit.io. `'project'` is still accepted for older setups and no longer needed. If the key is empty, `init` writes a warning with `error_log`, returns `false` and Blipit stays off; it never throws, so a missing key cannot stop the app from booting. Uncaught exceptions, warnings and fatal errors are reported on their own.

## Laravel

The service provider is discovered on its own. Add the values to `config/services.php`:

```php
'blipit' => [
    'key' => env('BLIPIT_KEY'),
    'environment' => env('APP_ENV'),
    'release' => env('GIT_SHA'),
],
```

Blipit then starts from that config (an explicit `Blipit::init([...])` in `AppServiceProvider::boot` keeps working). Read the values through config, because `env()` returns null in app code once `php artisan config:cache` has run.

Every exception Laravel's handler reports reaches Blipit, so 404s and validation errors stay out. Queries, log lines and HTTP client calls become breadcrumbs, the request span is named after the route, queued jobs are traced and sent after each job. If you already pass exceptions on in `bootstrap/app.php` with `Blipit::captureException($e)`, keep it or remove it: the same exception is never sent twice.

## Symfony

Register the listener in `config/services.yaml`:

```yaml
Blipit\Symfony\BlipitListener:
    arguments: ['@router']
    tags:
        - { name: kernel.event_listener, event: kernel.exception, method: onKernelException }
        - { name: kernel.event_listener, event: kernel.request, method: onKernelRequest }
        - { name: kernel.event_listener, event: kernel.terminate, method: onKernelTerminate }
        - { name: kernel.event_listener, event: console.error, method: onConsoleError }
```

and call `Blipit::init([...])` early in `public/index.php` and `bin/console`. Errors with a 4xx status are left out.

## Manual capture

```php
Blipit::captureException($e);
Blipit::captureMessage('cache miss rate above 50%', 'warning');
Blipit::setUser(['id' => '42', 'email' => 'ana@example.com']);
Blipit::setTag('region', 'ap-southeast-1');
Blipit::setContext('order', ['id' => 1042, 'total' => 99.5]);
Blipit::addBreadcrumb('cache cleared', 'cache');
```

## Login attempts

```php
Blipit::captureSecurity('login_failed', $email, 'wrong password', null, $request->ip(), $request->userAgent());
```

`kind` is one of `login_failed`, `login_blocked`, `login_succeeded`, `password_reset_requested` or `privilege_granted`; any other kind arrives as a plain message. Login attempts need the project's secret key (init with it on the server) and the Scale plan; with the public key ingest refuses them with 403 `security_needs_secret_key`.

## Options

| Option | Default | |
| --- | --- | --- |
| `environment`, `release` | `production`, none | Shown on every issue. |
| `sample_rate` | `1.0` | Share of error events to send. |
| `traces_sample_rate` | `0.0` | Share of requests and jobs traced for Pulse. |
| `before_send` | none | `fn (array $event, array $hint): ?array`, return `null` to drop. |
| `before_breadcrumb` | none | Same for breadcrumbs. |
| `ignore_exceptions` | `[]` | Class names never sent. |
| `error_types` | `E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED` | PHP errors that become events. |
| `context_lines` | `5` | Source lines around each frame, `0` for none. |
| `default_integrations` | `true` | `false` keeps only what you capture yourself. |
| `batch_size` | `10` | In the CLI, events are sent once this many are queued. |
| `shutdown_timeout`, `http_timeout` | `2` | Seconds. |
| `server_name`, `max_breadcrumbs`, `project_root`, `debug` | | |

Other options from older versions are accepted and ignored.

## Pulse (performance)

Pass `'traces_sample_rate' => 0.2` to `init` and requests show up on the Pulse page. `Blipit::trace('import orders', fn () => $importer->run())` times any block inside a traced request or job.

## How sending works

Events are queued (at most 100) and sent to `POST /api/v1/events` after the response: FastCGI requests are finished first, so visitors never wait on Blipit. In the CLI they go out in batches and at exit; Laravel queue workers send after each job. A `429` pauses sending for the `Retry-After` the server gives; a `5xx` is retried. Keys and values that look like passwords, tokens, cookies, sessions or card numbers are replaced with `[Filtered]` before anything leaves the process.

## Updates

In development Blipit writes one line to the PHP error log when a newer version is out, with the reason and the command. Update with `composer update blipit/blipit`. Details: https://docs.blipit.io/updates

## Tests

```sh
composer install
vendor/bin/phpunit
```

Docs: https://docs.blipit.io/php
