<x-filament-panels::page>
    <div
        x-data="{}"
        x-load-js="[@js('https://cdn.jsdelivr.net/npm/@tsparticles/confetti@3.0.3/tsparticles.confetti.bundle.min.js')]"
    ></div>

    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}
    </x-filament-panels::form>

    {{--<pre style="font-size: 0.7rem"><code>{{ json_encode($this->data, JSON_PRETTY_PRINT) }}</code></pre>--}}
</x-filament-panels::page>
