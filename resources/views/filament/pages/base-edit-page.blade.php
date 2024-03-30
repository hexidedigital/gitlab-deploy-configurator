<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}
    </x-filament-panels::form>

    {{--<pre style="font-size: 0.7rem"><code>{{ json_encode($this->data, JSON_PRETTY_PRINT) }}</code></pre>--}}
</x-filament-panels::page>
