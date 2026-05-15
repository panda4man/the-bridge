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
