<?php

namespace App\Services;

use App\Models\App;
use JsonException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Ported from reference/src/services/deploySteps.ts.
 *
 * Resolution priority: repo `bridge.yml` (in the app's working copy) beats
 * the UI/DB `deploy_steps` field beats none. The rollback edge case is
 * intentional (see the reference docblock this mirrors): after a
 * `git checkout <rollback_sha>` to a commit that predates bridge.yml, the
 * repo file is absent, so resolution correctly falls through to UI steps.
 *
 * `App::$deploy_steps` deliberately has NO Eloquent array cast (see
 * app/Models/App.php and docs/porting-notes.md B5) — this service does its
 * own json_decode() with explicit json_last_error()/JsonException handling
 * so malformed JSON throws `post-deploy: deploy_steps JSON parse error`
 * exactly as the reference (and deploySteps.test.ts:120) requires. Under an
 * `array` cast this branch would be unreachable.
 */
final class DeploySteps
{
    /**
     * @return array{steps: list<array{service: string, run: string}>, source: 'repo'|'ui'|'none'}
     */
    public static function resolve(App $app): array
    {
        $bridgeYmlPath = rtrim($app->path, '/').'/bridge.yml';

        if (file_exists($bridgeYmlPath)) {
            $raw = @file_get_contents($bridgeYmlPath);
            if ($raw === false) {
                $err = error_get_last();
                throw new RuntimeException('post-deploy: failed to read bridge.yml: '.($err['message'] ?? 'unknown error'));
            }

            try {
                $doc = Yaml::parse($raw);
            } catch (Throwable $e) {
                throw new RuntimeException('post-deploy: bridge.yml parse error: '.$e->getMessage());
            }

            if (! is_array($doc)) {
                throw new RuntimeException('post-deploy: bridge.yml must be a YAML mapping');
            }

            $postDeploy = $doc['post_deploy'] ?? null;
            if (! is_array($postDeploy) || ! array_is_list($postDeploy)) {
                throw new RuntimeException('post-deploy: bridge.yml must have a post_deploy array');
            }

            return ['steps' => self::validateSteps($postDeploy, 'bridge.yml'), 'source' => 'repo'];
        }

        if ($app->deploy_steps) {
            try {
                $parsed = json_decode($app->deploy_steps, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException('post-deploy: deploy_steps JSON parse error: '.$e->getMessage());
            }

            if (! is_array($parsed) || ! array_is_list($parsed) || count($parsed) === 0) {
                return ['steps' => [], 'source' => 'none'];
            }

            return ['steps' => self::validateSteps($parsed, 'deploy_steps'), 'source' => 'ui'];
        }

        return ['steps' => [], 'source' => 'none'];
    }

    /**
     * @param  list<mixed>  $entries
     * @return list<array{service: string, run: string}>
     */
    private static function validateSteps(array $entries, string $source): array
    {
        $steps = [];

        foreach ($entries as $i => $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException("post-deploy: {$source}[{$i}] must be an object with service and run");
            }

            $service = $entry['service'] ?? null;
            if (! is_string($service) || trim($service) === '') {
                throw new RuntimeException("post-deploy: {$source}[{$i}].service must be a non-empty string");
            }

            $run = $entry['run'] ?? null;
            if (! is_string($run) || trim($run) === '') {
                throw new RuntimeException("post-deploy: {$source}[{$i}].run must be a non-empty string");
            }

            $steps[] = ['service' => trim($service), 'run' => trim($run)];
        }

        return $steps;
    }

    /**
     * Serialize DeployStep[] to the plain-text textarea format (one step per
     * line). Format: `service: command`.
     *
     * @param  list<array{service: string, run: string}>  $steps
     */
    public static function serializeUiSteps(array $steps): string
    {
        return implode("\n", array_map(
            static fn (array $s) => "{$s['service']}: {$s['run']}",
            $steps,
        ));
    }

    /**
     * Parse the edit-form textarea to DeployStep[]. Splits on the FIRST
     * colon only so commands keep theirs (e.g. `php artisan cache:clear`,
     * `rails db:migrate`). Blank lines and lines without a colon are
     * skipped.
     *
     * @return list<array{service: string, run: string}>
     */
    public static function parseUiSteps(string $text): array
    {
        $steps = [];

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $i = strpos($trimmed, ':');
            if ($i === false) {
                continue;
            }
            $service = trim(substr($trimmed, 0, $i));
            $run = trim(substr($trimmed, $i + 1));
            if ($service === '' || $run === '') {
                continue;
            }
            $steps[] = ['service' => $service, 'run' => $run];
        }

        return $steps;
    }
}
