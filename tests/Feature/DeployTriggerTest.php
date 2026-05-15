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
