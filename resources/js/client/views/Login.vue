<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { store } from '../store';
import { post, ApiError } from '../api';
import { t } from '../i18n';
import Alert from '../components/Alert.vue';
import Icon from '../components/Icon.vue';

const route = useRoute();
const router = useRouter();

const errorMessages = {
    method_disabled: 'This login method is not available.',
    no_account: 'No account exists for this identity.',
    account_closed: 'This account has been closed.',
    no_external_record: 'This account is not present in Enterprise Master Data.',
    external_record_missing: 'This account is not present in Enterprise Master Data.',
    external_record_mismatch: 'This account does not match its Enterprise Master Data record.',
    no_permission: 'This account has no access.',
    not_entitled: 'Your package does not include access to the client portal.',
    invalid_credentials: 'The login link or code is not valid or has expired.',
    provider_unavailable: 'The sign-in service is not available right now. Please try again later.',
    session_expired: 'Your session has expired. Please sign in again.',
};

const email = ref('');
const code = ref('');
const mode = ref(store.loginMethods.magic_link ? 'magic' : 'otp');
const otpSent = ref(false);
const busy = ref(false);
const notice = ref(null);
const error = ref(null);
const codeInput = ref(null);

const reasonOf = (value) => (value && errorMessages[value] ? value : null);
const errorReason = ref(reasonOf(route.query.error) ?? reasonOf(store.notice));

function refreshError() {
    error.value = errorReason.value ? t(errorMessages[errorReason.value]) : null;
}

const methods = computed(() => store.loginMethods);
const hasPasswordless = computed(() => methods.value.magic_link || methods.value.otp_sms);
// Company sign-on is the primary path whenever it is enabled; the e-mail link and
// SMS code stay folded behind a small link until the client asks for them.
const hasSso = computed(() => methods.value.adfs || methods.value.entra);
const passwordlessOpen = ref(false);
const showPasswordless = computed(() => hasPasswordless.value && (!hasSso.value || passwordlessOpen.value));
const isPremium = computed(() => store.isPremium);
const premiumLabel = computed(() => store.premiumBadgeLabel || t('Premium client'));
const loginBackground = computed(() => store.tierTheme.login_background_image);
const showSupport = computed(() => store.support && (errorReason.value === 'not_entitled' || isPremium.value));

const perks = [
    { icon: 'question', text: 'Answer a few personal questions the agent can check.' },
    { icon: 'key', text: 'Set a PIN for the phone menu and skip the questions.' },
    { icon: 'code', text: 'Generate a one-time code in the app while you call.' },
];

onMounted(() => {
    refreshError();
    store.notice = null;
    // The error came in the address bar (magic link, SSO): show it once, then
    // drop it so that a refresh does not repeat it.
    if (route.query.error) router.replace({ path: route.path, query: { ...route.query, error: undefined } });
});

watch(() => store.notice, (value) => {
    if (reasonOf(value)) { errorReason.value = value; refreshError(); store.notice = null; }
});

async function requestMagicLink() {
    busy.value = true; error.value = null; errorReason.value = null;
    try {
        const r = await post('/client/auth/magic-link', { email: email.value });
        notice.value = r.message;
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

async function requestOtp() {
    busy.value = true; error.value = null; errorReason.value = null;
    try {
        const r = await post('/client/auth/otp/request', { email: email.value });
        notice.value = r.message; otpSent.value = true;
        setTimeout(() => codeInput.value?.focus(), 50);
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

async function verifyOtp() {
    busy.value = true; error.value = null; errorReason.value = null;
    try {
        await post('/client/auth/otp/verify', { email: email.value, code: code.value });
        await store.refreshUser();
        router.push(route.query.next ?? '/');
    } catch (e) {
        error.value = e instanceof ApiError && e.reason ? t(errorMessages[e.reason] ?? e.message) : e.message;
    } finally { busy.value = false; }
}
</script>

<template>
    <div class="card grid w-full overflow-hidden md:grid-cols-[1.1fr_1fr]" :class="{ 'premium-card': isPremium }">
        <section class="relative hidden flex-col justify-between p-10 text-white md:flex" :class="isPremium ? 'bg-premium-deep' : 'bg-brand'">
            <div v-if="loginBackground" class="absolute inset-0 bg-cover bg-center" :style="{ backgroundImage: `url('${loginBackground}')` }" aria-hidden="true"></div>
            <div class="absolute inset-0" :class="loginBackground ? 'bg-slate-900/55' : 'opacity-30'" :style="loginBackground ? '' : 'background: radial-gradient(circle at 20% 20%, white, transparent 45%), radial-gradient(circle at 90% 80%, black, transparent 50%);'" aria-hidden="true"></div>
            <div v-if="isPremium" class="premium-glow absolute -right-24 -top-24 h-72 w-72 rounded-full" aria-hidden="true"></div>
            <div class="relative">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-wide"><Icon name="shield" class="h-4 w-4" />{{ store.branding.app_name }}</span>
                <span v-if="isPremium" class="premium-badge ml-2 align-middle"><Icon name="crown" class="h-3.5 w-3.5" />{{ premiumLabel }}</span>
                <h2 class="mt-6 text-3xl font-bold leading-tight">{{ isPremium ? t('Welcome back. Your priority line is one code away.') : t('Be recognised in seconds when you call the helpdesk.') }}</h2>
                <p class="mt-3 text-white/80">{{ t('Choose how the helpdesk should verify it is really you. No passwords to remember.') }}</p>
            </div>
            <ul class="relative space-y-3">
                <li v-for="perk in perks" :key="perk.icon" class="flex items-start gap-3 text-sm">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/15"><Icon :name="perk.icon" class="h-4 w-4" /></span>
                    <span class="pt-1.5">{{ t(perk.text) }}</span>
                </li>
            </ul>
        </section>

        <section class="p-8 sm:p-10">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-2xl font-bold tracking-tight text-ink">{{ t('Sign in') }}</h1>
                <span v-if="isPremium" class="premium-badge md:hidden"><Icon name="crown" class="h-3.5 w-3.5" />{{ premiumLabel }}</span>
            </div>
            <p class="mt-1 text-sm text-ink-muted">{{ t('Manage how the helpdesk recognises you when you call.') }}</p>

            <div class="mt-6 space-y-4">
                <Alert type="error" :message="error" />
                <Alert type="success" :message="notice" />

                <template v-if="hasSso">
                    <a v-if="methods.adfs" class="btn-primary w-full" href="/auth/client/adfs/redirect"><Icon name="shield" class="h-4 w-4" />{{ t('Sign in with your company account') }}</a>
                    <a v-if="methods.entra" class="w-full" :class="methods.adfs ? 'btn-secondary' : 'btn-primary'" href="/auth/client/entra/redirect">{{ t('Sign in with Microsoft') }}</a>
                    <p v-if="hasPasswordless && !passwordlessOpen" class="text-center text-xs text-ink-muted">
                        <button type="button" class="underline-offset-2 hover:text-brand-deep hover:underline focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none" @click="passwordlessOpen = true">{{ methods.magic_link && methods.otp_sms ? t('Sign in with an e-mail link or SMS code instead') : (methods.magic_link ? t('Sign in with an e-mail link instead') : t('Sign in with an SMS code instead')) }}</button>
                    </p>
                </template>

                <div v-if="showPasswordless" class="space-y-4">
                    <div v-if="methods.magic_link && methods.otp_sms" class="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm" role="tablist">
                        <button type="button" role="tab" :aria-selected="mode === 'magic'" class="flex-1 rounded-lg py-1.5 font-medium" :class="mode === 'magic' ? 'bg-white shadow-sm' : 'text-ink-muted'" @click="mode = 'magic'">{{ t('E-mail link') }}</button>
                        <button type="button" role="tab" :aria-selected="mode === 'otp'" class="flex-1 rounded-lg py-1.5 font-medium" :class="mode === 'otp' ? 'bg-white shadow-sm' : 'text-ink-muted'" @click="mode = 'otp'">{{ t('SMS code') }}</button>
                    </div>

                    <form v-if="mode === 'magic' && methods.magic_link" class="space-y-3" @submit.prevent="requestMagicLink">
                        <div><label class="label" for="email">{{ t('E-mail address') }}</label><input id="email" v-model="email" type="email" required class="input" autocomplete="email" autofocus /></div>
                        <button class="btn-primary w-full" :disabled="busy"><Icon name="mail" class="h-4 w-4" />{{ busy ? t('Sending…') : t('Send me a login link') }}</button>
                    </form>

                    <form v-else-if="methods.otp_sms" class="space-y-3" @submit.prevent="otpSent ? verifyOtp() : requestOtp()">
                        <div><label class="label" for="email2">{{ t('E-mail address') }}</label><input id="email2" v-model="email" type="email" required class="input" :disabled="otpSent" autocomplete="email" /></div>
                        <div v-if="otpSent"><label class="label" for="code">{{ t('Code from the SMS') }}</label><input id="code" ref="codeInput" v-model="code" inputmode="numeric" required class="input tracking-[0.4em]" autocomplete="one-time-code" /></div>
                        <button class="btn-primary w-full" :disabled="busy"><Icon name="chat" class="h-4 w-4" />{{ otpSent ? t('Sign in') : t('Send me a code') }}</button>
                        <button v-if="otpSent" type="button" class="w-full text-center text-xs text-ink-muted hover:underline" @click="otpSent = false; code = ''">{{ t('Use a different e-mail') }}</button>
                    </form>
                </div>

                <p v-if="!hasPasswordless && !hasSso" class="text-sm text-ink-muted">{{ t('No login method is enabled. Please contact support.') }}</p>

                <div v-if="showSupport" class="rounded-xl px-4 py-3 text-sm" :class="isPremium ? 'bg-premium-soft text-ink' : 'bg-slate-50 text-ink'">
                    <div class="font-semibold">{{ isPremium ? t('Premium support') : t('Support') }}</div>
                    <p class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-ink-muted">
                        <a v-if="store.support.phone" :href="'tel:' + store.support.phone.replace(/\s+/g, '')" class="hover:underline">{{ store.support.phone }}</a>
                        <a v-if="store.support.email" :href="'mailto:' + store.support.email" class="hover:underline">{{ store.support.email }}</a>
                        <span v-if="store.support.hours" class="whitespace-pre-line">{{ store.support.hours }}</span>
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
