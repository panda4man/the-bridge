<?php

namespace Tests\Feature;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Enums\HealthStatus;
use App\Models\App;
use App\Models\Deployment;
use App\Models\HealthCheck;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * `bridge:import-v1`, against a real SQLite file built from the Express app's
 * own DDL — `git show v1-express:src/db.ts`, `bootstrapSchema()` — rather than
 * from this port's migrations. That is the whole point: an assertion against a
 * schema we generated ourselves would prove nothing about the file an operator
 * actually has.
 */
class ImportV1DatabaseTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourcePath = tempnam(sys_get_temp_dir(), 'bridge-v1-').'.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->sourcePath);

        parent::tearDown();
    }

    /**
     * The reference's schema verbatim, including the best-effort ALTERs it
     * applies on every open and the hand-rolled `jobs` table that is the reason
     * `php artisan migrate` cannot simply be pointed at one of these files.
     */
    private function makeV1Database(): PDO
    {
        $db = new PDO('sqlite:'.$this->sourcePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $db->exec(<<<'SQL'
            CREATE TABLE apps (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              name       TEXT NOT NULL,
              repo_url   TEXT NOT NULL,
              branch     TEXT NOT NULL DEFAULT 'main',
              path       TEXT NOT NULL,
              status     TEXT NOT NULL DEFAULT 'idle',
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE deployments (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              app_id      INTEGER NOT NULL REFERENCES apps(id) ON DELETE CASCADE,
              status      TEXT NOT NULL DEFAULT 'pending',
              log         TEXT,
              started_at  TEXT,
              finished_at TEXT,
              created_at  TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE jobs (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              queue        TEXT    NOT NULL DEFAULT 'default',
              payload      TEXT    NOT NULL,
              attempts     INTEGER NOT NULL DEFAULT 0,
              reserved_at  INTEGER,
              available_at INTEGER NOT NULL DEFAULT (unixepoch()),
              created_at   INTEGER NOT NULL DEFAULT (unixepoch())
            );
            CREATE TABLE health_checks (
              id               INTEGER PRIMARY KEY AUTOINCREMENT,
              app_id           INTEGER NOT NULL REFERENCES apps(id) ON DELETE CASCADE,
              status           TEXT NOT NULL DEFAULT 'unknown',
              http_status_code INTEGER,
              response_time_ms INTEGER,
              checked_at       TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);

            ALTER TABLE apps ADD COLUMN health_url TEXT;
            ALTER TABLE apps ADD COLUMN health_check_interval INTEGER NOT NULL DEFAULT 60;
            ALTER TABLE apps ADD COLUMN webhook_secret TEXT;
            ALTER TABLE deployments ADD COLUMN commit_sha TEXT;
            ALTER TABLE deployments ADD COLUMN commit_message TEXT;
            ALTER TABLE deployments ADD COLUMN rollback_sha TEXT;
            ALTER TABLE apps ADD COLUMN deploy_steps TEXT;
        SQL);

        return $db;
    }

    private function seedV1(PDO $db): void
    {
        $db->exec(<<<'SQL'
            INSERT INTO apps (id, name, repo_url, branch, path, status, health_url, health_check_interval, webhook_secret, deploy_steps, created_at, updated_at)
            VALUES
              (1, 'Alpha', 'git@github.com:acme/alpha.git', 'main', '/repos/alpha', 'success', 'http://alpha/health', 120, 'abc123', '[{"service":"web","run":"rails db:migrate"}]', '2026-01-02 03:04:05', '2026-02-03 04:05:06'),
              (7, 'Beta',  'git@github.com:acme/beta.git',  'develop', '/repos/beta', 'idle', NULL, 60, NULL, NULL, '2026-01-09 10:11:12', '2026-01-09 10:11:12');

            INSERT INTO deployments (id, app_id, status, log, started_at, finished_at, commit_sha, commit_message, rollback_sha, created_at, updated_at)
            VALUES
              (4, 1, 'success', 'built ok', '2026-02-03 04:00:00', '2026-02-03 04:05:06', 'abcdef1234567890', 'ship it', NULL, '2026-02-03 04:00:00', '2026-02-03 04:05:06'),
              (5, 7, 'failed',  'boom',     '2026-02-04 04:00:00', '2026-02-04 04:01:00', NULL, NULL, NULL, '2026-02-04 04:00:00', '2026-02-04 04:01:00');

            INSERT INTO health_checks (id, app_id, status, http_status_code, response_time_ms, checked_at)
            VALUES (9, 1, 'up', 200, 42, '2026-02-05 06:07:08');

            INSERT INTO settings (key, value) VALUES ('slack_webhook_url', 'https://hooks.slack.test/v1'), ('api_token', 'v1-token');

            INSERT INTO jobs (queue, payload) VALUES ('default', '{"deploymentId":4}');
        SQL);
    }

    public function test_it_imports_the_four_tables_preserving_ids_and_relationships(): void
    {
        $this->seedV1($this->makeV1Database());

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])->assertExitCode(0);

        $this->assertSame(2, App::query()->count());
        $this->assertSame(2, Deployment::query()->count());
        $this->assertSame(1, HealthCheck::query()->count());

        // Ids preserved — this is what keeps deployments pointing at the right
        // app when the source has gaps in its id sequence.
        $beta = App::query()->findOrFail(7);
        $this->assertSame('Beta', $beta->name);
        $this->assertSame('develop', $beta->branch);

        $deployment = Deployment::query()->findOrFail(4);
        $this->assertSame(1, $deployment->app_id);
        $this->assertSame('Alpha', $deployment->app->name);
        $this->assertSame('abcdef1234567890', $deployment->commit_sha);
        $this->assertSame('built ok', $deployment->log);

        $this->assertSame('https://hooks.slack.test/v1', Setting::getValue('slack_webhook_url'));
        $this->assertSame('v1-token', Setting::getValue('api_token'));
    }

    public function test_imported_rows_read_back_through_the_models_including_enums_and_timestamps(): void
    {
        $this->seedV1($this->makeV1Database());

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])->assertExitCode(0);

        $app = App::query()->findOrFail(1);

        // The status strings are the backed enums' values, so casting is a
        // no-op — but only an actual read proves it.
        $this->assertSame(AppStatus::Success, $app->status);
        $this->assertSame(120, $app->health_check_interval);
        $this->assertSame('2026-01-02 03:04:05', $app->created_at->format('Y-m-d H:i:s'));

        $this->assertSame(DeploymentStatus::Failed, Deployment::query()->findOrFail(5)->status);
        $this->assertSame(HealthStatus::Up, HealthCheck::query()->findOrFail(9)->status);
    }

    public function test_deploy_steps_arrives_as_the_raw_json_string_it_was(): void
    {
        $db = $this->makeV1Database();
        $this->seedV1($db);
        // Malformed on purpose. The reference aborts a deploy with a parse
        // error on this, and DeploySteps here does the same — which it can only
        // do if the import does not "helpfully" normalise or drop it.
        $db->exec("UPDATE apps SET deploy_steps = '{not json' WHERE id = 7");

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])->assertExitCode(0);

        $this->assertSame(
            '[{"service":"web","run":"rails db:migrate"}]',
            App::query()->findOrFail(1)->deploy_steps,
        );
        $this->assertSame('{not json', App::query()->findOrFail(7)->deploy_steps);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->seedV1($this->makeV1Database());

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(0, App::query()->count());
        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame(0, Setting::query()->count());
    }

    public function test_it_refuses_a_source_with_duplicate_app_paths(): void
    {
        $db = $this->makeV1Database();
        $this->seedV1($db);
        // Legal in the reference, which enforced uniqueness only in application
        // code; illegal here, where apps.path carries a unique index.
        $db->exec("UPDATE apps SET path = '/repos/alpha' WHERE id = 7");

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])
            ->expectsOutputToContain('Duplicate apps.path')
            ->assertExitCode(1);

        $this->assertSame(0, App::query()->count());
    }

    public function test_it_refuses_a_status_value_no_enum_covers(): void
    {
        $db = $this->makeV1Database();
        $this->seedV1($db);
        $db->exec("UPDATE deployments SET status = 'cancelled' WHERE id = 5");

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])
            ->expectsOutputToContain("status='cancelled'")
            ->assertExitCode(1);

        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_it_refuses_to_import_into_a_database_that_already_has_apps(): void
    {
        $this->seedV1($this->makeV1Database());

        App::factory()->create(['path' => '/repos/existing']);

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])
            ->expectsOutputToContain('already has rows in apps')
            ->assertExitCode(1);

        // Nothing half-written: the pre-existing row is still the only one.
        $this->assertSame(1, App::query()->count());
    }

    public function test_an_existing_setting_is_kept_unless_overwriting_is_asked_for(): void
    {
        $this->seedV1($this->makeV1Database());

        // The operator has already configured a token here and handed it to
        // bridge-mcp; restoring the v1 value would break that client.
        Setting::setValue('api_token', 'current-token');

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])->assertExitCode(0);

        $this->assertSame('current-token', Setting::getValue('api_token'));
        $this->assertSame('https://hooks.slack.test/v1', Setting::getValue('slack_webhook_url'));

        DB::table('apps')->delete();
        DB::table('settings')->where('key', 'slack_webhook_url')->delete();

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath, '--overwrite-settings' => true])
            ->assertExitCode(0);

        $this->assertSame('v1-token', Setting::getValue('api_token'));
    }

    public function test_an_older_source_without_health_checks_or_settings_still_imports(): void
    {
        // The shape of the bridge.db in this repository's root: an early build
        // that predates both tables. Those are optional; apps and deployments
        // are not.
        $db = $this->makeV1Database();
        $this->seedV1($db);
        $db->exec('DROP TABLE health_checks');
        $db->exec('DROP TABLE settings');

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])
            ->expectsOutputToContain('no health_checks table')
            ->expectsOutputToContain('no settings table')
            ->assertExitCode(0);

        $this->assertSame(2, App::query()->count());
        $this->assertSame(2, Deployment::query()->count());
        $this->assertSame(0, HealthCheck::query()->count());
    }

    public function test_iso_8601_timestamps_are_rewritten_to_one_on_disk_format(): void
    {
        // Production writes these: rows inserted from JavaScript carry
        // 2026-07-30T12:37:09.899Z rather than SQLite's 2026-07-30 12:37:09.
        // Eloquent READS both — Carbon falls back — so this is asserted on the
        // stored value, which is where it actually matters.
        $db = $this->makeV1Database();
        $this->seedV1($db);
        $db->exec("UPDATE deployments SET created_at = '2026-07-30T12:37:09.899Z', finished_at = '2026-07-30T12:40:00.000Z' WHERE id = 4");
        $db->exec("UPDATE apps SET created_at = '2026-05-18T01:29:59.391Z' WHERE id = 1");

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])
            ->expectsOutputToContain('ISO-8601 timestamp')
            ->assertExitCode(0);

        $this->assertSame('2026-07-30 12:37:09', DB::table('deployments')->where('id', 4)->value('created_at'));
        $this->assertSame('2026-07-30 12:40:00', DB::table('deployments')->where('id', 4)->value('finished_at'));
        $this->assertSame('2026-05-18 01:29:59', DB::table('apps')->where('id', 1)->value('created_at'));
    }

    public function test_imported_rows_sort_chronologically_against_natively_written_ones(): void
    {
        // The defect the format mismatch actually causes. SQLite compares these
        // as strings and 'T' (0x54) sorts after ' ' (0x20), so an imported row
        // three minutes EARLIER would otherwise sort as later — on a column the
        // deployments table lets you sort by.
        $db = $this->makeV1Database();
        $this->seedV1($db);
        $db->exec("UPDATE deployments SET created_at = '2026-07-30T12:37:09.899Z' WHERE id = 4");
        $db->exec("UPDATE deployments SET created_at = '2026-07-30T12:50:00.000Z' WHERE id = 5");

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])->assertExitCode(0);

        // A row in the format this application itself stores (Eloquent's
        // Y-m-d H:i:s), timestamped between the two imported ones.
        $native = Deployment::query()->create([
            'app_id' => 1,
            'status' => DeploymentStatus::Success,
        ]);
        DB::table('deployments')->where('id', $native->id)->update(['created_at' => '2026-07-30 12:40:00']);

        $order = DB::table('deployments')->orderBy('created_at')->pluck('created_at')->all();

        $this->assertSame([
            '2026-07-30 12:37:09',
            '2026-07-30 12:40:00',
            '2026-07-30 12:50:00',
        ], $order);
    }

    public function test_a_relative_app_path_is_warned_about_rather_than_imported_silently(): void
    {
        $db = $this->makeV1Database();
        $this->seedV1($db);
        $db->exec("UPDATE apps SET path = 'repos/yt-dlp-dashboard' WHERE id = 7");

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])
            ->expectsOutputToContain('relative path')
            ->assertExitCode(0);

        $this->assertSame('repos/yt-dlp-dashboard', App::query()->findOrFail(7)->path);
    }

    public function test_it_refuses_a_file_that_is_not_a_bridge_database(): void
    {
        $db = new PDO('sqlite:'.$this->sourcePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE something_else (id INTEGER PRIMARY KEY)');

        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath])
            ->expectsOutputToContain('does not look like a Bridge database at all')
            ->assertExitCode(1);
    }

    public function test_it_refuses_a_path_that_does_not_exist(): void
    {
        $this->artisan('bridge:import-v1', ['--from' => '/nonexistent/bridge.db'])
            ->expectsOutputToContain('No such file')
            ->assertExitCode(1);
    }

    public function test_it_reports_how_old_the_source_is(): void
    {
        $this->seedV1($this->makeV1Database());

        // Row counts alone cannot tell an operator they are importing a stale
        // scratch copy; the newest timestamp in each table can.
        $this->artisan('bridge:import-v1', ['--from' => $this->sourcePath, '--dry-run' => true])
            ->expectsOutputToContain('newest created_at: 2026-02-04 04:00:00')
            ->expectsOutputToContain('newest checked_at: 2026-02-05 06:07:08')
            ->assertExitCode(0);
    }
}
