<div x-data="{ copied: null }" class="space-y-2">
    <div class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Placeholders') }} <span class="font-normal text-gray-500">— {{ __('click to copy, then paste into the text') }}</span></div>
    <div class="flex flex-wrap gap-2">
        @foreach ($placeholders as $name => $description)
            @php($tag = '{'.'{ '.$name.' }'.'}')
            <button
                type="button"
                title="{{ $description }}"
                x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($tag) }}); copied = {{ \Illuminate\Support\Js::from($name) }}; setTimeout(() => copied = null, 1500)"
                class="rounded-full border border-gray-200 bg-white px-3 py-1 font-mono text-xs text-gray-700 hover:border-primary-500 hover:text-primary-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-200"
                :class="copied === {{ \Illuminate\Support\Js::from($name) }} ? '!border-success-500 !text-success-600' : ''"
            >
                <span x-show="copied !== {{ \Illuminate\Support\Js::from($name) }}">{{ $tag }}</span>
                <span x-show="copied === {{ \Illuminate\Support\Js::from($name) }}" x-cloak>{{ __('copied') }}</span>
            </button>
        @endforeach
    </div>
</div>
