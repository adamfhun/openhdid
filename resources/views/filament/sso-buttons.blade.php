<div class="flex flex-col gap-3">
    @foreach ($providers as $provider)
        <x-filament::button
            tag="a"
            :href="route('sso.redirect', ['principal' => 'user', 'provider' => $provider->value])"
            color="primary"
            size="lg"
            class="w-full"
        >
            {{ $provider->label() }}
        </x-filament::button>
    @endforeach
</div>
