# blipit/blipit

Blipit error monitoring for PHP. Know your app broke before your users tell you.

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
    'project' => <project id>,
    'environment' => 'production',
    'release' => 'myapp@1.4.2',
]);
```

Both values are on the project's Keys page at app.blipit.io. Uncaught exceptions and fatal errors are reported on their own.

## Laravel

Call `Blipit::init([...])` in `AppServiceProvider::boot`. If you want the Laravel integration (request context, queue jobs, a log channel), install `sentry/sentry-laravel` instead and set `SENTRY_LARAVEL_DSN=https://<public key>@in.blipit.io/<project id>` in `.env`.

## Manual capture

```php
Blipit::captureException($e);
Blipit::captureMessage('cache miss rate above 50%', 'warning');
Blipit::setUser(['id' => '42', 'email' => 'ana@example.com']);
Blipit::setTag('region', 'ap-southeast-1');
Blipit::addBreadcrumb('cache cleared', 'cache');
```

## Login attempts

```php
Blipit::captureSecurity('login_failed', $email, 'wrong password', null, $request->ip(), $request->userAgent());
```

`kind` is one of `login_failed`, `login_blocked`, `login_succeeded`, `password_reset` or any short name you choose. Failed and blocked logins are reported as warnings.

## Performance

Pass `'traces_sample_rate' => 0.2` to `init` and requests show up on the Performance page.

Docs: https://docs.blipit.io/php
