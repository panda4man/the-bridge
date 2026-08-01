<x-filament-panels::page>
    {{-- wire:submit targets the page's save() method; the Save header action
         submits this form rather than running an action of its own, so
         validation errors land on the fields. --}}
    <form wire:submit="save">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
