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
