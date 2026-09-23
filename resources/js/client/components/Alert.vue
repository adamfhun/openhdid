<script setup>
defineProps({ type: { type: String, default: 'info' }, message: String });
const classes = {
    info: 'bg-brand-soft text-brand-deep',
    success: 'bg-emerald-50 text-emerald-700',
    error: 'bg-red-50 text-red-700',
};
</script>

<template>
    <div v-if="message" class="rounded-xl px-4 py-3 text-sm" :class="classes[type]">{{ message }}</div>
</template>
