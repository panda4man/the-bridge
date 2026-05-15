# The Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build "The Bridge" — a self-hosted Docker deployment platform containerized as a single image that manages multiple git-backed projects and deploys them via docker compose with live log streaming.

**Architecture:** Single Laravel 11 container (Nginx + PHP-FPM + queue worker via Supervisor). Deploy jobs run via Laravel queues with `proc_open` for real-time log capture. Logs stream to browser via Server-Sent Events. Docker CLI + compose plugin bundled in the image; host Docker socket mounted so commands run against the host daemon. Repos volume is mounted at the same path inside and outside the container so Docker daemon resolves bind mounts in target compose files correctly.

**Tech Stack:** PHP 8.3, Laravel 11, Pest, SQLite, Alpine.js, Blade, Tailwind CDN, Docker CLI + compose plugin, Supervisor, Nginx

---

## File Map

| File | Purpose |
|---|---|
| `app/Enums/AppStatus.php` | Enum: idle/deploying/success/failed |
| `app/Enums/DeploymentStatus.php` | Enum: pending/running/success/failed |
| `app/Models/App.php` | App model + deployments relationship |
| `app/Models/Deployment.php` | Deployment model + app relationship |
| `app/Services/GitService.php` | Wraps git clone/pull (injectable for tests) |
| `app/Jobs/DeployApp.php` | Queue job: git pull → compose pull → compose up, appends log chunks |
| `app/Http/Controllers/AppController.php` | CRUD + deploy trigger |
| `app/Http/Controllers/DeploymentController.php` | Show + SSE stream |
| `config/bridge.php` | `REPOS_PATH`, `BRIDGE_SSH_KEY_PATH` |
| `database/migrations/*_create_apps_table.php` | apps schema |
| `database/migrations/*_create_deployments_table.php` | deployments schema |
| `database/factories/AppFactory.php` | Test factory |
| `database/factories/DeploymentFactory.php` | Test factory |
| `resources/views/layouts/app.blade.php` | Base layout (Tailwind CDN + Alpine CDN) |
| `resources/views/apps/{index,create,show,edit}.blade.php` | App CRUD views |
| `resources/views/deployments/show.blade.php` | Live log view with Alpine.js SSE |
| `routes/web.php` | All routes |
| `docker/Dockerfile` | PHP 8.3-fpm-alpine + Docker CLI + compose + Nginx + Supervisor |
| `docker/nginx.conf` | Nginx config with SSE buffering disabled |
| `docker/supervisord.conf` | php-fpm + nginx + queue:work |
| `docker/entrypoint.sh` | Migrate + key:generate + supervisord |
| `docker-compose.yml` | Bridge service definition |
| `.env.example` | `REPOS_PATH`, `APP_KEY`, `BRIDGE_PORT` |

---

### Task 1: Scaffold Laravel project

**Files:**
- Create: `the-bridge/` (Laravel project root, scaffold into existing dir)

- [ ] **Step 1: Scaffold into current directory**

```bash
cd /Users/aclinton-sonar/Dev/Playground/the-bridge
composer create-project laravel/laravel . --prefer-dist
```

- [ ] **Step 2: Configure SQLite as default**

Edit `.env`:
```
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/the-bridge/database/database.sqlite
```

Edit `config/database.php` — confirm `sqlite` connection reads `env('DB_DATABASE', ...)`. No change needed in a fresh install.

- [ ] **Step 3: Create local SQLite file**

```bash
touch database/database.sqlite
```

- [ ] **Step 4: Remove unused defaults**

```bash
rm -f app/Models/User.php
rm -f database/migrations/*_create_users_table.php
rm -f database/migrations/*_create_password_reset_tokens_table.php
rm -f database/migrations/*_create_failed_jobs_table.php
```

- [ ] **Step 5: Create queue tables and configure driver**

```bash
php artisan queue:table
php artisan migrate
```

In `.env`:
```
QUEUE_CONNECTION=database
```

- [ ] **Step 6: Verify app boots**

```bash
php artisan serve
# Open http://localhost:8000 — Laravel welcome page. Ctrl+C.
```

- [ ] **Step 7: Init git and commit**

```bash
git init
git add .
git commit -m "feat: scaffold Laravel project with SQLite and database queue"
```

---

### Task 2: Status enums

**Files:**
- Create: `app/Enums/AppStatus.php`
- Create: `app/Enums/DeploymentStatus.php`
- Create: `tests/Unit/EnumTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/EnumTest.php`:
```php
<?php

test('AppStatus has expected cases', function () {
    expect(App\Enums\AppStatus::cases())->toHaveCount(4);
    expect(App\Enums\AppStatus::Idle->value)->toBe('idle');
    expect(App\Enums\AppStatus::Deploying->value)->toBe('deploying');
    expect(App\Enums\AppStatus::Success->value)->toBe('success');
    expect(App\Enums\AppStatus::Failed->value)->toBe('failed');
});

test('DeploymentStatus has expected cases', function () {
    expect(App\Enums\DeploymentStatus::cases())->toHaveCount(4);
    expect(App\Enums\DeploymentStatus::Pending->value)->toBe('pending');
    expect(App\Enums\DeploymentStatus::Running->value)->toBe('running');
    expect(App\Enums\DeploymentStatus::Success->value)->toBe('success');
    expect(App\Enums\DeploymentStatus::Failed->value)->toBe('failed');
});
```

- [ ] **Step 2: Run test — verify FAIL**

```bash
./vendor/bin/pest tests/Unit/EnumTest.php
# Expected: FAIL — "App\Enums\AppStatus not found"
```

- [ ] **Step 3: Create AppStatus enum**

Create `app/Enums/AppStatus.php`:
```php
<?php

namespace App\Enums;

enum AppStatus: string
{
    case Idle = 'idle';
    case Deploying = 'deploying';
    case Success = 'success';
    case Failed = 'failed';
}
```

- [ ] **Step 4: Create DeploymentStatus enum**

Create `app/Enums/DeploymentStatus.php`:
```php
<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
```

- [ ] **Step 5: Run test — verify PASS**

```bash
./vendor/bin/pest tests/Unit/EnumTest.php
# Expected: PASS
```

- [ ] **Step 6: Commit**

```bash
git add app/Enums/ tests/Unit/EnumTest.php
git commit -m "feat: add AppStatus and DeploymentStatus enums"
```

---

### Task 3: Migrations

**Files:**
- Create: `database/migrations/*_create_apps_table.php`
- Create: `database/migrations/*_create_deployments_table.php`
- Create: `tests/Feature/MigrationTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/MigrationTest.php`:
```php
<?php

use Illuminate\Support\Facades\Schema;

test('apps table has correct columns', function () {
    expect(Schema::hasTable('apps'))->toBeTrue();
    expect(Schema::hasColumns('apps', [
        'id', 'name', 'repo_url', 'branch', 'path', 'status', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('deployments table has correct columns', function () {
    expect(Schema::hasTable('deployments'))->toBeTrue();
    expect(Schema::hasColumns('deployments', [
        'id', 'app_id', 'status', 'log', 'started_at', 'finished_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});
```

- [ ] **Step 2: Run test — verify FAIL**

```bash
./vendor/bin/pest tests/Feature/MigrationTest.php
# Expected: FAIL — tables don't exist
```

- [ ] **Step 3: Create apps migration**

```bash
php artisan make:migration create_apps_table
```

Edit the generated file:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('repo_url');
            $table->string('branch')->default('main');
            $table->string('path');
            $table->string('status')->default('idle');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
```

- [ ] **Step 4: Create deployments migration**

```bash
php artisan make:migration create_deployments_table
```

Edit the generated file:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->longText('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
```

- [ ] **Step 5: Run migrations**

```bash
php artisan migrate
```

- [ ] **Step 6: Run test — verify PASS**

```bash
./vendor/bin/pest tests/Feature/MigrationTest.php
# Expected: PASS
```

- [ ] **Step 7: Commit**

```bash
git add database/migrations/ tests/Feature/MigrationTest.php
git commit -m "feat: add apps and deployments migrations"
```

---

### Task 4: Models and factories

**Files:**
- Create: `app/Models/App.php`
- Create: `app/Models/Deployment.php`
- Create: `database/factories/AppFactory.php`
- Create: `database/factories/DeploymentFactory.php`
- Create: `tests/Unit/ModelTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/ModelTest.php`:
```php
<?php

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;

test('App status casts to AppStatus enum', function () {
    $app = App::factory()->create(['status' => 'idle']);
    expect($app->status)->toBeInstanceOf(AppStatus::class);
    expect($app->status)->toBe(AppStatus::Idle);
});

test('App has many deployments', function () {
    $app = App::factory()->create();
    Deployment::factory()->create(['app_id' => $app->id]);
    expect($app->deployments)->toHaveCount(1);
});

test('Deployment belongs to App', function () {
    $app = App::factory()->create();
    $deployment = Deployment::factory()->create(['app_id' => $app->id]);
    expect($deployment->app->id)->toBe($app->id);
});

test('Deployment status casts to DeploymentStatus enum', function () {
    $deployment = Deployment::factory()->create(['status' => 'pending']);
    expect($deployment->status)->toBeInstanceOf(DeploymentStatus::class);
    expect($deployment->status)->toBe(DeploymentStatus::Pending);
});
```

- [ ] **Step 2: Run test — verify FAIL**

```bash
./vendor/bin/pest tests/Unit/ModelTest.php
# Expected: FAIL — "App\Models\App not found"
```

- [ ] **Step 3: Create AppFactory**

```bash
php artisan make:factory AppFactory --model=App
```

Edit `database/factories/AppFactory.php`:
```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AppFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'      => $this->faker->words(2, true),
            'repo_url'  => 'https://github.com/example/repo.git',
            'branch'    => 'main',
            'path'      => '/tmp/repos/' . $this->faker->slug(),
            'status'    => 'idle',
        ];
    }
}
```

- [ ] **Step 4: Create DeploymentFactory**

```bash
php artisan make:factory DeploymentFactory --model=Deployment
```

Edit `database/factories/DeploymentFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\App;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeploymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_id'      => App::factory(),
            'status'      => 'pending',
            'log'         => null,
            'started_at'  => null,
            'finished_at' => null,
        ];
    }
}
```

- [ ] **Step 5: Create App model**

Create `app/Models/App.php`:
```php
<?php

namespace App\Models;

use App\Enums\AppStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class App extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'repo_url', 'branch', 'path', 'status'];

    protected $casts = [
        'status' => AppStatus::class,
    ];

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
```

- [ ] **Step 6: Create Deployment model**

Create `app/Models/Deployment.php`:
```php
<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    use HasFactory;

    protected $fillable = ['app_id', 'status', 'log', 'started_at', 'finished_at'];

    protected $casts = [
        'status'      => DeploymentStatus::class,
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
```

- [ ] **Step 7: Run test — verify PASS**

```bash
./vendor/bin/pest tests/Unit/ModelTest.php
# Expected: PASS
```

- [ ] **Step 8: Commit**

```bash
git add app/Models/ database/factories/ tests/Unit/ModelTest.php
git commit -m "feat: add App and Deployment models with factories"
```

---

### Task 5: Bridge config + GitService

**Files:**
- Create: `config/bridge.php`
- Create: `app/Services/GitService.php`
- Create: `tests/Unit/GitServiceTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/GitServiceTest.php`:
```php
<?php

use App\Services\GitService;

test('clone returns output on success', function () {
    $service = new GitService(sshKeyPath: null);
    $tempDir  = sys_get_temp_dir() . '/bridge-clone-' . uniqid();

    $output = $service->clone('https://github.com/octocat/Hello-World.git', $tempDir, 'master');

    expect($output)->toBeString();
    expect(is_dir($tempDir))->toBeTrue();

    exec("rm -rf {$tempDir}");
});

test('clone throws RuntimeException on invalid repo', function () {
    $service = new GitService(sshKeyPath: null);
    $tempDir  = sys_get_temp_dir() . '/bridge-clone-fail-' . uniqid();

    expect(fn () => $service->clone(
        'https://github.com/nonexistent-org-99xyz/nonexistent-repo-99xyz.git',
        $tempDir,
        'main'
    ))->toThrow(\RuntimeException::class);
});

test('pull returns output for existing repo', function () {
    $service = new GitService(sshKeyPath: null);
    $tempDir  = sys_get_temp_dir() . '/bridge-pull-' . uniqid();

    $service->clone('https://github.com/octocat/Hello-World.git', $tempDir, 'master');
    $output = $service->pull($tempDir, 'master');

    expect($output)->toBeString();

    exec("rm -rf {$tempDir}");
});
```

Note: these tests make real network calls (~5–15s). They test real behavior without mocks.

- [ ] **Step 2: Run test — verify FAIL**

```bash
./vendor/bin/pest tests/Unit/GitServiceTest.php
# Expected: FAIL — "App\Services\GitService not found"
```

- [ ] **Step 3: Create bridge config**

Create `config/bridge.php`:
```php
<?php

return [
    'ssh_key_path' => env('BRIDGE_SSH_KEY_PATH', '/data/ssh/id_rsa'),
    'repos_path'   => env('REPOS_PATH', '/repos'),
];
```

- [ ] **Step 4: Create GitService**

Create `app/Services/GitService.php`:
```php
<?php

namespace App\Services;

class GitService
{
    public function __construct(
        private readonly ?string $sshKeyPath = null
    ) {
        $this->sshKeyPath ??= config('bridge.ssh_key_path');
    }

    public function clone(string $repoUrl, string $targetPath, string $branch): string
    {
        $cmd = $this->withSsh(
            'git clone --branch ' . escapeshellarg($branch)
            . ' ' . escapeshellarg($repoUrl)
            . ' ' . escapeshellarg($targetPath)
        );
        return $this->run($cmd);
    }

    public function pull(string $repoPath, string $branch): string
    {
        $cmd = $this->withSsh(
            'git -C ' . escapeshellarg($repoPath)
            . ' pull origin ' . escapeshellarg($branch)
        );
        return $this->run($cmd);
    }

    private function withSsh(string $gitCmd): string
    {
        if ($this->sshKeyPath && file_exists($this->sshKeyPath)) {
            $sshEnv = 'GIT_SSH_COMMAND=' . escapeshellarg(
                'ssh -i ' . escapeshellarg($this->sshKeyPath) . ' -o StrictHostKeyChecking=no'
            );
            return "{$sshEnv} {$gitCmd}";
        }
        return $gitCmd;
    }

    private function run(string $cmd): string
    {
        $output   = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(implode("\n", $output));
        }

        return implode("\n", $output);
    }
}
```

- [ ] **Step 5: Run test — verify PASS**

```bash
./vendor/bin/pest tests/Unit/GitServiceTest.php
# Expected: PASS (takes ~10s for network)
```

- [ ] **Step 6: Commit**

```bash
git add config/bridge.php app/Services/GitService.php tests/Unit/GitServiceTest.php
git commit -m "feat: add GitService and bridge config"
```

---

### Task 6: App CRUD routes, controller, and stub views

**Files:**
- Create: `app/Http/Controllers/AppController.php`
- Create: `app/Http/Controllers/DeploymentController.php` (stub)
- Modify: `routes/web.php`
- Create: `resources/views/apps/{index,create,show,edit}.blade.php` (stubs)
- Create: `resources/views/deployments/show.blade.php` (stub)
- Create: `tests/Feature/AppCrudTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/AppCrudTest.php`:
```php
<?php

use App\Models\App;
use App\Services\GitService;

test('apps index returns 200 with apps', function () {
    App::factory()->count(3)->create();
    $response = $this->get('/');
    $response->assertStatus(200);
    $response->assertViewIs('apps.index');
    $response->assertViewHas('apps');
});

test('create form returns 200', function () {
    $response = $this->get('/apps/create');
    $response->assertStatus(200);
    $response->assertViewIs('apps.create');
});

test('store clones repo, creates app, and redirects', function () {
    $this->mock(GitService::class, function ($mock) {
        $mock->shouldReceive('clone')->once()->andReturn('Cloning into...');
    });

    $response = $this->post('/apps', [
        'name'     => 'My App',
        'repo_url' => 'https://github.com/example/repo.git',
        'branch'   => 'main',
        'path'     => '/tmp/test-app',
    ]);

    $response->assertRedirect('/');
    $this->assertDatabaseHas('apps', ['name' => 'My App']);
});

test('store validates required fields', function () {
    $response = $this->post('/apps', []);
    $response->assertSessionHasErrors(['name', 'repo_url', 'path']);
});

test('store flashes error when clone fails', function () {
    $this->mock(GitService::class, function ($mock) {
        $mock->shouldReceive('clone')->once()->andThrow(new \RuntimeException('Auth failed'));
    });

    $response = $this->post('/apps', [
        'name'     => 'Bad App',
        'repo_url' => 'git@github.com:private/repo.git',
        'branch'   => 'main',
        'path'     => '/tmp/bad-app',
    ]);

    $response->assertSessionHasErrors(['repo_url']);
    $this->assertDatabaseMissing('apps', ['name' => 'Bad App']);
});

test('show returns app detail view', function () {
    $app = App::factory()->create();
    $response = $this->get("/apps/{$app->id}");
    $response->assertStatus(200);
    $response->assertViewIs('apps.show');
});

test('edit form returns 200', function () {
    $app = App::factory()->create();
    $response = $this->get("/apps/{$app->id}/edit");
    $response->assertStatus(200);
    $response->assertViewIs('apps.edit');
});

test('update modifies app and redirects', function () {
    $app = App::factory()->create(['name' => 'Old Name']);
    $response = $this->put("/apps/{$app->id}", [
        'name'     => 'New Name',
        'repo_url' => $app->repo_url,
        'branch'   => $app->branch,
        'path'     => $app->path,
    ]);
    $response->assertRedirect("/apps/{$app->id}");
    $this->assertDatabaseHas('apps', ['id' => $app->id, 'name' => 'New Name']);
});

test('destroy deletes app and redirects', function () {
    $app = App::factory()->create();
    $response = $this->delete("/apps/{$app->id}");
    $response->assertRedirect('/');
    $this->assertDatabaseMissing('apps', ['id' => $app->id]);
});
```

- [ ] **Step 2: Run tests — verify FAIL**

```bash
./vendor/bin/pest tests/Feature/AppCrudTest.php
# Expected: FAIL — routes not defined
```

- [ ] **Step 3: Register routes**

Replace `routes/web.php`:
```php
<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\DeploymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AppController::class, 'index']);
Route::resource('apps', AppController::class)->except(['index']);
Route::post('/apps/{app}/deploy', [AppController::class, 'deploy'])->name('apps.deploy');
Route::get('/deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
Route::get('/deployments/{deployment}/stream', [DeploymentController::class, 'stream'])->name('deployments.stream');
```

- [ ] **Step 4: Create AppController**

Create `app/Http/Controllers/AppController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Services\GitService;
use Illuminate\Http\Request;

class AppController extends Controller
{
    public function index()
    {
        $apps = App::latest()->get();
        return view('apps.index', compact('apps'));
    }

    public function create()
    {
        return view('apps.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'repo_url' => 'required|string|max:500',
            'branch'   => 'required|string|max:255',
            'path'     => 'required|string|max:500',
        ]);

        $git = app(GitService::class);

        try {
            $git->clone($validated['repo_url'], $validated['path'], $validated['branch']);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['repo_url' => 'Clone failed: ' . $e->getMessage()]);
        }

        App::create($validated);

        return redirect('/')->with('success', 'App created and cloned.');
    }

    public function show(App $app)
    {
        $deployments = $app->deployments()->latest()->get();
        return view('apps.show', compact('app', 'deployments'));
    }

    public function edit(App $app)
    {
        return view('apps.edit', compact('app'));
    }

    public function update(Request $request, App $app)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'repo_url' => 'required|string|max:500',
            'branch'   => 'required|string|max:255',
            'path'     => 'required|string|max:500',
        ]);

        $app->update($validated);

        return redirect("/apps/{$app->id}")->with('success', 'App updated.');
    }

    public function destroy(App $app)
    {
        $app->delete();
        return redirect('/')->with('success', 'App deleted.');
    }

    public function deploy(App $app)
    {
        $deployment = $app->deployments()->create([
            'status' => DeploymentStatus::Pending,
        ]);

        DeployApp::dispatch($deployment);

        return redirect()->route('deployments.show', $deployment);
    }
}
```

- [ ] **Step 5: Create stub DeploymentController**

Create `app/Http/Controllers/DeploymentController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Deployment;

class DeploymentController extends Controller
{
    public function show(Deployment $deployment)
    {
        return view('deployments.show', compact('deployment'));
    }

    public function stream(Deployment $deployment)
    {
        // Implemented in Task 8
    }
}
```

- [ ] **Step 6: Create stub Blade views**

Create `resources/views/apps/index.blade.php`:
```blade
<p>index</p>
```

Create `resources/views/apps/create.blade.php`:
```blade
<p>create</p>
```

Create `resources/views/apps/show.blade.php`:
```blade
<p>show</p>
```

Create `resources/views/apps/edit.blade.php`:
```blade
<p>edit</p>
```

Create `resources/views/deployments/show.blade.php`:
```blade
<p>deployment</p>
```

- [ ] **Step 7: Run tests — verify PASS**

```bash
./vendor/bin/pest tests/Feature/AppCrudTest.php
# Expected: PASS
```

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ routes/web.php resources/views/ tests/Feature/AppCrudTest.php
git commit -m "feat: add App CRUD controller, routes, and deploy trigger"
```

---

### Task 7: DeployApp job

**Files:**
- Create: `app/Jobs/DeployApp.php`
- Create: `tests/Unit/DeployAppJobTest.php`
- Create: `tests/Feature/DeployTriggerTest.php`

- [ ] **Step 1: Write failing feature test**

Create `tests/Feature/DeployTriggerTest.php`:
```php
<?php

use App\Jobs\DeployApp;
use App\Models\App;
use Illuminate\Support\Facades\Queue;

test('deploy endpoint creates pending deployment and dispatches job', function () {
    Queue::fake();
    $app = App::factory()->create();

    $response = $this->post("/apps/{$app->id}/deploy");

    $response->assertRedirect();
    $this->assertDatabaseHas('deployments', ['app_id' => $app->id, 'status' => 'pending']);
    Queue::assertPushed(DeployApp::class);
});
```

- [ ] **Step 2: Write failing unit test**

Create `tests/Unit/DeployAppJobTest.php`:
```php
<?php

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Models\Deployment;

test('job marks deployment success when all commands exit 0', function () {
    $app = App::factory()->create([
        'path'     => sys_get_temp_dir() . '/bridge-deploy-' . uniqid(),
        'repo_url' => 'https://github.com/octocat/Hello-World.git',
        'branch'   => 'master',
    ]);
    $deployment = Deployment::factory()->create(['app_id' => $app->id, 'status' => 'pending']);

    // Clone so git pull has something to work with
    mkdir($app->path, 0755, true);
    exec("git clone --branch {$app->branch} {$app->repo_url} {$app->path} 2>&1");

    $job = new DeployApp($deployment, composeRunner: function (string $subCmd, string $workDir, callable $onOutput): int {
        $onOutput("mock compose {$subCmd}\n");
        return 0;
    });

    $job->handle();

    $deployment->refresh();
    $app->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Success);
    expect($deployment->log)->toContain('mock compose');
    expect($deployment->finished_at)->not->toBeNull();
    expect($app->status)->toBe(AppStatus::Success);

    exec("rm -rf {$app->path}");
});

test('job marks deployment failed when compose exits non-zero', function () {
    $app = App::factory()->create([
        'path'     => sys_get_temp_dir() . '/bridge-fail-' . uniqid(),
        'repo_url' => 'https://github.com/octocat/Hello-World.git',
        'branch'   => 'master',
    ]);
    $deployment = Deployment::factory()->create(['app_id' => $app->id, 'status' => 'pending']);

    mkdir($app->path, 0755, true);
    exec("git clone --branch {$app->branch} {$app->repo_url} {$app->path} 2>&1");

    $job = new DeployApp($deployment, composeRunner: function (string $subCmd, string $workDir, callable $onOutput): int {
        $onOutput("compose error\n");
        return 1; // simulate failure
    });

    $job->handle();

    $deployment->refresh();
    $app->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed);
    expect($app->status)->toBe(AppStatus::Failed);

    exec("rm -rf {$app->path}");
});
```

- [ ] **Step 3: Run tests — verify FAIL**

```bash
./vendor/bin/pest tests/Feature/DeployTriggerTest.php tests/Unit/DeployAppJobTest.php
# Expected: FAIL — "App\Jobs\DeployApp not found"
```

- [ ] **Step 4: Create DeployApp job**

Create `app/Jobs/DeployApp.php`:
```php
<?php

namespace App\Jobs;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\GitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class DeployApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var callable */
    private $composeRunner;

    public function __construct(
        private readonly Deployment $deployment,
        ?callable $composeRunner = null
    ) {
        $this->composeRunner = $composeRunner ?? $this->defaultComposeRunner();
    }

    public function handle(): void
    {
        $deployment = $this->deployment;
        $app        = $deployment->app;

        $deployment->update(['status' => DeploymentStatus::Running, 'started_at' => now()]);
        $app->update(['status' => AppStatus::Deploying]);

        try {
            $git = new GitService();
            $this->appendLog($deployment, "=== git pull ===\n");
            $output = $git->pull($app->path, $app->branch);
            $this->appendLog($deployment, $output . "\n");

            foreach (['pull', 'up -d --build'] as $subCmd) {
                $this->appendLog($deployment, "=== docker compose {$subCmd} ===\n");
                $exit = ($this->composeRunner)($subCmd, $app->path, fn (string $chunk) => $this->appendLog($deployment, $chunk));
                if ($exit !== 0) {
                    throw new \RuntimeException("docker compose {$subCmd} exited with code {$exit}");
                }
            }

            $deployment->update(['status' => DeploymentStatus::Success, 'finished_at' => now()]);
            $app->update(['status' => AppStatus::Success]);

        } catch (\Throwable $e) {
            $this->appendLog($deployment, "\nERROR: " . $e->getMessage() . "\n");
            $deployment->update(['status' => DeploymentStatus::Failed, 'finished_at' => now()]);
            $app->update(['status' => AppStatus::Failed]);
        }
    }

    private function appendLog(Deployment $deployment, string $chunk): void
    {
        DB::statement(
            "UPDATE deployments SET log = COALESCE(log, '') || ? WHERE id = ?",
            [$chunk, $deployment->id]
        );
    }

    private function defaultComposeRunner(): callable
    {
        return function (string $subCmd, string $workDir, callable $onOutput): int {
            $sshKey = config('bridge.ssh_key_path');
            $sshEnv = '';
            if (file_exists((string) $sshKey)) {
                $sshEnv = 'GIT_SSH_COMMAND=' . escapeshellarg("ssh -i {$sshKey} -o StrictHostKeyChecking=no") . ' ';
            }

            $composePath = escapeshellarg("{$workDir}/docker-compose.yml");
            $cmd         = "{$sshEnv}docker compose -f {$composePath} {$subCmd}";

            $spec  = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc  = proc_open($cmd, $spec, $pipes, $workDir);

            if (!is_resource($proc)) {
                $onOutput("Failed to start: {$cmd}\n");
                return 1;
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            while (true) {
                $out = fread($pipes[1], 4096);
                $err = fread($pipes[2], 4096);
                if ($out) $onOutput($out);
                if ($err) $onOutput($err);
                if (feof($pipes[1]) && feof($pipes[2])) break;
                usleep(50000);
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            return proc_close($proc);
        };
    }
}
```

- [ ] **Step 5: Run tests — verify PASS**

```bash
./vendor/bin/pest tests/Feature/DeployTriggerTest.php tests/Unit/DeployAppJobTest.php
# Expected: PASS (unit tests take ~10s for git clone)
```

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/DeployApp.php tests/Feature/DeployTriggerTest.php tests/Unit/DeployAppJobTest.php
git commit -m "feat: add DeployApp job with proc_open log streaming"
```

---

### Task 8: SSE stream endpoint

**Files:**
- Modify: `app/Http/Controllers/DeploymentController.php`
- Create: `tests/Feature/SseStreamTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/SseStreamTest.php`:
```php
<?php

use App\Models\App;
use App\Models\Deployment;

test('stream returns text/event-stream content type', function () {
    $app        = App::factory()->create();
    $deployment = Deployment::factory()->create([
        'app_id' => $app->id,
        'status' => 'success',
        'log'    => "line1\nline2\n",
    ]);

    $response = $this->get("/deployments/{$deployment->id}/stream");

    $response->assertHeader('Content-Type', 'text/event-stream');
});

test('stream emits done event for terminal deployment', function () {
    $app        = App::factory()->create();
    $deployment = Deployment::factory()->create([
        'app_id' => $app->id,
        'status' => 'success',
        'log'    => "build output\n",
    ]);

    ob_start();
    $this->get("/deployments/{$deployment->id}/stream");
    $body = ob_get_clean() ?: '';

    expect($body)->toContain('"done":true');
    expect($body)->toContain('build output');
});
```

- [ ] **Step 2: Run tests — verify FAIL**

```bash
./vendor/bin/pest tests/Feature/SseStreamTest.php
# Expected: FAIL — stream() returns null
```

- [ ] **Step 3: Implement SSE stream**

Replace the `stream` method in `app/Http/Controllers/DeploymentController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;

class DeploymentController extends Controller
{
    public function show(Deployment $deployment)
    {
        return view('deployments.show', compact('deployment'));
    }

    public function stream(Deployment $deployment)
    {
        $terminal = [DeploymentStatus::Success, DeploymentStatus::Failed];

        return response()->stream(function () use ($deployment, $terminal) {
            $offset = 0;

            while (true) {
                $deployment->refresh();
                $log = $deployment->log ?? '';
                $new = substr($log, $offset);

                if ($new !== '') {
                    echo 'data: ' . json_encode(['text' => $new]) . "\n\n";
                    ob_flush();
                    flush();
                    $offset = strlen($log);
                }

                if (in_array($deployment->status, $terminal)) {
                    echo 'data: ' . json_encode(['done' => true, 'status' => $deployment->status->value]) . "\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                usleep(500000);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
```

- [ ] **Step 4: Run tests — verify PASS**

```bash
./vendor/bin/pest tests/Feature/SseStreamTest.php
# Expected: PASS
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DeploymentController.php tests/Feature/SseStreamTest.php
git commit -m "feat: add SSE log streaming endpoint"
```

---

### Task 9: Final Blade views

**Files:**
- Create: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/apps/index.blade.php`
- Modify: `resources/views/apps/create.blade.php`
- Modify: `resources/views/apps/show.blade.php`
- Modify: `resources/views/apps/edit.blade.php`
- Modify: `resources/views/deployments/show.blade.php`

No automated tests — verify manually in browser.

- [ ] **Step 1: Create layout**

Create `resources/views/layouts/app.blade.php`:
```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Bridge</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-100 text-gray-900">
<nav class="bg-gray-900 text-white px-6 py-3 flex items-center gap-4">
    <a href="/" class="font-bold text-lg tracking-tight">The Bridge</a>
    <a href="/apps/create" class="text-sm text-gray-400 hover:text-white">+ New App</a>
</nav>
<main class="max-w-4xl mx-auto p-6">
    @if(session('success'))
        <div class="mb-4 bg-green-100 text-green-800 px-4 py-2 rounded text-sm">{{ session('success') }}</div>
    @endif
    @yield('content')
</main>
</body>
</html>
```

- [ ] **Step 2: Apps index**

Replace `resources/views/apps/index.blade.php`:
```blade
@extends('layouts.app')
@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold">Apps</h1>
    <a href="/apps/create" class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">New App</a>
</div>
@forelse($apps as $app)
<div class="bg-white rounded shadow p-4 mb-3 flex items-center justify-between">
    <div>
        <a href="/apps/{{ $app->id }}" class="font-semibold text-blue-700 hover:underline">{{ $app->name }}</a>
        <span class="ml-2 text-xs text-gray-400">{{ $app->path }}</span>
    </div>
    <div class="flex items-center gap-3">
        @php
            $badge = match($app->status->value) {
                'success'   => 'bg-green-100 text-green-800',
                'failed'    => 'bg-red-100 text-red-800',
                'deploying' => 'bg-yellow-100 text-yellow-800',
                default     => 'bg-gray-100 text-gray-600',
            };
        @endphp
        <span class="text-xs px-2 py-1 rounded {{ $badge }}">{{ $app->status->value }}</span>
        <form method="POST" action="/apps/{{ $app->id }}/deploy">
            @csrf
            <button class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">Deploy</button>
        </form>
        <a href="/apps/{{ $app->id }}/edit" class="text-sm text-gray-400 hover:text-gray-700">Edit</a>
    </div>
</div>
@empty
<p class="text-gray-500">No apps yet. <a href="/apps/create" class="text-blue-600 underline">Create one.</a></p>
@endforelse
@endsection
```

- [ ] **Step 3: Create app form**

Replace `resources/views/apps/create.blade.php`:
```blade
@extends('layouts.app')
@section('content')
<h1 class="text-2xl font-bold mb-6">New App</h1>
<form method="POST" action="/apps" class="bg-white rounded shadow p-6 space-y-4 max-w-xl">
    @csrf
    <div>
        <label class="block text-sm font-medium mb-1">Name</label>
        <input name="name" value="{{ old('name') }}" required
            class="w-full border rounded px-3 py-2 @error('name') border-red-500 @enderror">
        @error('name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Repo URL</label>
        <input name="repo_url" value="{{ old('repo_url') }}" required
            placeholder="https://github.com/org/repo.git"
            class="w-full border rounded px-3 py-2 @error('repo_url') border-red-500 @enderror">
        @error('repo_url')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Branch</label>
        <input name="branch" value="{{ old('branch', 'main') }}" required
            class="w-full border rounded px-3 py-2">
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Local Path</label>
        <input name="path" value="{{ old('path') }}" required
            placeholder="{{ config('bridge.repos_path') }}/my-app"
            class="w-full border rounded px-3 py-2 @error('path') border-red-500 @enderror">
        @error('path')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Create &amp; Clone</button>
</form>
@endsection
```

- [ ] **Step 4: App show view**

Replace `resources/views/apps/show.blade.php`:
```blade
@extends('layouts.app')
@section('content')
<div class="flex justify-between items-center mb-4">
    <div>
        <h1 class="text-2xl font-bold">{{ $app->name }}</h1>
        <p class="text-sm text-gray-500 mt-1">{{ $app->repo_url }} &nbsp;·&nbsp; {{ $app->branch }} &nbsp;·&nbsp; {{ $app->path }}</p>
    </div>
    <div class="flex gap-2">
        <form method="POST" action="/apps/{{ $app->id }}/deploy">
            @csrf
            <button class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Deploy</button>
        </form>
        <a href="/apps/{{ $app->id }}/edit" class="bg-gray-200 px-4 py-2 rounded hover:bg-gray-300 text-sm">Edit</a>
    </div>
</div>
<h2 class="font-semibold mb-3">Deploy History</h2>
@forelse($deployments as $deployment)
@php
    $badge = match($deployment->status->value) {
        'success' => 'bg-green-100 text-green-800',
        'failed'  => 'bg-red-100 text-red-800',
        'running' => 'bg-yellow-100 text-yellow-800',
        default   => 'bg-gray-100 text-gray-600',
    };
@endphp
<div class="bg-white rounded shadow p-3 mb-2 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <span class="text-xs px-2 py-1 rounded {{ $badge }}">{{ $deployment->status->value }}</span>
        <span class="text-sm text-gray-500">{{ $deployment->created_at->diffForHumans() }}</span>
    </div>
    <a href="/deployments/{{ $deployment->id }}" class="text-sm text-blue-600 hover:underline">View Log</a>
</div>
@empty
<p class="text-gray-500 text-sm">No deployments yet.</p>
@endforelse
@endsection
```

- [ ] **Step 5: Edit app form**

Replace `resources/views/apps/edit.blade.php`:
```blade
@extends('layouts.app')
@section('content')
<h1 class="text-2xl font-bold mb-6">Edit {{ $app->name }}</h1>
<form method="POST" action="/apps/{{ $app->id }}" class="bg-white rounded shadow p-6 space-y-4 max-w-xl">
    @csrf
    @method('PUT')
    <div>
        <label class="block text-sm font-medium mb-1">Name</label>
        <input name="name" value="{{ old('name', $app->name) }}" required class="w-full border rounded px-3 py-2">
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Repo URL</label>
        <input name="repo_url" value="{{ old('repo_url', $app->repo_url) }}" required class="w-full border rounded px-3 py-2">
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Branch</label>
        <input name="branch" value="{{ old('branch', $app->branch) }}" required class="w-full border rounded px-3 py-2">
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Local Path</label>
        <input name="path" value="{{ old('path', $app->path) }}" required class="w-full border rounded px-3 py-2">
    </div>
    <div class="flex gap-3">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Save</button>
        <a href="/apps/{{ $app->id }}" class="bg-gray-200 px-4 py-2 rounded hover:bg-gray-300">Cancel</a>
    </div>
</form>
<form method="POST" action="/apps/{{ $app->id }}" class="mt-6 max-w-xl">
    @csrf
    @method('DELETE')
    <button type="submit" onclick="return confirm('Delete this app?')"
        class="text-red-600 text-sm hover:underline">Delete App</button>
</form>
@endsection
```

- [ ] **Step 6: Deployment log view with Alpine.js SSE**

Replace `resources/views/deployments/show.blade.php`:
```blade
@extends('layouts.app')
@section('content')
<div class="flex items-center gap-3 mb-4">
    <a href="/apps/{{ $deployment->app_id }}" class="text-gray-500 hover:text-gray-700 text-sm">← {{ $deployment->app->name }}</a>
    @php
        $badge = match($deployment->status->value) {
            'success' => 'bg-green-100 text-green-800',
            'failed'  => 'bg-red-100 text-red-800',
            'running' => 'bg-yellow-100 text-yellow-800',
            default   => 'bg-gray-100 text-gray-600',
        };
    @endphp
    <span class="text-xs px-2 py-1 rounded {{ $badge }}" x-text="status">{{ $deployment->status->value }}</span>
</div>

<div
    x-data="{
        log: @js($deployment->log ?? ''),
        status: @js($deployment->status->value),
        done: @js(in_array($deployment->status->value, ['success', 'failed'])),
        init() {
            if (this.done) return;
            const es = new EventSource('{{ route('deployments.stream', $deployment) }}');
            es.onmessage = (e) => {
                const data = JSON.parse(e.data);
                if (data.text) {
                    this.log += data.text;
                    this.$nextTick(() => {
                        const el = this.$refs.logbox;
                        el.scrollTop = el.scrollHeight;
                    });
                }
                if (data.done) {
                    this.status = data.status;
                    this.done = true;
                    es.close();
                }
            };
        }
    }"
>
    <pre
        x-ref="logbox"
        x-text="log || 'Waiting for output...'"
        class="bg-gray-900 text-green-400 rounded p-4 text-sm font-mono overflow-auto h-[600px] whitespace-pre-wrap"
    ></pre>
</div>
@endsection
```

- [ ] **Step 7: Run full test suite**

```bash
./vendor/bin/pest
# Expected: all PASS
```

- [ ] **Step 8: Smoke test in browser**

```bash
php artisan queue:work --daemon &
php artisan serve
# Open http://localhost:8000, verify layout renders, create app form loads
```

Kill background worker after test: `kill %1`

- [ ] **Step 9: Commit**

```bash
git add resources/views/
git commit -m "feat: add complete Blade UI with Alpine.js SSE log viewer"
```

---

### Task 10: Dockerfile and container config

**Files:**
- Create: `docker/Dockerfile`
- Create: `docker/nginx.conf`
- Create: `docker/supervisord.conf`
- Create: `docker/entrypoint.sh`
- Create: `docker-compose.yml`
- Create: `.env.example`
- Create: `.dockerignore`

- [ ] **Step 1: Create Dockerfile**

Create `docker/Dockerfile`:
```dockerfile
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    git \
    openssh-client \
    docker-cli \
    docker-cli-compose \
    && docker-php-ext-install pdo_sqlite pcntl posix

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && mkdir -p /data/ssh \
    && chmod 700 /data/ssh \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
```

- [ ] **Step 2: Create nginx.conf**

Create `docker/nginx.conf`:
```nginx
worker_processes 1;
events { worker_connections 1024; }

http {
    include      mime.types;
    default_type application/octet-stream;
    sendfile     on;

    server {
        listen 80;
        root  /var/www/html/public;
        index index.php;

        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location ~ \.php$ {
            fastcgi_pass  127.0.0.1:9000;
            fastcgi_index index.php;
            include       fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        }

        # Disable buffering for SSE endpoints
        location ~ ^/deployments/[0-9]+/stream$ {
            fastcgi_pass         127.0.0.1:9000;
            fastcgi_index        index.php;
            include              fastcgi_params;
            fastcgi_param        SCRIPT_FILENAME $document_root/index.php;
            fastcgi_read_timeout 3600;
            fastcgi_buffering    off;
        }
    }
}
```

- [ ] **Step 3: Create supervisord.conf**

Create `docker/supervisord.conf`:
```ini
[supervisord]
nodaemon=true
logfile=/dev/stdout
logfile_maxbytes=0

[program:php-fpm]
command=php-fpm
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=nginx -g "daemon off;"
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:queue-worker]
command=php /var/www/html/artisan queue:work --sleep=3 --tries=1
directory=/var/www/html
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

- [ ] **Step 4: Create entrypoint.sh**

Create `docker/entrypoint.sh`:
```bash
#!/bin/sh
set -e

mkdir -p "$(dirname "$DB_DATABASE")"
touch "$DB_DATABASE"

php /var/www/html/artisan migrate --force

if [ -z "$APP_KEY" ]; then
    php /var/www/html/artisan key:generate --force
fi

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisord.conf
```

- [ ] **Step 5: Create bridge docker-compose.yml**

Create `docker-compose.yml`:
```yaml
services:
  bridge:
    build:
      context: .
      dockerfile: docker/Dockerfile
    restart: unless-stopped
    ports:
      - "${BRIDGE_PORT:-8080}:80"
    volumes:
      - ${REPOS_PATH}:${REPOS_PATH}
      - /var/run/docker.sock:/var/run/docker.sock
      - ./data:/data
    environment:
      - APP_KEY=${APP_KEY}
      - APP_ENV=production
      - APP_DEBUG=false
      - DB_CONNECTION=sqlite
      - DB_DATABASE=/data/database.sqlite
      - QUEUE_CONNECTION=database
      - REPOS_PATH=${REPOS_PATH}
      - BRIDGE_SSH_KEY_PATH=/data/ssh/id_rsa
```

- [ ] **Step 6: Create .env.example**

Create `.env.example`:
```dotenv
# Port the Bridge UI is served on
BRIDGE_PORT=8080

# Absolute path to repos on the HOST — same path used inside the container
# Ubuntu example:  REPOS_PATH=/home/aclinton/Dev
# Unraid example:  REPOS_PATH=/mnt/user/appdata/dockge/stacks
REPOS_PATH=/opt/repos

# Generate with:
#   docker run --rm php:8.3-alpine php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
APP_KEY=
```

- [ ] **Step 7: Create .dockerignore**

Create `.dockerignore`:
```
node_modules
vendor
.git
.env
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
tests
docker-compose*.yml
.dockerignore
docs
```

- [ ] **Step 8: Build and start**

```bash
cp .env.example .env
# Edit .env: set REPOS_PATH and APP_KEY
mkdir -p data
docker compose build
docker compose up
```

Open `http://localhost:8080` — apps list renders.

- [ ] **Step 9: Commit**

```bash
git add docker/ docker-compose.yml .env.example .dockerignore
git commit -m "feat: add Dockerfile, Nginx, Supervisor, and bridge compose config"
```

---

## Verification Checklist

- [ ] `./vendor/bin/pest` — all tests pass
- [ ] `docker compose build` — image builds without errors
- [ ] `docker compose up` — container starts, UI at configured port
- [ ] Create app via UI — git clone runs, app appears in list
- [ ] Click Deploy — redirected to log view, output streams live
- [ ] Deploy completes — status badge updates to success or failed
- [ ] Deploy history on app detail page shows past runs with log links
- [ ] Private repo: place SSH key at `./data/ssh/id_rsa`, clone private repo, deploy
- [ ] Ubuntu install: `REPOS_PATH=/home/aclinton/Dev`
- [ ] Unraid install: `REPOS_PATH=/mnt/user/appdata/dockge/stacks`
