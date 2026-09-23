<script setup>
import Icon from './Icon.vue';
defineProps({ title: String, subtitle: String, icon: String });
</script>

<template>
    <div class="flex items-start gap-4">
        <span v-if="icon" class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-soft text-brand-deep sm:flex">
            <Icon :name="icon" class="h-6 w-6" />
        </span>
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-bold tracking-tight text-ink">{{ title }}</h1>
            <p v-if="subtitle" class="mt-0.5 text-sm text-ink-muted">{{ subtitle }}</p>
        </div>
        <div class="shrink-0"><slot /></div>
    </div>
</template>
