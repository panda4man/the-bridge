<?php

namespace App\Console\Commands;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Enums\HealthStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

/**
 * One-shot import of the Express application's SQLite database (`bridge.db`)
 * into this one, for an operator upgrading in place rather than re-registering
 * their apps by hand.
 *
 * This exists because the two databases hold the same DATA but are not the same
 * DATABASE. `apps`, `deployments`, `health_checks` and `settings` match
 * column-for-column — the reference's best-effort `ALTER TABLE ... ADD COLUMN`
 * list is folded into this port's create migrations, the status strings are
 * exactly the backed enums' values, and SQLite's `datetime('now')` format is
 * what Eloquent already expects. What does NOT transfer is everything around
 * them: the reference has no `users`, `sessions`, `cache` or `migrations`
 * table, and it DOES have a hand-rolled `jobs` table that collides with
 * Laravel's own. So `php artisan migrate` cannot be pointed at an old file —
 * it fails on `create_jobs_table` before it reaches anything interesting.
 * Migrate a fresh database, then run this.
 *
 * Everything is checked before anything is written, and the write itself is one
 * transaction. Row ids are preserved, which is what keeps `deployments.app_id`
 * and `health_checks.app_id` pointing at the right apps — and is also why the
 * target's tables must be empty.
 */
class ImportV1Database extends Command
{
    protected $signature = 'bridge:import-v1
        {--from=bridge.db : Path to the Express SQLite database}
        {--dry-run : Report what would be imported and write nothing}
        {--overwrite-settings : Replace settings rows that already exist here}';

    protected $description = 'Import apps, deployments, health checks and settings from the Express (v1) database';

    /** The tables carried over, in foreign-key-safe order. */
    private const TABLES = ['apps', 'deployments', 'health_checks', 'settings'];

    /**
     * Without these there is nothing to import. `health_checks` and `settings`
     * are deliberately NOT here: the reference grew them later, and its
     * `bootstrapSchema()` only adds a table when the app next opens the file,
     * so a database last touched by an older build genuinely lacks them. That
     * is an old Bridge database, not a foreign one, and refusing it would be
     * wrong. Verified against a real one — `bridge.db` in this repository has
     * exactly `apps`, `deployments` and `jobs`.
     */
    private const REQUIRED_TABLES = ['apps', 'deployments'];

    /**
     * Columns Eloquent will parse as dates. The reference wrote most of them
     * with SQLite's `datetime('now')`, which is already `Y-m-d H:i:s` — but
     * rows inserted from JavaScript carry ISO-8601 instead
     * (`2026-05-18T01:29:59.391Z`), and the model would fail to parse those.
     * Normalised on the way in.
     *
     * @var array<string, list<string>>
     */
    private const DATE_COLUMNS = [
        'apps' => ['created_at', 'updated_at'],
        'deployments' => ['started_at', 'finished_at', 'created_at', 'updated_at'],
        'health_checks' => ['checked_at'],
        'settings' => [],
    ];

    /**
     * Column => enum, for the three status columns. Validated before the
     * import, because an unmapped value is only discovered later as a
     * ValueError deep inside a model read, long after the import "succeeded".
     *
     * @var array<string, array{string, class-string}>
     */
    private const STATUS_COLUMNS = [
        'apps' => ['status', AppStatus::class],
        'deployments' => ['status', DeploymentStatus::class],
        'health_checks' => ['status', HealthStatus::class],
    ];

    public function handle(): int
    {
        $path = (string) $this->option('from');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");
            $this->line('The Express database is usually bridge.db in the repository root.');

            return self::FAILURE;
        }

        try {
            $source = new PDO('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Throwable $e) {
            $this->error("Cannot open {$path} as a SQLite database: {$e->getMessage()}");

            return self::FAILURE;
        }

        $rows = $this->read($source);

        if ($rows === null) {
            return self::FAILURE;
        }

        // Reported before the pre-flight refuses anything: a `--dry-run`
        // against a database that already has apps is exactly how someone
        // inspects a `bridge.db` of unknown vintage, and refusing before
        // saying what is in the file makes that impossible.
        $this->report($path, $rows);

        if (! $this->preflight($rows)) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $this->write($rows);

        $this->info('Imported. The deploy key, .env files and checkouts under the repos directory are NOT part of this — '
            .'point BRIDGE_REPOS_PATH at the same directory the Express instance used, since apps.path holds absolute paths.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, list<array<string, mixed>>>|null
     */
    private function read(PDO $source): ?array
    {
        $present = array_column(
            $source->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(),
            'name',
        );

        $missing = array_diff(self::REQUIRED_TABLES, $present);

        if ($missing !== []) {
            $this->error('That database is missing '.implode(', ', $missing).'.');
            $this->line('It does not look like a Bridge database at all.');

            return null;
        }

        $rows = [];

        foreach (self::TABLES as $table) {
            if (! in_array($table, $present, true)) {
                // An older Bridge build that never created this table. Not an
                // error — there is simply nothing of that kind to carry over.
                $this->warn("Source has no {$table} table; skipping it.");
                $rows[$table] = [];

                continue;
            }

            // Ordered by primary key so the import is deterministic and a
            // re-run against the same file produces the same thing.
            $order = $table === 'settings' ? 'key' : 'id';
            $fetched = $source->query("SELECT * FROM {$table} ORDER BY {$order}")->fetchAll();

            // Projected onto THIS schema's columns. A v1 database that picked
            // up a column this port does not have would otherwise fail the
            // insert with an unhelpful SQL error halfway through.
            $keep = Schema::getColumnListing($table);
            $dropped = array_diff(array_keys($fetched[0] ?? []), $keep);

            foreach ($dropped as $column) {
                $this->warn("{$table}.{$column} exists in the source and not here; not imported.");
            }

            $rows[$table] = array_map(
                fn (array $row): array => $this->normaliseDates(
                    $table,
                    array_intersect_key($row, array_flip($keep)),
                ),
                $fetched,
            );
        }

        if ($this->normalisedDates > 0) {
            $this->warn("Rewrote {$this->normalisedDates} ISO-8601 timestamp(s) to SQLite's `Y-m-d H:i:s`. "
                .'The reference wrote both formats depending on whether SQLite or JavaScript produced the value. '
                .'Both are readable, but SQLite compares them as strings, so mixing them misorders rows within a day.');
        }

        return $rows;
    }

    /** Count of values rewritten from ISO-8601, reported once rather than per row. */
    private int $normalisedDates = 0;

    /**
     * SQLite has no date type, so both formats sit happily in the same column:
     * `datetime('now')` produces `2026-07-30 12:37:09`, while a value inserted
     * from JavaScript arrives as `2026-07-30T12:37:09.899Z`. The production v1
     * database is full of the second kind.
     *
     * **Eloquent reads both** — Carbon's parser falls back and handles the ISO
     * form fine, so this is not about unreadable data. The problem is one level
     * down, in SQL: SQLite compares these as STRINGS, and `'T'` (0x54) sorts
     * after `' '` (0x20). So `2026-07-30T12:37:09.899Z` compares as GREATER
     * than `2026-07-30 12:40:00` — three minutes earlier. A deployments table
     * sorted on `created_at` (a sortable column in the panel) silently
     * interleaves imported rows with native ones, and any `where created_at >`
     * range does the same.
     *
     * Normalising on the way in means one format on disk, which is what every
     * row this application writes itself already uses.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseDates(string $table, array $row): array
    {
        foreach (self::DATE_COLUMNS[$table] as $column) {
            $value = $row[$column] ?? null;

            if (! is_string($value) || $value === '' || ! str_contains($value, 'T')) {
                continue;
            }

            try {
                // Stored as UTC by both writers; the reference has no timezone
                // handling anywhere, and this port's `timezone` is UTC too.
                $row[$column] = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
                $this->normalisedDates++;
            } catch (Throwable) {
                // Left alone rather than guessed at: an unparseable timestamp
                // is surfaced by the pre-flight, not silently zeroed.
            }
        }

        return $row;
    }

    /**
     * What is about to be imported, and how OLD it is.
     *
     * A `bridge.db` sitting in a repository root is quite likely to be a stale
     * copy — a laptop's scratch database rather than the instance that was
     * actually serving deploys. Row counts alone do not show that, so the
     * newest timestamp in each table is printed next to them: an operator who
     * sees a last deployment from months ago is looking at the wrong file, and
     * that is much easier to notice here than after the import.
     *
     * @param  array<string, list<array<string, mixed>>>  $rows
     */
    private function report(string $path, array $rows): void
    {
        $this->line("Source: {$path} (".number_format((int) filesize($path)).' bytes, modified '
            .date('Y-m-d H:i', (int) filemtime($path)).')');

        foreach (self::TABLES as $table) {
            $column = match ($table) {
                'deployments', 'apps' => 'created_at',
                'health_checks' => 'checked_at',
                default => null,
            };

            $latest = $column === null
                ? null
                : max(array_column($rows[$table], $column) ?: [null]);

            $this->line(sprintf(
                '  %-14s %5d row(s)%s',
                $table,
                count($rows[$table]),
                $latest === null ? '' : "   newest {$column}: {$latest}",
            ));
        }
    }

    /**
     * Every reason to refuse, reported together rather than one per run.
     *
     * @param  array<string, list<array<string, mixed>>>  $rows
     */
    private function preflight(array $rows): bool
    {
        $problems = [];

        // apps.path is UNIQUE here and was only enforced in application code in
        // the reference, so an old database can genuinely hold duplicates.
        $paths = array_column($rows['apps'], 'path');
        $duplicates = array_keys(array_filter(array_count_values($paths), fn (int $n): bool => $n > 1));

        foreach ($duplicates as $path) {
            $problems[] = "Duplicate apps.path: {$path} — this port has a unique index on it. "
                .'Remove or repoint the duplicates in the source database first.';
        }

        // Not fatal, and not silent. apps.path is resolved by the queue worker
        // inside the container and by anything an operator runs on the host, so
        // it has to be absolute; a relative value deploys into whatever the
        // working directory happens to be. Old databases do contain these.
        foreach ($rows['apps'] as $app) {
            if (! str_starts_with((string) $app['path'], '/')) {
                $this->warn("apps id {$app['id']} has a relative path ({$app['path']}). "
                    .'Fix it in the panel after importing — deploys resolve it against the process working directory.');
            }
        }

        foreach (self::STATUS_COLUMNS as $table => [$column, $enum]) {
            $valid = array_column($enum::cases(), 'value');

            foreach ($rows[$table] as $row) {
                if (! in_array($row[$column], $valid, true)) {
                    $problems[] = sprintf(
                        '%s id %s has %s=%s, which is not one of: %s.',
                        $table,
                        $row['id'] ?? '?',
                        $column,
                        var_export($row[$column], true),
                        implode(', ', $valid),
                    );
                }
            }
        }

        // Ids are preserved, so anything already here would collide — and a
        // half-imported database is worse than a refused import.
        foreach (['apps', 'deployments', 'health_checks'] as $table) {
            if (DB::table($table)->exists()) {
                $problems[] = "This database already has rows in {$table}. Import into a freshly migrated database: "
                    .'ids are preserved so that deployments and health checks keep pointing at the right apps.';
            }
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return $problems === [];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows
     */
    private function write(array $rows): void
    {
        DB::transaction(function () use ($rows): void {
            // Insert order matters: SQLite foreign keys are ON (see
            // config/database.php), so apps must exist before the two tables
            // that reference them.
            foreach (['apps', 'deployments', 'health_checks'] as $table) {
                foreach (array_chunk($rows[$table], 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }

            foreach ($rows['settings'] as $setting) {
                $exists = DB::table('settings')->where('key', $setting['key'])->exists();

                if ($exists && ! $this->option('overwrite-settings')) {
                    // Kept rather than clobbered: `api_token` and
                    // `slack_webhook_url` live here, and silently replacing a
                    // token the operator has already configured — and handed to
                    // bridge-mcp — would break a working client to restore an
                    // older value.
                    $this->warn("settings.{$setting['key']} already set here; kept. Use --overwrite-settings to replace it.");

                    continue;
                }

                DB::table('settings')->updateOrInsert(['key' => $setting['key']], ['value' => $setting['value']]);
            }
        });
    }
}
