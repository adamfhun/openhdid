<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { store } from '../store';
import { post, ApiError } from '../api';
import { t } from '../i18n';
import { loginErrorMessages } from '../loginErrors';
import Alert from '../components/Alert.vue';
import Icon from '../components/Icon.vue';

/*
 * Landing page of the e-mailed login link. Opening the link signs nobody in:
 * mail scanners that open links in advance would use it up. The client
 * presses the button, which posts the token. The token sits in the address
 * fragment, which never reaches the server log; it is cleared from the
 * address bar at once and kept in the history entry's state, so a reload
 * before the button is pressed still finds it.
 */
const router = useRouter();
const token = ref(null);
const busy = ref(false);
const error = ref(null);

const isPremium = computed(() => store.isPremium);
const loginHref = computed(() => (store.entry === 'premium' ? '/premium/login' : '/login'));
const premiumLabel = computed(() => store.premiumBadgeLabel || t('Premium client'));

function rememberToken(value) {
    history.replaceState({ ...(history.state ?? {}), magicToken: value }, '', window.location.pathname);
}

onMounted(() => {
    const match = window.location.hash.match(/token=([A-Za-z0-9]+)/);
    token.value = match ? match[1] : (history.state?.magicToken ?? null);
    if (window.location.hash || token.value) rememberToken(token.value);
    if (!token.value) error.value = t('This page needs the link from the e-mail: open it again, or ask for a new link on the login page.');
});

async function signIn() {
    busy.value = true; error.value = null;
    try {
        await post('/client/auth/magic-link/consume', { token: token.value });
        token.value = null; rememberToken(null);
        await store.refreshUser();
        router.push('/');
    } catch (e) {
        error.value = e instanceof ApiError && e.reason ? t(loginErrorMessages[e.reason] ?? e.message) : (e.firstError ?? e.message);
        if (e instanceof ApiError && e.status === 422) error.value = t(loginErrorMessages.invalid_credentials);
        // A used, expired or unknown link cannot work on a second press.
        if (e instanceof ApiError && (e.status === 422 || e.reason === 'invalid_credentials')) { token.value = null; rememberToken(null); }
    } finally { busy.value = false; }
}
</script>

<template>
    <div class="card mx-auto w-full max-w-md overflow-hidden" :class="{ 'premium-card': isPremium }">
        <section class="p-8 sm:p-10">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-2xl font-bold tracking-tight text-ink">{{ t('Sign in with the e-mailed link') }}</h1>
                <span v-if="isPremium" class="premium-badge"><Icon name="crown" class="h-3.5 w-3.5" />{{ premiumLabel }}</span>
            </div>
            <p class="mt-1 text-sm text-ink-muted">{{ t('Press the button to sign in. The link works once.') }}</p>

            <div class="mt-6 space-y-4">
                <Alert type="error" :message="error" />
                <button v-if="token" type="button" class="btn-primary w-full" :disabled="busy" @click="signIn"><Icon name="shield" class="h-4 w-4" />{{ busy ? t('Signing in…') : t('Sign in') }}</button>
                <p class="text-center text-xs text-ink-muted"><router-link :to="loginHref" class="hover:underline">{{ t('Back to the login page') }}</router-link></p>
            </div>
        </section>
    </div>
</template>
