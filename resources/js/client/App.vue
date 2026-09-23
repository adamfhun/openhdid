<script setup>
import { computed, ref } from 'vue';
import { store } from './store';
import { i18n, t } from './i18n';
import { useRoute, useRouter } from 'vue-router';
import Icon from './components/Icon.vue';
import Toast from './components/Toast.vue';

const router = useRouter();
const route = useRoute();

const nav = [
    { to: '/', label: 'Overview', icon: 'home' },
    { to: '/questions', label: 'Security questions', icon: 'question' },
    { to: '/pin', label: 'PIN', icon: 'key' },
    { to: '/phones', label: 'Phone numbers', icon: 'phone' },
    { to: '/mobile-code', label: 'Identification code', icon: 'code' },
    { to: '/news', label: 'News / Information', icon: 'sparkle' },
];

const portal = () => store.branding.portal ?? {};

const otherLocale = () => (i18n.locale === 'hu' ? 'en' : 'hu');

const premiumLabel = computed(() => store.premiumBadgeLabel || t('Premium client'));

async function logout() {
    await store.logout();
    // store.logout() keeps the last known level as the entrance, so a premium
    // client lands on the premium login page even after a magic-link login.
    router.push(store.entry === 'premium' ? '/premium/login' : '/login');
}

const retrying = ref(false);

async function retry() {
    retrying.value = true;
    try {
        if (await store.retryBoot()) {
            const { path, query, hash } = router.currentRoute.value;
            router.replace({ path, query, hash, force: true });
        }
    } finally { retrying.value = false; }
}
</script>

<template>
    <div class="min-h-screen">
        <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:shadow">{{ t('Skip to content') }}</a>

        <header class="sticky top-0 z-20 border-b border-slate-200/70 bg-white/80 backdrop-blur" :class="{ 'premium-header': store.isPremium }">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <RouterLink :to="store.user ? '/' : (store.entry === 'premium' ? '/premium/login' : '/login')" class="flex items-center gap-3" :aria-label="store.branding.app_name">
                    <span class="brand-mark" :class="{ 'brand-mark-premium': store.isPremium }">
                        <img v-if="store.branding.logo_url" :src="store.branding.logo_url" alt="" class="h-9 w-auto rounded-lg" />
                        <span v-else class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand text-lg font-bold text-white shadow-sm">
                            {{ store.branding.app_name?.slice(0, 1) }}
                        </span>
                    </span>
                    <span class="text-lg font-bold tracking-tight text-ink">{{ store.branding.app_name }}</span>
                    <span v-if="store.isPremium" class="premium-badge hidden sm:inline-flex"><Icon name="crown" class="h-3.5 w-3.5" />{{ premiumLabel }}</span>
                </RouterLink>

                <div class="flex items-center gap-2 text-sm">
                    <button class="rounded-lg px-2 py-1 text-xs font-semibold uppercase tracking-wide text-ink-muted hover:bg-slate-100 hover:text-brand-deep" :aria-label="t('Switch language')" @click="i18n.set(otherLocale())">{{ otherLocale() }}</button>
                    <template v-if="store.user">
                        <span class="hidden rounded-full bg-slate-100 px-3 py-1 text-slate-600 sm:inline">{{ store.user.name }}</span>
                        <button class="btn-secondary !px-3" :title="t('Sign out')" :aria-label="t('Sign out')" @click="logout"><Icon name="logout" class="h-4 w-4" /><span class="hidden sm:inline">{{ t('Sign out') }}</span></button>
                    </template>
                </div>
            </div>
        </header>

        <Toast />

        <main v-if="store.networkError" id="main" class="mx-auto flex min-h-[calc(100vh-4rem)] max-w-6xl items-center justify-center px-4 py-8 sm:px-6">
            <div class="card w-full max-w-md p-8 text-center" role="alert">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600"><Icon name="alert" class="h-6 w-6" /></div>
                <h1 class="mt-4 text-xl font-bold text-ink">{{ t('The portal cannot be reached right now.') }}</h1>
                <p class="mt-2 text-sm text-ink-muted">{{ t('Check your connection, or try again in a moment. If the problem persists, contact support.') }}</p>
                <button class="btn-primary mt-6" :disabled="retrying" @click="retry"><Icon name="refresh" class="h-4 w-4" />{{ retrying ? t('Retrying…') : t('Try again') }}</button>
                <div v-if="store.support" class="mt-6 text-xs text-ink-muted">
                    <a v-if="store.support.phone" :href="'tel:' + store.support.phone.replace(/\s+/g, '')" class="hover:underline">{{ store.support.phone }}</a>
                    <span v-if="store.support.phone && store.support.email"> · </span>
                    <a v-if="store.support.email" :href="'mailto:' + store.support.email" class="hover:underline">{{ store.support.email }}</a>
                </div>
            </div>
        </main>

        <div v-else-if="store.user" class="mx-auto grid max-w-6xl gap-8 px-4 py-8 sm:px-6 md:grid-cols-[240px_1fr]">
            <nav class="hidden self-start md:block" :aria-label="t('Main navigation')">
                <RouterLink
                    v-for="item in nav"
                    :key="item.to"
                    :to="item.to"
                    class="mb-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-white hover:text-brand-deep hover:shadow-sm"
                    active-class="!bg-white !text-brand-deep shadow-sm ring-1 ring-slate-900/5"
                >
                    <Icon :name="item.icon" class="h-5 w-5" />
                    {{ t(item.label) }}
                </RouterLink>
            </nav>
            <main id="main" class="min-w-0 pb-20 md:pb-0"><RouterView /></main>

            <nav class="fixed inset-x-0 bottom-0 z-20 grid grid-cols-6 border-t border-slate-200 bg-white/95 backdrop-blur md:hidden" :aria-label="t('Main navigation')">
                <RouterLink v-for="item in nav" :key="item.to" :to="item.to" class="flex flex-col items-center gap-1 py-2 text-[10px] font-medium text-ink-muted" active-class="!text-brand-deep">
                    <Icon :name="item.icon" class="h-5 w-5" />
                    <span class="truncate px-1">{{ t(item.label) }}</span>
                </RouterLink>
            </nav>
        </div>
        <!-- Signed out: only guest pages may render here. Between forgetUser() and the
             redirect to the login page the route is still the protected one, and
             re-rendering it with a null user would crash the page. -->
        <main v-else id="main" class="mx-auto flex min-h-[calc(100vh-4rem)] max-w-6xl items-center px-4 py-8 sm:px-6"><RouterView v-if="route.meta.guest" /></main>

        <footer class="mt-8 border-t border-slate-200/70 bg-surface/70">
            <div class="mx-auto max-w-6xl px-4 py-8 text-xs text-ink-muted sm:px-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="max-w-2xl space-y-1">
                        <div class="font-semibold text-ink">{{ portal().legal_name || store.branding.app_name }}</div>
                        <p v-if="portal().footer_text" class="whitespace-pre-line leading-relaxed">{{ portal().footer_text }}</p>
                        <div v-if="store.support" class="space-y-0.5 pt-1">
                            <div class="font-semibold text-ink">{{ store.isPremium ? t('Premium support') : t('Support') }}</div>
                            <p class="flex flex-wrap gap-x-3 gap-y-0.5">
                                <a v-if="store.support.email" :href="'mailto:' + store.support.email" class="hover:text-brand-accent hover:underline">{{ store.support.email }}</a>
                                <a v-if="store.support.phone" :href="'tel:' + store.support.phone.replace(/\s+/g, '')" class="hover:text-brand-accent hover:underline">{{ store.support.phone }}</a>
                                <span v-if="store.support.hours" class="whitespace-pre-line">{{ store.support.hours }}</span>
                            </p>
                        </div>
                    </div>
                    <nav class="flex flex-wrap gap-4" :aria-label="t('Legal')">
                        <a v-if="portal().privacy_url" :href="portal().privacy_url" target="_blank" rel="noopener" class="hover:text-brand-accent hover:underline">{{ t('Privacy policy') }}</a>
                        <a v-if="portal().terms_url" :href="portal().terms_url" target="_blank" rel="noopener" class="hover:text-brand-accent hover:underline">{{ t('Terms of use') }}</a>
                        <a v-if="portal().imprint_url" :href="portal().imprint_url" target="_blank" rel="noopener" class="hover:text-brand-accent hover:underline">{{ t('Imprint') }}</a>
                        <a v-if="portal().source_url" :href="portal().source_url" target="_blank" rel="noopener" class="hover:text-brand-accent hover:underline">{{ t('Source code') }}</a>
                    </nav>
                </div>
                <p class="mt-4">© {{ new Date().getFullYear() }} {{ portal().legal_name || store.branding.app_name }}</p>
            </div>
        </footer>
    </div>
</template>
