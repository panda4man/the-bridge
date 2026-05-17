@extends('layouts.app')
@section('content')
<h1 class="text-2xl font-bold mb-6">New App</h1>
<form method="POST" action="/apps" class="bg-white rounded shadow p-6 space-y-4 max-w-xl"
    x-data="{ relative: '{{ old('path') }}', skip: {{ old('skip_clone') ? 'true' : 'false' }} }">
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
        <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
            <input type="checkbox" name="skip_clone" value="1" x-model="skip" class="rounded border-gray-300">
            Import existing directory <span class="text-gray-400">(skip clone)</span>
        </label>
        <p class="text-gray-400 text-xs mt-1" x-show="skip">
            Directory must already exist on disk and be a git repository.
        </p>
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Local Path</label>
        <div class="flex rounded border overflow-hidden @error('path') border-red-500 @else border-gray-300 @enderror">
            <span class="bg-gray-100 text-gray-500 px-3 py-2 text-sm border-r whitespace-nowrap select-none">{{ rtrim(config('bridge.repos_path'), '/') }}/</span>
            <input name="path" x-model="relative" required
                placeholder="my-app"
                class="w-full px-3 py-2 focus:outline-none">
        </div>
        <p class="text-gray-400 text-xs mt-1" x-show="relative.trim()">
            → <span x-text="'{{ rtrim(config('bridge.repos_path'), '/') }}/' + relative.trim()"></span>
        </p>
        @error('path')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
        <span x-text="skip ? 'Import' : 'Create &amp; Clone'">Create &amp; Clone</span>
    </button>
</form>
@endsection
