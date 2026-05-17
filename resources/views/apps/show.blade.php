@extends('layouts.app')
@section('content')
@php
    $statusBadge = match($app->status->value) {
        'success'   => 'bg-green-100 text-green-800',
        'failed'    => 'bg-red-100 text-red-800',
        'deploying' => 'bg-yellow-100 text-yellow-800',
        default     => 'bg-gray-100 text-gray-600',
    };
@endphp

<div class="bg-white rounded-lg shadow p-6 mb-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ $app->name }}</h1>
            <span class="inline-block mt-1 text-xs px-2 py-0.5 rounded {{ $statusBadge }}">
                {{ $app->status->value }}
            </span>
        </div>
        <div class="flex gap-2 shrink-0">
            <form method="POST" action="/apps/{{ $app->id }}/deploy">
                @csrf
                <button class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 text-sm font-medium">
                    Deploy
                </button>
            </form>
            <a href="/apps/{{ $app->id }}/edit"
               class="bg-gray-100 text-gray-700 px-4 py-2 rounded hover:bg-gray-200 text-sm font-medium">
                Edit
            </a>
        </div>
    </div>

    <dl class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-1 gap-3 sm:grid-cols-3 text-sm">
        <div>
            <dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">Repository</dt>
            <dd class="mt-0.5 text-gray-700 break-all">{{ $app->repo_url }}</dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">Branch</dt>
            <dd class="mt-0.5 text-gray-700 font-mono">{{ $app->branch }}</dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">Path</dt>
            <dd class="mt-0.5 text-gray-700 font-mono break-all">{{ $app->path }}</dd>
        </div>
    </dl>
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
