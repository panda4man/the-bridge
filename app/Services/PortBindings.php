<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Ported from reference/src/services/portBindings.ts.
 *
 * Reads docker-compose.yml's `services.*.ports`, fully interpolating `.env`
 * values Compose-style: ${VAR}, ${VAR:-default}, ${VAR-default},
 * ${VAR:+alt}, ${VAR+alt}, ${VAR:?err}, ${VAR?err}, and bare $VAR. The
 * `:`-prefixed variants treat empty as unset; the bare (no-colon) variants
 * treat only undefined (missing from .env) as unset — that distinction is
 * the whole point of carrying both `$colon` and `$isSet`/`$usable` through
 * interpolate() below, not just collapsing to one "is empty" check.
 *
 * EVERY failure path returns [] silently — missing file, unreadable file,
 * malformed YAML, wrong shape, anything unanticipated. This renders directly
 * in the UI (Filament, later phases) and must never throw a page-breaking
 * exception, so the whole body is additionally wrapped in a catch-all as a
 * backstop for any failure mode not explicitly enumerated below.
 */
final class PortBindings
{
    /**
     * @return list<array{service: string, host_ip: ?string, host_port: ?string, container_port: string, protocol: string}>
     */
    public static function read(string $appPath): array
    {
        try {
            $composePath = rtrim($appPath, '/').'/docker-compose.yml';
            if (! file_exists($composePath)) {
                return [];
            }

            $raw = @file_get_contents($composePath);
            if ($raw === false) {
                return [];
            }

            try {
                $doc = Yaml::parse($raw);
            } catch (Throwable) {
                return [];
            }

            if (! is_array($doc)) {
                return [];
            }

            $services = $doc['services'] ?? null;
            if (! is_array($services)) {
                return [];
            }

            $env = self::readDotenv($appPath);
            $bindings = [];

            foreach ($services as $serviceName => $serviceDef) {
                if (! is_array($serviceDef)) {
                    continue;
                }
                $ports = $serviceDef['ports'] ?? null;
                if (! is_array($ports) || ! array_is_list($ports)) {
                    continue;
                }
                foreach ($ports as $entry) {
                    $parsed = self::parseEntry($entry, (string) $serviceName, $env);
                    if ($parsed !== null) {
                        $bindings[] = $parsed;
                    }
                }
            }

            usort($bindings, function (array $a, array $b) {
                if ($a['service'] !== $b['service']) {
                    return strcmp($a['service'], $b['service']);
                }

                return strnatcmp($a['container_port'], $b['container_port']);
            });

            return $bindings;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private static function readDotenv(string $appPath): array
    {
        $envPath = rtrim($appPath, '/').'/.env';
        if (! file_exists($envPath)) {
            return [];
        }

        $raw = @file_get_contents($envPath);
        if ($raw === false) {
            return [];
        }

        $result = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $eq = strpos($trimmed, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $eq));
            $value = trim(substr($trimmed, $eq + 1));
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Compose-style interpolation: ${VAR}, ${VAR:-default}, ${VAR-default},
     * ${VAR:+alt}, ${VAR+alt}, ${VAR:?err}, ${VAR?err}, and $VAR.
     *
     * @param  array<string, string>  $env
     */
    private static function interpolate(string $input, array $env): string
    {
        $braced = preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?)([-+?])([^}]*))?\}/',
            function (array $m) use ($env) {
                $name = $m[1];
                $colon = $m[2] ?? '';
                $op = $m[3] ?? '';
                $arg = $m[4] ?? '';

                $isSet = array_key_exists($name, $env);
                $val = $isSet ? $env[$name] : null;
                $usable = $colon === ':' ? ($isSet && $val !== '') : $isSet;

                if ($op === '') {
                    return $val ?? '';
                }
                if ($op === '-') {
                    return $usable ? $val : $arg;
                }
                if ($op === '+') {
                    return $usable ? $arg : '';
                }
                if ($op === '?') {
                    return $usable ? $val : '';
                }

                return $val ?? '';
            },
            $input,
        );

        return preg_replace_callback(
            '/\$([A-Za-z_][A-Za-z0-9_]*)/',
            fn (array $m) => $env[$m[1]] ?? '',
            $braced,
        );
    }

    /**
     * @param  array<string, string>  $env
     */
    private static function interpolateIfString(mixed $value, array $env): mixed
    {
        return is_string($value) ? self::interpolate($value, $env) : $value;
    }

    /**
     * @param  array<string, string>  $env
     * @return array{service: string, host_ip: ?string, host_port: ?string, container_port: string, protocol: string}|null
     */
    private static function parseShortForm(string $raw, string $service, array $env): ?array
    {
        $resolved = trim(self::interpolate($raw, $env));
        if ($resolved === '') {
            return null;
        }

        $parts = explode('/', $resolved);
        $hostAndContainer = $parts[0];
        $protocol = self::normalizeProtocol($parts[1] ?? null);
        $segments = explode(':', $hostAndContainer);

        $hostIp = null;
        $hostPort = null;
        $containerPort = null;

        if (count($segments) === 1) {
            $containerPort = $segments[0];
        } elseif (count($segments) === 2) {
            $hostPort = $segments[0];
            $containerPort = $segments[1];
        } elseif (count($segments) === 3) {
            $hostIp = $segments[0];
            $hostPort = $segments[1];
            $containerPort = $segments[2];
        } else {
            return null;
        }

        if ($containerPort === null || $containerPort === '') {
            return null;
        }
        if ($hostPort === '') {
            $hostPort = null;
        }
        if ($hostIp === '') {
            $hostIp = null;
        }

        return [
            'service' => $service,
            'host_ip' => $hostIp,
            'host_port' => $hostPort,
            'container_port' => $containerPort,
            'protocol' => $protocol,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, string>  $env
     * @return array{service: string, host_ip: ?string, host_port: ?string, container_port: string, protocol: string}|null
     */
    private static function parseLongForm(array $entry, string $service, array $env): ?array
    {
        $target = self::interpolateIfString($entry['target'] ?? null, $env);
        if ($target === null || $target === '') {
            return null;
        }

        $published = self::interpolateIfString($entry['published'] ?? null, $env);
        $hostIpVal = self::interpolateIfString($entry['host_ip'] ?? null, $env);
        $protocolVal = self::interpolateIfString($entry['protocol'] ?? null, $env);

        return [
            'service' => $service,
            'host_ip' => (is_string($hostIpVal) && $hostIpVal !== '') ? $hostIpVal : null,
            'host_port' => ($published === null || $published === '') ? null : (string) $published,
            'container_port' => (string) $target,
            'protocol' => self::normalizeProtocol($protocolVal),
        ];
    }

    /**
     * @param  array<string, string>  $env
     * @return array{service: string, host_ip: ?string, host_port: ?string, container_port: string, protocol: string}|null
     */
    private static function parseEntry(mixed $entry, string $service, array $env): ?array
    {
        if (is_string($entry)) {
            return self::parseShortForm($entry, $service, $env);
        }
        if (is_int($entry) || is_float($entry)) {
            return self::parseShortForm((string) $entry, $service, $env);
        }
        if (is_array($entry)) {
            return self::parseLongForm($entry, $service, $env);
        }

        return null;
    }

    private static function normalizeProtocol(mixed $value): string
    {
        return $value === 'udp' ? 'udp' : 'tcp';
    }
}
