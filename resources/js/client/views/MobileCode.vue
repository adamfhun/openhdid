<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import { get, post } from '../api';
import { store } from '../store';
import PageHeader from '../components/PageHeader.vue';
import ConfirmModal from '../components/ConfirmModal.vue';
import Skeleton from '../components/Skeleton.vue';
import Icon from '../components/Icon.vue';
import { t } from '../i18n';

const LEAVE_GUARD_MS = 60_000;

const code = ref(null);
const formatted = ref(null);
const expiresAt = ref(null);
const ttlMinutes = ref(5);
const remaining = ref(0);
const loaded = ref(false);
const busy = ref(false);
const expired = ref(false);
const copied = ref(false);
const identified = ref(null);
let timer = null;
let poll = null;

const leaveConfirm = ref(false);
let pendingLeave = null;
const regenerateConfirm = ref(false);

const pairs = computed(() => (formatted.value ? formatted.value.split(' ') : []));
const total = computed(() => ttlMinutes.value * 60);
const fraction = computed(() => (total.value ? remaining.value / total.value : 0));
const clock = computed(() => Math.floor(remaining.value / 60) + ':' + String(remaining.value % 60).padStart(2, '0'));
const circumference = 2 * Math.PI * 22;

const recentlyGenerated = () => store.codeGeneratedAt !== null && Date.now() - store.codeGeneratedAt < LEAVE_GUARD_MS;

function apply(r) {
    code.value = r.code; formatted.value = r.formatted; expiresAt.value = r.expires_at; ttlMinutes.value = r.ttl_minutes ?? 5;
    clearInterval(timer);
    if (code.value) { expired.value = false; identified.value = null; timer = setInterval(tick, 1000); tick(); startPolling(); }
    else if (r.identified && (!store.codeGeneratedAt || new Date(r.identified.at) >= new Date(store.codeGeneratedAt - 5000))) { markIdentified(r.identified); }
}

function markIdentified(info) {
    identified.value = info;
    expired.value = false;
    store.codeGeneratedAt = null;
    stopPolling();
    clearInterval(timer);
}

/* While a code is live, ask the server every few seconds whether the helpdesk accepted it. */
function startPolling() {
    stopPolling();
    poll = setInterval(async () => {
        try {
            const r = await get('/client/mobile-code');
            if (r.identified && !r.code) { code.value = null; formatted.value = null; markIdentified(r.identified); }
            else if (!r.code) { stopPolling(); }
        } catch { /* keep the local countdown */ }
    }, 4000);
}

function stopPolling() { clearInterval(poll); poll = null; }

function tick() {
    remaining.value = Math.max(0, Math.round((new Date(expiresAt.value) - Date.now()) / 1000));
    if (remaining.value === 0) { code.value = null; formatted.value = null; expired.value = true; clearInterval(timer); }
}

async function loadCurrent() {
    try { apply(await get('/client/mobile-code')); }
    catch (e) { store.toast(e.message, 'error'); }
    finally { loaded.value = true; }
}

async function generate() {
    regenerateConfirm.value = false;
    busy.value = true; copied.value = false; identified.value = null;
    try {
        apply(await post('/client/mobile-code'));
        store.codeGeneratedAt = Date.now();
        store.toast(t('New code generated. It is valid for {n} minutes.', { n: ttlMinutes.value }));
    } catch (e) { store.toast(e.message, 'error'); } finally { busy.value = false; }
}

function askGenerate() {
    if (code.value) regenerateConfirm.value = true;
    else generate();
}

async function copy() {
    const text = formatted.value ?? code.value;
    try {
        if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(text);
        else {
            const area = document.createElement('textarea');
            area.value = text; area.setAttribute('readonly', ''); area.style.position = 'absolute'; area.style.left = '-9999px';
            document.body.appendChild(area); area.select(); document.execCommand('copy'); document.body.removeChild(area);
        }
        copied.value = true;
        store.toast(t('Code copied.'));
        setTimeout(() => (copied.value = false), 2000);
    } catch { store.toast(t('Could not copy the code.'), 'error'); }
}

function onBeforeUnload(event) {
    if (recentlyGenerated()) { event.preventDefault(); event.returnValue = ''; }
}

onBeforeRouteLeave((to, from, next) => {
    if (!recentlyGenerated()) return next();
    pendingLeave = next;
    leaveConfirm.value = true;
});

function confirmLeave() { leaveConfirm.value = false; pendingLeave?.(); pendingLeave = null; }
function stay() { leaveConfirm.value = false; pendingLeave?.(false); pendingLeave = null; }

onMounted(() => { loadCurrent(); window.addEventListener('beforeunload', onBeforeUnload); });
onUnmounted(() => { clearInterval(timer); stopPolling(); window.removeEventListener('beforeunload', onBeforeUnload); });
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('Identification code')" :subtitle="t('Generate a one-time code while you are on the phone and read it to the agent or type it into the phone menu.')" icon="code" />

        <Skeleton v-if="!loaded" :lines="3" />

        <div v-else class="card relative overflow-hidden p-6 sm:p-8" :class="{ 'premium-card': store.isPremium }">
            <div v-if="code" class="flex flex-col items-center gap-6 text-center">
                <div class="flex flex-wrap items-center justify-center gap-2 sm:gap-3" aria-live="polite" :aria-label="t('Your code is {code}', { code: formatted })">
                    <span v-for="(pair, index) in pairs" :key="index" class="code-pair" :class="{ 'code-pair-premium': store.isPremium }">{{ pair }}</span>
                </div>

                <div class="flex items-center gap-3 text-sm text-ink-muted">
                    <span class="relative h-12 w-12 shrink-0">
                        <svg viewBox="0 0 48 48" class="h-12 w-12 -rotate-90" aria-hidden="true">
                            <circle cx="24" cy="24" r="22" class="stroke-slate-200" stroke-width="4" fill="none" />
                            <circle cx="24" cy="24" r="22" :class="remaining <= 30 ? 'stroke-red-500' : (store.isPremium ? 'stroke-premium' : 'stroke-brand')" stroke-width="4" fill="none" stroke-linecap="round" class="transition-[stroke-dashoffset] duration-1000 ease-linear"
                                :stroke-dasharray="circumference" :stroke-dashoffset="circumference * (1 - fraction)" />
                        </svg>
                        <Icon name="clock" class="absolute inset-0 m-auto h-5 w-5 text-ink-muted" />
                    </span>
                    <span><span class="font-mono text-base font-semibold" :class="remaining <= 30 ? 'text-red-600' : 'text-ink'">{{ clock }}</span><br />{{ t('remaining') }}</span>
                </div>

                <p class="max-w-md text-sm text-ink-muted">{{ t('Read the code to the agent in pairs: 12 – 34 – 56 – 78. It works once and only for a few minutes.') }}</p>

                <div class="flex flex-wrap justify-center gap-2">
                    <button type="button" class="btn-secondary" :aria-label="t('Copy code')" @click="copy"><Icon :name="copied ? 'check' : 'copy'" class="h-4 w-4" />{{ copied ? t('Copied') : t('Copy') }}</button>
                    <button type="button" class="btn-primary" :disabled="busy" @click="askGenerate"><Icon name="refresh" class="h-4 w-4" />{{ t('Generate a new code') }}</button>
                </div>
            </div>

            <div v-else-if="identified" class="flex flex-col items-center gap-4 text-center" role="status" aria-live="polite">
                <span class="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><Icon name="check" class="h-8 w-8" /></span>
                <div>
                    <h2 class="text-xl font-bold text-ink">{{ t('Our colleague has identified you.') }}</h2>
                    <p class="mt-1 max-w-md text-sm text-ink-muted">{{ t('The code has been used and is no longer needed. You can continue the call.') }}</p>
                </div>
                <button type="button" class="btn-secondary" :disabled="busy" @click="generate"><Icon name="refresh" class="h-4 w-4" />{{ t('Generate code') }}</button>
            </div>

            <div v-else class="flex flex-col items-center gap-4 text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl" :class="store.isPremium ? 'bg-premium-soft text-premium-deep' : 'bg-brand-soft text-brand-deep'"><Icon name="code" class="h-7 w-7" /></span>
                <div>
                    <h2 class="font-semibold text-ink">{{ expired ? t('Your code has expired.') : t('No active code.') }}</h2>
                    <p class="mt-1 max-w-md text-sm text-ink-muted">{{ t('Generate one right before you call, or while the agent is waiting. It is valid for {n} minutes.', { n: ttlMinutes }) }}</p>
                </div>
                <button type="button" class="btn-primary" :disabled="busy" @click="generate"><Icon name="refresh" class="h-4 w-4" />{{ busy ? t('Generating…') : t('Generate code') }}</button>
            </div>
        </div>

        <ConfirmModal :open="leaveConfirm" :title="t('Leave this page?')" :text="t('You generated a code less than a minute ago. If you leave, you can come back here to see it while it is valid.')" :confirm-label="t('Leave anyway')" @confirm="confirmLeave" @cancel="stay" />
        <ConfirmModal :open="regenerateConfirm" :title="t('Replace the current code?')" :text="t('Your current code is still valid. Generating a new one makes the old one unusable.')" :confirm-label="t('Generate a new code')" :cancel-label="t('Keep current')" @confirm="generate" @cancel="regenerateConfirm = false" />
    </div>
</template>
