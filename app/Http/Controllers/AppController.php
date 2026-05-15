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
            'path'     => ['required', 'string', 'max:255', 'not_regex:/\.\./'],
        ]);

        $reposBase = rtrim(config('bridge.repos_path'), '/');
        $validated['path'] = $reposBase . '/' . ltrim($validated['path'], '/');

        if (App::where('path', $validated['path'])->exists()) {
            return back()->withInput()->withErrors(['path' => 'An app already uses this path.']);
        }

        if (is_dir($validated['path'])) {
            return back()->withInput()->withErrors(['path' => 'Directory already exists on disk.']);
        }

        $git = app(GitService::class);

        try {
            $git->clone($validated['repo_url'], $validated['path'], $validated['branch']);
        } catch (\RuntimeException $e) {
            // Clean up partial clone directory if it was created
            if (is_dir($validated['path'])) {
                exec('rm -rf ' . escapeshellarg($validated['path']));
            }
            return back()->withInput()->withErrors(['repo_url' => 'Clone failed: ' . $e->getMessage()]);
        }

        $envExample = $validated['path'] . '/.env.example';
        $envFile    = $validated['path'] . '/.env';
        if (file_exists($envExample) && !file_exists($envFile)) {
            copy($envExample, $envFile);
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
