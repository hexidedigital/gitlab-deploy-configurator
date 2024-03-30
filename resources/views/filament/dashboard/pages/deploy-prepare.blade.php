<x-filament-panels::page>
    <x-filament-panels::form wire:submit="startConfiguration">
        {{ $this->form }}
    </x-filament-panels::form>

    {{--<pre style="font-size: 0.7rem"><code>{{ json_encode($this->data, JSON_PRETTY_PRINT) }}</code></pre>--}}

    <x-filament-actions::modals/>
</x-filament-panels::page>
