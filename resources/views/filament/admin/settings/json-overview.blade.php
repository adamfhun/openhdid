{{-- Readable view of a JSON request body (settings page): formatted, coloured, placeholders marked. Display only. --}}
@php
    /** @var \App\Settings\JsonOverview $overview */
    /** @var array<string, string> $placeholders */
@endphp

<div class="hdid-json-overview" x-data="{ copied: false }">
    <div @class(['hdid-json-status', 'is-ok' => $overview->valid, 'is-bad' => ! $overview->valid])>
        @if ($overview->valid)
            <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5 shrink-0" />
            <span>{{ __('Valid JSON; Save accepts it as it stands.') }}</span>
        @else
            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-5 w-5 shrink-0" />
            <span>{{ $overview->error }}</span>
        @endif
    </div>

    @if ($overview->lines !== [])
        <div class="hdid-json-code" role="region" aria-label="{{ __('Formatted JSON') }}" tabindex="0">
            <ol>
                @foreach ($overview->lines as $line)
                    <li><code>{{ $line }}</code></li>
                @endforeach
            </ol>
        </div>
    @endif

    @if ($placeholders !== [] || $overview->unknown !== [])
        <dl class="hdid-json-legend">
            @foreach ($placeholders as $name => $note)
                <div>
                    <dt><span class="hdid-json-ph">{{ \App\Settings\JsonOverview::placeholder($name) }}</span></dt>
                    <dd>
                        {{ $note }}
                        @unless (in_array($name, $overview->used, true))
                            <span class="hdid-json-unused">· {{ __('not used in this body') }}</span>
                        @endunless
                    </dd>
                </div>
            @endforeach
            @foreach ($overview->unknown as $name)
                <div>
                    <dt><span class="hdid-json-ph hdid-json-ph-unknown">{{ \App\Settings\JsonOverview::placeholder($name) }}</span></dt>
                    <dd>{{ __('Unknown placeholder: Save refuses it.') }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if ($overview->valid)
        <div>
            <x-filament::button
                size="sm"
                color="gray"
                icon="heroicon-m-clipboard-document"
                x-on:click="navigator.clipboard?.writeText({{ \Illuminate\Support\Js::from($overview->formatted) }}).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {})"
            >
                <span x-show="! copied">{{ __('Copy the formatted text') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </x-filament::button>
        </div>
    @endif
</div>
