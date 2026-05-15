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

    $response->assertHeaderContains('Content-Type', 'text/event-stream');
});

test('stream emits done event for terminal deployment', function () {
    $app        = App::factory()->create();
    $deployment = Deployment::factory()->create([
        'app_id' => $app->id,
        'status' => 'success',
        'log'    => "build output\n",
    ]);

    $response = $this->get("/deployments/{$deployment->id}/stream");
    $body     = $response->streamedContent();

    expect($body)->toContain('"done":true');
    expect($body)->toContain('build output');
});
