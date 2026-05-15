<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Http\Requests\StoreAppRequest;
use App\Http\Requests\UpdateAppRequest;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Services\GitService;

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

    public function store(StoreAppRequest $request)
    {
        $data = $request->appData();
        $git  = app(GitService::class);

        try {
            $git->clone($data['repo_url'], $data['path'], $data['branch']);
        } catch (\RuntimeException $e) {
            if (is_dir($data['path'])) {
                exec('rm -rf ' . escapeshellarg($data['path']));
            }
            return back()->withInput()->withErrors(['repo_url' => 'Clone failed: ' . $e->getMessage()]);
        }

        $envExample = $data['path'] . '/.env.example';
        $envFile    = $data['path'] . '/.env';
        if (file_exists($envExample) && !file_exists($envFile)) {
            copy($envExample, $envFile);
        }

        App::create($data);

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

    public function update(UpdateAppRequest $request, App $app)
    {
        $app->update($request->validated());

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
