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
