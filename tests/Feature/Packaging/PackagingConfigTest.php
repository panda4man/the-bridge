<?php

namespace Tests\Feature\Packaging;

use App\Jobs\DeployApp;
use App\Jobs\PollHealthChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the container's process topology against the application code it is
 * meant to run.
 *
 * Every assertion here guards a failure that is SILENT — nothing throws, no
 * log line appears, and the panel looks healthy while some part of the system
 * quietly does nothing:
 *
 *   - a `--queue` that does not match a job's queue name enqueues work forever
 *     and runs none of it;
 *   - a second worker on `default` duplicates any deploy that outlives
 *     retry_after;
 *   - a retried PollHealthChecks forks the self-rescheduling chain in two;
 *   - a missing entrypoint step (migrations, stuck-deployment reset, the
 *     health-chain kickoff) is only visible as an absence.
 *
 * These are config files, not code, so nothing else in the suite would ever
 * touch them. Reading them from disk is the point.
 */
class PackagingConfigTest extends TestCase
{
    private const DOCKER_DIR = __DIR__.'/../../../docker';

    private function supervisord(): string
    {
        return $this->readOrFail(self::DOCKER_DIR.'/supervisord.conf');
    }

    private function entrypoint(): string
    {
        return $this->readOrFail(self::DOCKER_DIR.'/entrypoint.sh');
    }

    private function dockerfile(): string
    {
        return $this->readOrFail(self::DOCKER_DIR.'/Dockerfile');
    }

    private function compose(): string
    {
        return $this->readOrFail(self::DOCKER_DIR.'/../docker-compose.yml');
    }

    private function readOrFail(string $path): string
    {
        $contents = @file_get_contents($path);

        $this->assertIsString($contents, "Missing packaging file: {$path}");

        return $contents;
    }

    /**
     * Every `command=` line in supervisord.conf that runs `queue:work`, as
     * [program name => command].
     *
     * @return array<string, string>
     */
    private function queueWorkerPrograms(): array
    {
        preg_match_all(
            '/^\[program:([^\]]+)\](.*?)(?=^\[|\z)/ms',
            $this->supervisord(),
            $blocks,
            PREG_SET_ORDER,
        );

        $workers = [];

        foreach ($blocks as [, $name, $body]) {
            if (preg_match('/^command=(.*queue:work.*)$/m', $body, $command)) {
                $workers[$name] = trim($command[1]);
            }
        }

        return $workers;
    }

    public function test_the_health_worker_serves_exactly_the_queue_the_health_job_is_dispatched_onto(): void
    {
        // Not a string literal comparison against 'health': the point is that
        // the two sides agree, whatever the name is.
        $jobQueue = (new PollHealthChecks)->queue;

        $this->assertSame(PollHealthChecks::QUEUE, $jobQueue);

        $matching = array_filter(
            $this->queueWorkerPrograms(),
            fn (string $command) => str_contains($command, "--queue={$jobQueue}"),
        );

        $this->assertCount(
            1,
            $matching,
            "Exactly one supervisord program must serve the '{$jobQueue}' queue. "
            .'A mismatch here stops ALL health polling without any error.',
        );
    }

    public function test_the_health_worker_never_retries_a_tick(): void
    {
        $command = $this->queueWorkerPrograms()['health-worker'] ?? null;

        $this->assertNotNull($command, 'supervisord.conf has no [program:health-worker].');

        $this->assertStringContainsString(
            '--tries=1',
            $command,
            'PollHealthChecks re-dispatches itself from a finally block, so a failed tick '
            .'has already scheduled its successor. Retrying it forks the chain in two.',
        );
    }

    public function test_the_deploy_job_is_served_by_exactly_one_worker(): void
    {
        // DeployApp names no queue, so it lands on the connection's default.
        $this->assertNull(
            (new DeployApp(1))->queue,
            'DeployApp must stay on the connection default queue; see its class docblock.',
        );

        $deployQueue = config('queue.connections.database.queue');

        $this->assertSame('default', $deployQueue);

        $matching = array_filter(
            $this->queueWorkerPrograms(),
            fn (string $command) => str_contains($command, "--queue={$deployQueue}"),
        );

        $this->assertCount(
            1,
            $matching,
            "Exactly one supervisord program may serve the '{$deployQueue}' queue.",
        );
    }

    public function test_no_supervisord_program_runs_more_than_one_process(): void
    {
        // numprocs on the deploy worker is the same duplicate-deploy hazard as
        // a second [program:] block, in a form that is easy to add casually.
        $this->assertDoesNotMatchRegularExpression(
            '/^numprocs=(?!1$)/m',
            $this->supervisord(),
            'Deploy concurrency must stay at 1 while config/queue.php retry_after is set.',
        );
    }

    public function test_retry_after_is_still_the_value_the_single_worker_assumption_was_made_against(): void
    {
        // Not a correctness constraint on its own — a reminder that this number
        // and the worker count in supervisord.conf are a pair. Raising the
        // worker count without raising this duplicates long deploys.
        $this->assertSame(90, config('queue.connections.database.retry_after'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function entrypointObligations(): array
    {
        return [
            'migrations' => ['artisan migrate --force'],
            'admin seeding' => ['artisan db:seed --force'],
            'stuck deployment reset' => ['artisan bridge:reset-stuck-deployments'],
            'health chain kickoff' => ['artisan bridge:poll-health'],
        ];
    }

    #[DataProvider('entrypointObligations')]
    public function test_the_entrypoint_performs_each_boot_obligation_exactly_once(string $command): void
    {
        $occurrences = substr_count($this->entrypoint(), $command);

        $this->assertSame(
            1,
            $occurrences,
            "docker/entrypoint.sh must run `{$command}` exactly once per container start, "
            .'not zero times and not twice. bridge:poll-health in particular has no '
            .'duplicate-kickoff guard by design.',
        );
    }

    public function test_the_health_chain_is_started_by_the_entrypoint_and_not_by_supervisord(): void
    {
        // supervisord restarts a crashed program. If the kickoff lived in a
        // [program:] block, every restart would start another forever-ticking
        // chain — the exact thing PollHealth's docblock says must not happen.
        $this->assertStringNotContainsString(
            'bridge:poll-health',
            $this->supervisord(),
            'The health chain kickoff belongs in the entrypoint, which runs once per '
            .'container start, not in supervisord, which re-runs on every restart.',
        );
    }

    public function test_the_entrypoint_hands_off_to_supervisord_with_exec(): void
    {
        // Without exec, supervisord is not PID 1: it never receives the SIGTERM
        // from `docker stop`, and the graceful shutdown that lets an in-flight
        // deploy finish never happens.
        $this->assertMatchesRegularExpression(
            '/^exec .*supervisord/m',
            $this->entrypoint(),
        );
    }

    public function test_the_image_installs_pcntl(): void
    {
        // Without pcntl, queue:work cannot trap SIGTERM and a container stop
        // abandons an in-flight deploy — the Express defect this port fixes.
        $this->assertMatchesRegularExpression(
            '/install-php-extensions(?:[^\n]|\\\\\n)*\bpcntl\b/',
            $this->dockerfile(),
        );
    }

    public function test_the_deploy_worker_is_given_time_to_finish_a_build_on_shutdown(): void
    {
        preg_match(
            '/^\[program:deploy-worker\](.*?)(?=^\[|\z)/ms',
            $this->supervisord(),
            $block,
        );

        $this->assertNotEmpty($block, 'supervisord.conf has no [program:deploy-worker].');

        preg_match('/^stopwaitsecs=(\d+)$/m', $block[1], $wait);

        $this->assertNotEmpty($wait, 'deploy-worker must set stopwaitsecs explicitly.');
        $this->assertGreaterThanOrEqual(
            600,
            (int) $wait[1],
            'supervisord SIGKILLs after stopwaitsecs. A short value abandons long builds, '
            .'which is the behaviour queue:work was adopted to fix.',
        );

        // queue:work's children ARE the deploy. Signalling the process group
        // kills `docker compose up --build` instead of letting it complete.
        $this->assertStringNotContainsString('stopasgroup=true', $block[1]);
        $this->assertStringNotContainsString('killasgroup=true', $block[1]);
    }

    public function test_the_repos_volume_is_mounted_at_the_same_absolute_path_on_both_sides(): void
    {
        // apps.path stores absolute paths that must resolve identically inside
        // and outside the container. A conventional ./repos:/repos mapping
        // breaks every stored path.
        $this->assertMatchesRegularExpression(
            '/- \$\{BRIDGE_REPOS_PATH[^}]*\}:\$\{BRIDGE_REPOS_PATH\}/',
            $this->compose(),
        );
    }

    public function test_the_container_never_runs_with_debug_enabled(): void
    {
        // A debug-mode panel that can deploy arbitrary code renders stack
        // traces containing its own configuration to anyone who reaches a 500.
        $compose = $this->compose();

        $this->assertMatchesRegularExpression('/APP_ENV:\s*production/', $compose);
        $this->assertMatchesRegularExpression('/APP_DEBUG:\s*"false"/', $compose);

        // A developer .env carries APP_ENV=local and APP_DEBUG=true, and
        // env_file would inject both. Matched as a directive, not as a word,
        // so the comment explaining this does not trip its own assertion.
        $this->assertDoesNotMatchRegularExpression('/^\s*env_file\s*:/m', $compose);
    }
}
