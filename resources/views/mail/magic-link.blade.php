<x-mail::message>
# {{ __('Hello :name', ['name' => $client->name]) }}

{{ __('Use the button below to sign in to :app. The link is valid for :minutes minutes and can be used once.', ['app' => $branding['app_name'], 'minutes' => $ttlMinutes]) }}

<x-mail::button :url="$url">
{{ __('Sign in') }}
</x-mail::button>

{{ __('If you did not request this, you can ignore this e-mail.') }}

{{ $branding['app_name'] }}
</x-mail::message>
