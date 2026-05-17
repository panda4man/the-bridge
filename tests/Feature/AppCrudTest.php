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

test('import registers existing git repo without cloning', function () {
    $base = rtrim(config('bridge.repos_path'), '/');
    $dir  = $base . '/bridge-import-' . uniqid();
    mkdir($dir, 0755, true);
    mkdir($dir . '/.git', 0755, true);

    $this->mock(GitService::class, function ($mock) {
        $mock->shouldNotReceive('clone');
    });

    $response = $this->post('/apps', [
        'name'       => 'Imported App',
        'repo_url'   => 'https://github.com/example/repo.git',
        'branch'     => 'main',
        'path'       => basename($dir),
        'skip_clone' => '1',
    ]);

    $response->assertRedirect('/');
    $this->assertDatabaseHas('apps', ['name' => 'Imported App']);

    exec('rm -rf ' . escapeshellarg($dir));
});

test('import fails when directory does not exist', function () {
    $response = $this->post('/apps', [
        'name'       => 'Missing App',
        'repo_url'   => 'https://github.com/example/repo.git',
        'branch'     => 'main',
        'path'       => 'nonexistent-' . uniqid(),
        'skip_clone' => '1',
    ]);

    $response->assertSessionHasErrors(['path']);
    $this->assertDatabaseMissing('apps', ['name' => 'Missing App']);
});

test('import fails when directory exists but is not a git repo', function () {
    $base = rtrim(config('bridge.repos_path'), '/');
    $dir  = $base . '/bridge-nogit-' . uniqid();
    mkdir($dir, 0755, true);

    $response = $this->post('/apps', [
        'name'       => 'No Git App',
        'repo_url'   => 'https://github.com/example/repo.git',
        'branch'     => 'main',
        'path'       => basename($dir),
        'skip_clone' => '1',
    ]);

    $response->assertSessionHasErrors(['path']);
    $this->assertDatabaseMissing('apps', ['name' => 'No Git App']);

    exec('rm -rf ' . escapeshellarg($dir));
});
