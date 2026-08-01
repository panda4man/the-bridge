{{--
    The live deploy log. See App\Livewire\DeploymentLog for why the log itself
    is never component state and why polling stops the way it does.

    Two attributes here are load-bearing, not decoration:

    - `wire:poll.1s` is rendered ONLY while the deployment is unfinished.
      Livewire's poll directive pauses as soon as the attribute leaves the
      element, so dropping it is how "stop polling once terminal" is expressed.
    - `wire:ignore` on the <pre> hands its contents to the browser permanently.
      Without it the next morph would replace the box with whatever the server
      last rendered — which is the log as it stood at MOUNT — silently
      discarding every chunk appended since.
--}}
<div
    @if (! $done) wire:poll.1s="poll" @endif
    x-data="{
        isEmpty: @js($initialLog === ''),

        append(text) {
            if (! text) return;

            // The box is showing the placeholder, not output. Replace rather
            // than append, or the first chunk lands after 'Waiting for
            // output...' and stays there for the rest of the deploy.
            if (this.isEmpty) {
                this.$refs.logbox.textContent = '';
                this.isEmpty = false;
            }

            // textContent, never innerHTML: this is untrusted build output.
            this.$refs.logbox.textContent += text;

            this.scrollToBottom();
        },

        scrollToBottom() {
            this.$refs.logbox.scrollTop = this.$refs.logbox.scrollHeight;
        },
    }"
    {{-- $refs are not registered until children have initialised. --}}
    x-init="$nextTick(() => scrollToBottom())"
    x-on:deployment-log-appended.window="append($event.detail.text)"
>
    <pre wire:ignore x-ref="logbox" class="lcars-log">{{ $initialLog !== '' ? $initialLog : 'Waiting for output...' }}</pre>
</div>
