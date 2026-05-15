@extends('layouts.app')
@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold">Apps</h1>
    <a href="/apps/create" class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">New App</a>
</div>
@forelse($apps as $app)
<div class="bg-white rounded shadow p-4 mb-3 flex items-center justify-between">
    <div>
        <a href="/apps/{{ $app->id }}" class="font-semibold text-blue-700 hover:underline">{{ $app->name }}</a>
        <span class="ml-2 text-xs text-gray-400">{{ $app->path }}</span>
    </div>
    <div class="flex items-center gap-3">
        @php
            $badge = match($app->status->value) {
                'success'   => 'bg-green-100 text-green-800',
                'failed'    => 'bg-red-100 text-red-800',
                'deploying' => 'bg-yellow-100 text-yellow-800',
                default     => 'bg-gray-100 text-gray-600',
            };
        @endphp
        <span class="text-xs px-2 py-1 rounded {{ $badge }}">{{ $app->status->value }}</span>
        <form method="POST" action="/apps/{{ $app->id }}/deploy">
            @csrf
            <button class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">Deploy</button>
        </form>
        <a href="/apps/{{ $app->id }}/edit" class="text-sm text-gray-400 hover:text-gray-700">Edit</a>
    </div>
</div>
@empty
<p class="text-gray-500">No apps yet. <a href="/apps/create" class="text-blue-600 underline">Create one.</a></p>
@endforelse
@endsection
