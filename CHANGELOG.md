## 0.3.1

- Platform mismatch: when Blipit refuses data because the key belongs to a project for another platform (HTTP 409 platform_mismatch), the SDK logs one clear error and stops sending for the rest of the run.

## 0.3.0

- Runs on Blipit's own core, blipit-nuggets, with no third-party monitoring package: `composer require blipit/blipit` pulls nothing else. Events go to `POST /api/v1/events` in Blipit's event format with the `X-Blipit-Key` header; spans go to `/v1/traces`.
- The same calls keep working: `Blipit::init`, `captureException`, `captureMessage`, `captureSecurity`, `setUser`, `setTag`, `addBreadcrumb`, `flush`. Capture calls return the event id as a string. New: `setContext` and `trace`.
- Laravel: `Blipit\Laravel\BlipitServiceProvider` is discovered on its own. It reports what Laravel's exception handler reports (so 404s and validation errors stay out), adds query, log and HTTP client breadcrumbs, names the request span after the route, traces queued jobs and sends after each job. It can also start Blipit from `config('services.blipit')`.
- Symfony: `Blipit\Symfony\BlipitListener` for `kernel.exception`, `kernel.request`, `kernel.terminate` and `console.error`.
- Uncaught exceptions, warnings (respecting `error_reporting` and `@`) and fatal errors are reported; `error_types` picks which PHP errors count. Stack frames carry five lines of source.
- In web requests events are queued and sent after the response (FastCGI requests are finished first); in the CLI they go out in batches of 10 and at exit. The queue holds at most 100 events; a `429` pauses sending for the `Retry-After` given and a `5xx` is retried.
- Passwords, tokens, cookies, sessions, API keys and card numbers are replaced with `[Filtered]` before sending; request headers are limited to user agent, referer, content type and language.
- `project` is no longer needed; it is still accepted. `Blipit::dsn()` and `Blipit::notice()` are gone. `before_send` now receives and returns the event as an array in Blipit's format.

## 0.2.1

- Events identify themselves as `blipit-php` with the Blipit version, so the dashboard can show when this project runs an old Blipit SDK. A `before_send` you pass still runs after it.
- In development (`'environment' => 'development'` or `'debug' => true`) the SDK writes one line with `error_log` per process when a newer version is out: `[blipit] 0.2.2 is available (you have 0.2.1) — <why> — composer update blipit/blipit`. It reads a header on the responses to events it already sends (no extra request); an `http_client` you pass is left alone. Nothing is written in production.

## 0.2.0

- `Blipit::init` with an empty key or project id writes a warning with `error_log`, returns false and leaves Blipit off instead of throwing InvalidArgumentException (Laravel with `config:cache` returns null from `env()`). It returns true when started.
