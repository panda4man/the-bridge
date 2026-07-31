<?php

namespace App\Services;

use App\Services\Process\ProcessRunner;
use Throwable;

/**
 * Ported from reference/src/services/containerStatus.ts.
 *
 * Runs `docker compose ps --format json` in the app's working directory.
 * Docker Compose v2 emits NDJSON — one JSON object per line, not a JSON
 * array — so parsing is line-by-line, not a single json_decode() of the
 * whole output.
 *
 * 8-second wall-clock timeout (not idle — this is a quick status probe, not
 * a long-running build). Any failure at all — missing docker binary,
 * non-zero exit, timeout, malformed JSON on any line — returns [] silently,
 * matching the reference's single enclosing try/catch.
 */
final class ContainerStatus
{
    private const TIMEOUT_SECONDS = 8.0;

    public function __construct(private readonly ProcessRunner $runner) {}

    /**
     * @return list<array{name: string, service: string, state: string, status: string, ports: string}>
     */
    public function forWorkDir(string $workDir): array
    {
        try {
            $result = $this->runner->run(
                ['docker', 'compose', 'ps', '--format', 'json'],
                $workDir,
                timeout: self::TIMEOUT_SECONDS,
            );

            if (! $result->successful()) {
                return [];
            }

            $out = trim($result->output);
            if ($out === '') {
                return [];
            }

            $items = [];
            foreach (explode("\n", $out) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // Decoded WITHOUT assoc=true so a JSON array (list) and a JSON
                // object are distinguishable even when empty — json_decode($x,
                // true) turns both `[]` and `{}` into an indistinguishable PHP
                // `[]`, which would make an empty container object silently
                // vanish instead of becoming a single all-defaults row.
                $parsed = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
                if (is_array($parsed)) {
                    foreach ($parsed as $entry) {
                        $items[] = (array) $entry;
                    }
                } else {
                    $items[] = (array) $parsed;
                }
            }

            return array_map(static fn (array $c) => [
                'name' => $c['Name'] ?? '',
                'service' => $c['Service'] ?? '',
                'state' => strtolower($c['State'] ?? 'unknown'),
                'status' => $c['Status'] ?? '',
                'ports' => $c['Ports'] ?? '',
            ], $items);
        } catch (Throwable) {
            return [];
        }
    }
}
