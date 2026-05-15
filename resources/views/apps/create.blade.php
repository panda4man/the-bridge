@extends('layouts.app')
@section('content')
<h1 class="text-2xl font-bold mb-6">New App</h1>
<form method="POST" action="/apps" class="bg-white rounded shadow p-6 space-y-4 max-w-xl">
    @csrf
    <div>
        <label class="block text-sm font-medium mb-1">Name</label>
        <input name="name" value="{{ old('name') }}" required
            class="w-full border rounded px-3 py-2 @error('name') border-red-500 @enderror">
        @error('name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Repo URL</label>
        <input name="repo_url" value="{{ old('repo_url') }}" required
            placeholder="https://github.com/org/repo.git"
            class="w-full border rounded px-3 py-2 @error('repo_url') border-red-500 @enderror">
        @error('repo_url')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Branch</label>
        <input name="branch" value="{{ old('branch', 'main') }}" required
            class="w-full border rounded px-3 py-2">
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Local Path</label>
        <input name="path" value="{{ old('path') }}" required
            placeholder="{{ config('bridge.repos_path') }}/my-app"
            class="w-full border rounded px-3 py-2 @error('path') border-red-500 @enderror">
        @error('path')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Create &amp; Clone</button>
</form>
@endsection
