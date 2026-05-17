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
            <dt class="flex items-center gap-1 text-xs font-medium text-gray-400 uppercase tracking-wide">
                {{-- code-bracket --}}
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5" />
                </svg>
                Repository
            </dt>
            <dd class="mt-0.5 text-gray-700 break-all">{{ $app->repo_url }}</dd>
        </div>
        <div>
            <dt class="flex items-center gap-1 text-xs font-medium text-gray-400 uppercase tracking-wide">
                {{-- tag --}}
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L9.568 3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
                </svg>
                Branch
            </dt>
            <dd class="mt-0.5 text-gray-700 font-mono">{{ $app->branch }}</dd>
        </div>
        <div>
            <dt class="flex items-center gap-1 text-xs font-medium text-gray-400 uppercase tracking-wide">
                {{-- folder --}}
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v8.25A2.25 2.25 0 0 0 4.5 16.5h15A2.25 2.25 0 0 0 21.75 14.25V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                </svg>
                Path
            </dt>
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
