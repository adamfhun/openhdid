{{-- Where the running build's source is published (AGPL-3.0 section 13); nothing without HDID_SOURCE_URL. --}}
@if ($url)
    <div class="hdid-source-link">
        <a href="{{ $url }}" target="_blank" rel="noopener">{{ __('Source code') }}</a>
    </div>
@endif
