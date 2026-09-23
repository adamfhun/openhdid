<script setup>
import { store } from '../store';
import { t } from '../i18n';
import Icon from './Icon.vue';

defineProps({ compact: { type: Boolean, default: false } });
</script>

<template>
    <section v-if="store.support" class="card p-5 sm:p-6" :class="{ 'premium-card': store.isPremium }">
        <div class="flex items-start gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl" :class="store.isPremium ? 'bg-premium-soft text-premium-deep' : 'bg-brand-soft text-brand-deep'">
                <Icon name="lifebuoy" class="h-6 w-6" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-ink">{{ store.isPremium ? t('Your premium support line') : t('Need help?') }}</h2>
                <p v-if="!compact" class="mt-0.5 text-sm text-ink-muted">{{ store.isPremium ? t('Premium clients reach a dedicated team with priority handling.') : t('Our helpdesk is happy to help with anything on this page.') }}</p>
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                    <div v-if="store.support.phone">
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">{{ t('Phone') }}</dt>
                        <dd><a :href="'tel:' + store.support.phone.replace(/\s+/g, '')" class="font-semibold text-brand-accent hover:underline">{{ store.support.phone }}</a></dd>
                    </div>
                    <div v-if="store.support.email">
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">{{ t('E-mail') }}</dt>
                        <dd><a :href="'mailto:' + store.support.email" class="font-semibold text-brand-accent hover:underline break-all">{{ store.support.email }}</a></dd>
                    </div>
                    <div v-if="store.support.hours">
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">{{ t('Opening hours') }}</dt>
                        <dd class="whitespace-pre-line text-ink">{{ store.support.hours }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>
</template>
