<?php

namespace Tests\Feature\Packaging;

use App\Jobs\DeployApp;
use App\Jobs\PollHealthChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
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

    private function dockerignore(): string
    {
        return $this->readOrFail(self::DOCKER_DIR.'/../.dockerignore');
    }

    /**
     * docker-compose.yml as Docker Compose itself sees it. Every assertion that
     * can be made against the parsed tree is made against it rather than
     * against the file's text: a YAML file that no longer parses still
     * satisfies every regex written over its source, and compose refuses to
     * start it.
     *
     * @return array<string, mixed>
     */
    private function composeTree(): array
    {
        $parsed = Yaml::parse($this->compose());

        $this->assertIsArray($parsed, 'docker-compose.yml does not parse as YAML.');
        $this->assertArrayHasKey('bridge', $parsed['services'] ?? [], 'docker-compose.yml has no `bridge` service.');

        return $parsed['services']['bridge'];
    }

    /**
     * supervisord.conf as supervisord's own ini parser sees it. Catches what a
     * regex over the text cannot: a duplicated `[program:...]` header, a
     * `command=` that landed outside its block, a section that never closes.
     *
     * @return array<string, array<string, string>>
     */
    private function supervisordTree(): array
    {
        $parsed = @parse_ini_string($this->supervisord(), true, INI_SCANNER_RAW);

        $this->assertIsArray($parsed, 'supervisord.conf is not parseable ini; supervisord would refuse to start.');

        return $parsed;
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

    public function test_nothing_touches_the_database_backed_cache_before_the_migrations_create_its_table(): void
    {
        // Found by booting the image on an empty /data. CACHE_STORE is
        // `database`, so `cache:clear` is a DELETE against a table that does
        // not exist on a first boot: the command throws, `set -e` stops the
        // entrypoint, and the container restart-loops with "no such table:
        // cache" having never migrated. `optimize:clear` is the trap, because
        // it runs `cache:clear` as one of its five steps and reads as a
        // harmless "discard stale caches".
        $entrypoint = $this->entrypoint();

        // Matched as a command line, not as a word, so the comment in the
        // entrypoint explaining this does not trip its own assertion.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*php artisan optimize:clear/m',
            $entrypoint,
            'optimize:clear runs cache:clear, which needs a table the first boot has not created yet. '
            .'Clear config/route/view/event individually instead.',
        );

        // Read off compose, not config(): phpunit.xml runs the suite with
        // CACHE_STORE=array, where none of this would bite.
        $this->assertSame('database', $this->composeTree()['environment']['CACHE_STORE'] ?? null);

        $migrate = strpos($entrypoint, 'artisan migrate --force');
        $this->assertNotFalse($migrate);

        preg_match_all('/artisan cache:clear/', substr($entrypoint, 0, $migrate), $tooEarly);

        $this->assertEmpty(
            $tooEarly[0],
            'cache:clear must not run before `migrate`; the cache table does not exist yet.',
        );

        // And the config cache MUST still be discarded before migrate, or the
        // migration reads the previous boot's DB_DATABASE.
        $this->assertLessThan(
            $migrate,
            strpos($entrypoint, 'config:clear'),
            'The config cache must be cleared before migrating, or migrations run against stale configuration.',
        );
    }

    public function test_the_previous_containers_health_chain_is_cancelled_before_a_new_one_starts(): void
    {
        $entrypoint = $this->entrypoint();

        $clear = strpos($entrypoint, 'queue:clear --queue='.PollHealthChecks::QUEUE);
        $kickoff = strpos($entrypoint, 'artisan bridge:poll-health');

        // The delayed successor tick is a row in the database queue, and the
        // database is on the /data volume — it outlives the container. Without
        // this the kickoff ADDS a chain rather than starting one, on every
        // single restart, and nothing reports it.
        $this->assertNotFalse($clear, 'The entrypoint must clear the health queue before kicking off a new chain.');
        $this->assertNotFalse($kickoff);
        $this->assertLessThan($kickoff, $clear, 'Clearing must come BEFORE the kickoff, or it deletes the tick it just dispatched.');

        // Deploys live on `default` and a queued one must survive a restart to
        // be picked up; only the self-perpetuating health chain may be cleared.
        $this->assertStringNotContainsString('queue:clear --queue=default', $entrypoint);
        $this->assertDoesNotMatchRegularExpression('/queue:clear(?![^\n]*--queue=)/', $entrypoint);
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
        $volumes = $this->composeTree()['volumes'] ?? [];

        $reposMounts = array_values(array_filter(
            $volumes,
            static fn (string $volume): bool => str_contains($volume, 'BRIDGE_REPOS_PATH'),
        ));

        $this->assertCount(1, $reposMounts, 'docker-compose.yml must mount BRIDGE_REPOS_PATH exactly once.');

        // Split on the LAST colon: the host side carries compose's
        // `${VAR:?message}` form, whose message contains colons of its own.
        $separator = strrpos($reposMounts[0], ':');
        $this->assertNotFalse($separator);

        $host = substr($reposMounts[0], 0, $separator);
        $container = substr($reposMounts[0], $separator + 1);

        $this->assertSame('${BRIDGE_REPOS_PATH}', $container, 'The container side must be the variable itself, not a fixed path.');
        $this->assertStringStartsWith('${BRIDGE_REPOS_PATH', $host, 'The host side must be the same variable.');
    }

    public function test_the_docker_socket_is_mounted_where_the_cli_inside_the_container_looks_for_it(): void
    {
        // The entrypoint and every `docker compose` the worker runs use the
        // in-container default, /var/run/docker.sock. The HOST side is
        // overridable precisely because Docker Desktop for macOS does not put
        // it there — pinning the container side is what makes that override
        // safe.
        $volumes = $this->composeTree()['volumes'] ?? [];

        $socketMounts = array_values(array_filter(
            $volumes,
            static fn (string $volume): bool => str_ends_with($volume, ':/var/run/docker.sock'),
        ));

        $this->assertCount(1, $socketMounts, 'The host Docker socket must be mounted at /var/run/docker.sock inside the container.');
        $this->assertStringContainsString('${DOCKER_SOCK', $socketMounts[0], 'The host side must stay overridable; it is not /var/run/docker.sock on macOS.');
    }

    public function test_docker_stop_waits_longer_than_supervisord_does_for_a_running_deploy(): void
    {
        // The pair nothing else pins. deploy-worker's stopwaitsecs only gets to
        // matter if the CONTAINER survives that long: `docker stop` SIGKILLs
        // the whole container after stop_grace_period, whose default is 10
        // seconds. Lowering it — or deleting it — silently restores the
        // abandoned-in-flight-deploy defect this port replaced queue:work's
        // predecessor to fix, and every existing assertion here still passes.
        $stopWaitSecs = (int) ($this->supervisordTree()['program:deploy-worker']['stopwaitsecs'] ?? 0);

        $this->assertGreaterThan(0, $stopWaitSecs);

        $gracePeriod = $this->composeTree()['stop_grace_period'] ?? null;

        $this->assertNotNull($gracePeriod, 'docker-compose.yml must set stop_grace_period; the 10s default abandons builds.');

        $this->assertGreaterThan(
            $stopWaitSecs,
            $this->durationToSeconds((string) $gracePeriod),
            'stop_grace_period must exceed deploy-worker stopwaitsecs, or the container is killed '
            .'while supervisord is still waiting for the deploy to finish.',
        );
    }

    public function test_supervisord_declares_exactly_the_three_programs_the_image_is_built_around(): void
    {
        // Parsed rather than grepped: a duplicated [program:] header or a
        // command= that slipped outside its block reads fine as text and makes
        // supervisord refuse to start — which the image only discovers at run
        // time, in a container that then restarts forever.
        $programs = array_keys(array_filter(
            $this->supervisordTree(),
            static fn (string $section): bool => str_starts_with($section, 'program:'),
            ARRAY_FILTER_USE_KEY,
        ));

        sort($programs);

        $this->assertSame(
            ['program:deploy-worker', 'program:frankenphp', 'program:health-worker'],
            $programs,
        );
    }

    public function test_the_entrypoint_is_a_syntactically_valid_shell_script(): void
    {
        // The one thing about the entrypoint this suite can actually RUN. Every
        // other assertion in this class reads it as text, which is happy with
        // an unclosed quote or a broken `if` — and the container would then die
        // on its first boot, after the image built cleanly.
        $check = new Process(['sh', '-n', realpath(self::DOCKER_DIR.'/entrypoint.sh')]);
        $check->run();

        $this->assertTrue(
            $check->isSuccessful(),
            "docker/entrypoint.sh is not valid shell:\n".$check->getErrorOutput(),
        );
    }

    public function test_the_image_boots_through_the_entrypoint(): void
    {
        // Every boot obligation asserted above lives in that script and nowhere
        // else. An ENTRYPOINT pointing anywhere else — or a CMD added alongside
        // it — starts supervisord with no migrations, no stuck-deployment
        // reset, and no health chain, all silently.
        $this->assertMatchesRegularExpression(
            '/^ENTRYPOINT \["\/usr\/local\/bin\/entrypoint\.sh"\]$/m',
            $this->dockerfile(),
        );
    }

    public function test_no_host_env_file_is_baked_into_the_image(): void
    {
        // The sibling of the env_file assertion below, through the other door.
        // The vendor stage does `COPY . .`, so a .dockerignore that stops
        // excluding .env bakes the developer's file into the layer. Laravel's
        // Dotenv does not override real environment variables, so compose's
        // APP_ENV/APP_DEBUG would still win — but everything compose does NOT
        // set would come from that file, including BRIDGE_API_TOKEN, which is
        // the difference between an API that fails closed and one that accepts
        // a token the operator never configured.
        $dockerignore = $this->dockerignore();

        $this->assertMatchesRegularExpression('/^\.env$/m', $dockerignore);
        $this->assertMatchesRegularExpression('/^\.env\.\*$/m', $dockerignore);
        $this->assertMatchesRegularExpression('/^!\.env\.example$/m', $dockerignore);
    }

    private function durationToSeconds(string $duration): int
    {
        // Compose's Go duration syntax, restricted to what is usable here.
        preg_match_all('/(\d+)(h|m|s)?/', trim($duration), $parts, PREG_SET_ORDER);

        $this->assertNotEmpty($parts, "Unparseable duration: {$duration}");

        $seconds = 0;

        foreach ($parts as $part) {
            $seconds += (int) $part[1] * match ($part[2] ?? 's') {
                'h' => 3600,
                'm' => 60,
                's' => 1,
            };
        }

        return $seconds;
    }

    public function test_the_container_never_runs_with_debug_enabled(): void
    {
        // A debug-mode panel that can deploy arbitrary code renders stack
        // traces containing its own configuration to anyone who reaches a 500.
        $service = $this->composeTree();

        $this->assertSame('production', $service['environment']['APP_ENV'] ?? null);

        // Read off the parsed tree, so both `"false"` and an unquoted YAML
        // boolean are caught — the unquoted form parses to PHP false, which is
        // not what Laravel's env() casting would see from compose either way.
        $this->assertSame('false', $service['environment']['APP_DEBUG'] ?? null);

        // A developer .env carries APP_ENV=local and APP_DEBUG=true, and
        // env_file would inject both.
        $this->assertArrayNotHasKey('env_file', $service);
    }
}
