<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { store } from '../store';
import { t } from '../i18n';
import Icon from '../components/Icon.vue';
import NewsList from '../components/NewsList.vue';
import SupportCard from '../components/SupportCard.vue';
import ConfirmModal from '../components/ConfirmModal.vue';

const router = useRouter();
const logoutAllConfirm = ref(false);

async function logoutEverywhere() {
    logoutAllConfirm.value = false;
    await store.logoutAll();
    router.push(store.entry === 'premium' ? '/premium/login' : '/login');
}

const id = computed(() => store.user?.identification ?? {});
const phones = computed(() => store.user?.phone_numbers ?? []);
const premiumLabel = computed(() => store.premiumBadgeLabel || t('Premium client'));

// The identification profile: the questions (counted proportionally) and
// the PIN make up the 100 %; phone numbers and the one-time code are optional.
const questionProgress = computed(() => {
    const required = id.value.required ?? 0;
    return required ? Math.min(1, (id.value.answered ?? 0) / required) : 0;
});

const steps = computed(() => [
    { to: '/questions', icon: 'question', title: t('Security questions'), progress: questionProgress.value, done: !!id.value.eligible,
      status: id.value.eligible ? t('Ready') : t('{n} of {r} answered', { n: id.value.answered ?? 0, r: id.value.required ?? 0 }),
      text: t('The agent will ask you one of your own questions and compare your answer.') },
    { to: '/pin', icon: 'key', title: t('PIN'), progress: store.user?.has_pin ? 1 : 0, done: !!store.user?.has_pin, tag: t('Recommended'),
      status: store.user?.has_pin ? t('Set') : t('Not set'),
      text: !store.user?.has_pin && !id.value.pin_changes_enabled ? t('Contact the helpdesk to set your PIN.') : t('Type your PIN into the phone menu to be identified before you reach an agent.') },
    { to: '/phones', icon: 'phone', title: t('Phone numbers'), done: phones.value.length > 0, optional: true, tag: t('Optional'),
      status: phones.value.length ? t('{n} on file', { n: phones.value.length }) : t('None'),
      text: t('Calling from a known number lets the helpdesk find you instantly.') },
    { to: '/mobile-code', icon: 'code', title: t('Identification code'), done: true, optional: true, tag: t('Optional'),
      status: t('On demand'),
      text: t('Generate a short one-time code and read it to the agent or the phone menu.') },
]);

const scored = computed(() => steps.value.filter((s) => !s.optional));
const doneCount = computed(() => scored.value.filter((s) => s.done).length);
const readiness = computed(() => Math.round((scored.value.reduce((sum, s) => sum + s.progress, 0) / scored.value.length) * 100));
const nextStep = computed(() => scored.value.find((s) => !s.done) ?? null);
// Unfinished required steps first, then the finished ones, the optional extras last.
const orderedSteps = computed(() => [...scored.value.filter((s) => !s.done), ...scored.value.filter((s) => s.done), ...steps.value.filter((s) => s.optional)]);

const circumference = 2 * Math.PI * 34;
</script>

<template>
    <div class="space-y-6">
        <section class="card relative overflow-hidden p-6 sm:p-8" :class="{ 'premium-card premium-hero': store.isPremium }">
            <div class="absolute -right-16 -top-16 h-56 w-56 rounded-full" :class="store.isPremium ? 'premium-glow' : 'bg-brand-soft'" aria-hidden="true"></div>
            <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center">
                <div class="flex shrink-0 flex-col items-center gap-1 text-center" role="group" :aria-label="t('Identification profile')">
                    <div class="relative h-24 w-24" :class="{ 'readiness-shimmer': store.isPremium }">
                        <svg viewBox="0 0 80 80" class="h-24 w-24 -rotate-90" aria-hidden="true">
                            <circle cx="40" cy="40" r="34" class="stroke-slate-200" stroke-width="8" fill="none" />
                            <circle cx="40" cy="40" r="34" class="transition-all duration-700" :class="readiness === 100 ? 'stroke-emerald-500' : (store.isPremium ? 'stroke-premium' : 'stroke-brand')" stroke-width="8" fill="none" stroke-linecap="round"
                                :stroke-dasharray="circumference" :stroke-dashoffset="circumference * (1 - readiness / 100)" />
                        </svg>
                        <span class="absolute inset-0 flex items-center justify-center text-lg font-bold" :class="readiness === 100 ? 'text-emerald-600' : (store.isPremium ? 'text-premium-deep' : 'text-brand-deep')">
                            <Icon v-if="readiness === 100" name="check" class="h-8 w-8" />
                            <template v-else>{{ readiness }}%</template>
                        </span>
                    </div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-ink">{{ t('Identification profile') }}</span>
                    <span class="text-xs text-ink-muted">{{ t('{n} of {r} steps done', { n: doneCount, r: scored.length }) }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p v-if="store.isPremium" class="premium-badge mb-2"><Icon name="crown" class="h-3.5 w-3.5" />{{ premiumLabel }}</p>
                    <h1 class="text-2xl font-bold tracking-tight text-ink">{{ t('Hello, {name}', { name: store.user?.name ?? '' }) }}</h1>
                    <p class="mt-1 text-sm text-ink-muted">
                        <template v-if="store.isPremium">{{ t('As a premium client you are served with priority. ') }}</template>{{ nextStep ? t('Complete the steps below so the helpdesk recognises you right away. The PIN is recommended, phone numbers are optional.') : t('You are all set. The helpdesk can recognise you in every way.') }}
                    </p>
                    <div v-if="nextStep" class="mt-4 flex flex-wrap items-center gap-3">
                        <RouterLink :to="nextStep.to" class="btn-primary">{{ t('Next step: {title}', { title: nextStep.title }) }}</RouterLink>
                        <span class="text-sm text-ink-muted">{{ nextStep.status }}</span>
                    </div>
                </div>
            </div>
        </section>

        <SupportCard />

        <section v-if="store.newsOnOverview" class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-ink">{{ t('News / Information') }}</h2>
                <RouterLink to="/news" class="text-sm text-brand-accent hover:underline">{{ t('All news') }}</RouterLink>
            </div>
            <NewsList :limit="3" compact />
        </section>

        <div class="grid gap-4 sm:grid-cols-2">
            <RouterLink v-for="step in orderedSteps" :key="step.to" :to="step.to" class="card group block p-5 transition hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none">
                <div class="flex items-start justify-between gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl" :class="step.done ? 'bg-emerald-50 text-emerald-600' : 'bg-brand-soft text-brand-deep'">
                        <Icon :name="step.done && !step.optional ? 'check' : step.icon" class="h-5 w-5" />
                    </span>
                    <span class="badge" :class="step.done ? 'bg-emerald-50 text-emerald-700' : (step.optional ? 'bg-slate-100 text-ink-muted' : 'bg-amber-50 text-amber-700')">{{ step.status }}</span>
                </div>
                <h2 class="mt-4 font-semibold text-ink group-hover:text-brand-deep">{{ step.title }}<span v-if="step.tag" class="ml-2 align-middle text-xs font-normal text-ink-muted">{{ step.tag }}</span></h2>
                <p class="mt-1 text-sm text-ink-muted">{{ step.text }}</p>
            </RouterLink>
        </div>

        <p class="text-center text-xs text-ink-muted">
            <button type="button" class="underline-offset-2 hover:text-brand-deep hover:underline focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none" @click="logoutAllConfirm = true">{{ t('Sign out on every device') }}</button>
        </p>
        <ConfirmModal :open="logoutAllConfirm" :title="t('Sign out on every device?')" :text="t('Every browser session and mobile app login of yours ends now. You can sign in again any time.')" :confirm-label="t('Sign out everywhere')" danger @confirm="logoutEverywhere" @cancel="logoutAllConfirm = false" />
    </div>
</template>
