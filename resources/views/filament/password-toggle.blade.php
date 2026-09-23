<div class="text-center">
    <button
        type="button"
        wire:click="$set('showPasswordForm', true)"
        class="text-xs text-gray-500 underline-offset-2 hover:underline dark:text-gray-400"
    >
        {{ __('Sign in with a password instead') }}
    </button>
</div>
