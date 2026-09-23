<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';
import { t } from '../i18n';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    text: { type: String, default: '' },
    confirmLabel: { type: String, default: '' },
    cancelLabel: { type: String, default: '' },
    danger: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'cancel']);

const cancelButton = ref(null);

function onKey(event) {
    if (event.key === 'Escape') emit('cancel');
}

watch(() => props.open, async (open) => {
    if (open) {
        document.addEventListener('keydown', onKey);
        await nextTick();
        cancelButton.value?.focus();
    } else {
        document.removeEventListener('keydown', onKey);
    }
});

onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-4 backdrop-blur-sm sm:items-center" @click.self="emit('cancel')">
                <div class="card w-full max-w-md p-6" role="dialog" aria-modal="true" :aria-label="title">
                    <h2 class="text-lg font-bold text-ink">{{ title }}</h2>
                    <p v-if="text" class="mt-2 text-sm text-ink-muted">{{ text }}</p>
                    <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button ref="cancelButton" type="button" class="btn-secondary" @click="emit('cancel')">{{ cancelLabel || t('Stay') }}</button>
                        <button type="button" :class="danger ? 'btn-danger' : 'btn-primary'" @click="emit('confirm')">{{ confirmLabel || t('Continue') }}</button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
