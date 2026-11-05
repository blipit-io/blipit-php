<?php

declare(strict_types=1);

namespace Blipit;

final class Packages
{
    public const MAX = 3000;
    public const PATH = '/api/v1/sdk/packages';

    private static bool $registered = false;

    public static function installed(): array
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return [];
        }
        $out = [];
        foreach (\Composer\InstalledVersions::getAllRawData() as $data) {
            $root = (string) ($data['root']['name'] ?? '');
            foreach ((array) ($data['versions'] ?? []) as $name => $info) {
                $version = (string) ($info['pretty_version'] ?? '');
                if ($name === $root || $version === '' || strpos($version, 'dev-') === 0 || !isset($info['version'])) {
                    continue;
                }
                $out["{$name}@{$version}"] = ['ecosystem' => 'Packagist', 'name' => (string) $name, 'version' => (string) preg_replace('/^v(?=\d)/', '', $version)];
            }
        }
        ksort($out);

        return array_slice(array_values($out), 0, self::MAX);
    }

    public static function marker(string $key): string
    {
        return sys_get_temp_dir() . '/blipit-packages-' . substr(hash('sha256', $key), 0, 16);
    }

    public static function report(Nuggets $client, string $key, ?array $packages = null): int
    {
        $packages = $packages ?? self::installed();
        if (!$packages) {
            return 0;
        }
        $stamp = hash('sha256', implode("\n", array_map(static fn (array $p): string => "{$p['name']}@{$p['version']}", $packages)));
        $marker = self::marker($key);
        if (is_file($marker) && @file_get_contents($marker) === $stamp) {
            return 0;
        }
        if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        [$status] = $client->post(self::PATH, ['packages' => $packages], 5.0, 'PUT');
        if ($status < 200 || $status >= 300) {
            return 0;
        }
        @file_put_contents($marker, $stamp);

        return count($packages);
    }

    public static function register(string $key): void
    {
        if (self::$registered || strpos($key, 'blipit_pk_') === 0) {
            return;
        }
        self::$registered = true;
        register_shutdown_function(static function () use ($key): void {
            $client = Blipit::client();
            if ($client === null) {
                return;
            }
            try {
                self::report($client, $key);
            } catch (\Throwable $e) {
            }
        });
    }
}
