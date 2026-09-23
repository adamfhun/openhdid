<script setup>
defineProps({ lines: { type: Number, default: 3 }, card: { type: Boolean, default: true } });
</script>

<template>
    <div :class="card ? 'card p-5' : ''" aria-busy="true" aria-hidden="true">
        <div class="animate-pulse space-y-3">
            <div v-for="n in lines" :key="n" class="h-4 rounded-full bg-slate-200/80" :class="n === 1 ? 'w-2/3' : n === lines ? 'w-1/3' : 'w-full'"></div>
        </div>
    </div>
</template>
