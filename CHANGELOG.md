## 0.2.1

- Events identify themselves as `blipit-php` with the Blipit version, so the dashboard can show when this project runs an old Blipit SDK. A `before_send` you pass still runs after it.
- In development (`'environment' => 'development'` or `'debug' => true`) the SDK writes one line with `error_log` per process when a newer version is out: `[blipit] 0.2.2 is available (you have 0.2.1) — <why> — composer update blipit/blipit`. It reads a header on the responses to events it already sends (no extra request); an `http_client` you pass is left alone. Nothing is written in production.

## 0.2.0

- `Blipit::init` with an empty key or project id writes a warning with `error_log`, returns false and leaves Blipit off instead of throwing InvalidArgumentException (Laravel with `config:cache` returns null from `env()`). It returns true when started.
