## 0.2.0

- `Blipit::init` with an empty key or project id writes a warning with `error_log`, returns false and leaves Blipit off instead of throwing InvalidArgumentException (Laravel with `config:cache` returns null from `env()`). It returns true when started.
