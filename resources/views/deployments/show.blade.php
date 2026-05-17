@extends('layouts.app')
@section('content')
<div
    x-data="{
        log: @js($deployment->log ?? ''),
        status: @js($deployment->status->value),
        done: @js(in_array($deployment->status->value, ['success', 'failed'])),
        init() {
            if (this.done) return;
            const es = new EventSource('{{ route('deployments.stream', $deployment) }}');
            es.onmessage = (e) => {
                const data = JSON.parse(e.data);
                if (data.text) {
                    this.log += data.text;
                    this.$nextTick(() => {
                        const el = this.$refs.logbox;
                        el.scrollTop = el.scrollHeight;
                    });
                }
                if (data.done) {
                    this.status = data.status;
                    this.done = true;
                    es.close();
                }
            };
        }
    }"
>
    <div class="flex items-center gap-3 mb-4">
        <a href="/apps/{{ $deployment->app_id }}" class="text-gray-500 hover:text-gray-700 text-sm">← {{ $deployment->app->name }}</a>
        <span
            class="text-xs px-2 py-1 rounded"
            :class="{
                'bg-green-100 text-green-800':   status === 'success',
                'bg-red-100 text-red-800':       status === 'failed',
                'bg-yellow-100 text-yellow-800': status === 'running',
                'bg-gray-100 text-gray-600':     status === 'pending'
            }"
            x-text="status"
        ></span>
        @if(in_array($deployment->status->value, ['running', 'pending']))
        <form method="POST" action="{{ route('deployments.reset', $deployment) }}" x-show="!done">
            @csrf
            <button type="submit" class="text-xs px-2 py-1 rounded bg-red-100 text-red-700 hover:bg-red-200"
                onclick="return confirm('Mark this deployment as failed?')">
                Reset
            </button>
        </form>
        @endif
    </div>

    <pre
        x-ref="logbox"
        x-text="log || 'Waiting for output...'"
        class="bg-gray-900 text-green-400 rounded p-4 text-sm font-mono overflow-auto h-[600px] whitespace-pre-wrap"
    ></pre>
</div>
@endsection
