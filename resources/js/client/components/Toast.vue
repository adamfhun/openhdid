<script setup>
import { store } from '../store';
import { t } from '../i18n';
import Icon from './Icon.vue';

const classes = {
    success: 'bg-emerald-600 text-white',
    error: 'bg-red-600 text-white',
    info: 'bg-brand-deep text-white',
};
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-20 z-50 flex flex-col items-center gap-2 px-4" aria-live="polite" role="status">
        <TransitionGroup name="toast">
            <div
                v-for="toast in store.toasts"
                :key="toast.id"
                class="pointer-events-auto flex w-full max-w-md items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium shadow-lg"
                :class="classes[toast.type] ?? classes.info"
            >
                <Icon :name="toast.type === 'error' ? 'alert' : 'check'" class="h-5 w-5 shrink-0" />
                <span class="flex-1">{{ toast.message }}</span>
                <button type="button" class="rounded-lg p-1 hover:bg-white/15" :aria-label="t('Dismiss')" @click="store.dismissToast(toast.id)">
                    <Icon name="close" class="h-4 w-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
