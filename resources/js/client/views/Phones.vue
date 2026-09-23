<script setup>
import { ref, computed, nextTick } from 'vue';
import { post, del } from '../api';
import { store } from '../store';
import Icon from '../components/Icon.vue';
import PageHeader from '../components/PageHeader.vue';
import { t } from '../i18n';

const number = ref('');
const label = ref('');
const busy = ref(false);
const removingId = ref(null);
const phones = computed(() => store.user?.phone_numbers ?? []);
const verificationEnabled = computed(() => store.user?.phone_verification === true);
const maxNumbers = computed(() => store.user?.max_phone_numbers ?? 0);
const atCap = computed(() => maxNumbers.value > 0 && phones.value.length >= maxNumbers.value);

/** Number currently being verified, the code typed so far, and the sent-notice. */
const verifyingId = ref(null);
const verifyCode = ref('');
const verifyNotice = ref(null);

const sourceLabel = { sync: 'from Enterprise Master Data', admin: 'added by the helpdesk', self: 'added by you' };

const canVerify = (phone) => verificationEnabled.value && phone.source === 'self' && !phone.verified;

async function makePrimary(phone) {
    busy.value = true;
    try { store.user = (await post(`/client/phone-numbers/${phone.id}/primary`)).data; store.toast(t('Primary number updated.')); }
    catch (e) { store.toast(e.message, 'error'); } finally { busy.value = false; }
}

async function add() {
    busy.value = true;
    try {
        const r = await post('/client/phone-numbers', { number: number.value, label: label.value || null });
        store.user = r.data; number.value = label.value = '';
        store.toast(t('Phone number added.'));
    } catch (e) { store.toast(e.firstError ?? e.message, 'error'); } finally { busy.value = false; }
}

async function remove(phone) {
    busy.value = true;
    try { await del(`/client/phone-numbers/${phone.id}`); removingId.value = null; store.toast(t('Phone number removed.')); await store.refreshUser(); }
    catch (e) { store.toast(e.message, 'error'); } finally { busy.value = false; }
}

async function requestVerification(phone) {
    busy.value = true;
    verifyNotice.value = null;
    try {
        const r = await post(`/client/phone-numbers/${phone.id}/verify/request`);
        verifyingId.value = phone.id; verifyCode.value = '';
        verifyNotice.value = r.message;
        await nextTick();
        document.getElementById(`verify-${phone.id}`)?.focus();
    } catch (e) { store.toast(e.firstError ?? e.message, 'error'); } finally { busy.value = false; }
}

async function verify(phone) {
    busy.value = true;
    try {
        store.user = (await post(`/client/phone-numbers/${phone.id}/verify`, { code: verifyCode.value })).data;
        verifyingId.value = null; verifyCode.value = ''; verifyNotice.value = null;
        store.toast(t('Phone number verified.'));
    } catch (e) { store.toast(e.firstError ?? e.message, 'error'); } finally { busy.value = false; }
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('Phone numbers')" :subtitle="t('When you call from one of these numbers the helpdesk finds you immediately.')" icon="phone" />

        <div class="card divide-y divide-slate-100">
            <div v-for="phone in phones" :key="phone.id" class="p-4">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono font-semibold">{{ phone.number }}</span>
                            <span v-if="phone.verified" class="badge bg-emerald-50 text-emerald-700"><Icon name="check" class="h-3 w-3" />{{ t('verified') }}</span>
                            <span v-else class="badge bg-amber-50 text-amber-700" :title="t('The helpdesk treats an unverified number with less trust when you call from it.')">{{ t('not verified') }}</span>
                        </div>
                        <div class="text-xs text-slate-500">{{ phone.label ? phone.label + ' · ' : '' }}{{ t(sourceLabel[phone.source]) }}<span v-if="phone.is_primary" class="font-semibold text-brand-deep"> · {{ t('primary') }}</span></div>
                    </div>
                    <div v-if="removingId === phone.id" class="flex items-center gap-2 text-sm">
                        <span class="text-red-700">{{ t('Remove?') }}</span>
                        <button class="btn-danger !py-1.5" :disabled="busy" @click="remove(phone)">{{ t('Remove') }}</button>
                        <button class="btn-secondary !py-1.5" @click="removingId = null">{{ t('Cancel') }}</button>
                    </div>
                    <div v-else class="flex flex-wrap justify-end gap-2">
                        <button v-if="canVerify(phone) && verifyingId !== phone.id" class="btn-primary !py-1.5" :disabled="busy" @click="requestVerification(phone)"><Icon name="chat" class="h-4 w-4" />{{ t('Verify') }}</button>
                        <button v-if="!phone.is_primary" class="btn-secondary" :disabled="busy" @click="makePrimary(phone)">{{ t('Make primary') }}</button>
                        <button v-if="phone.source === 'self'" class="btn-danger" :disabled="busy" :aria-label="t('Remove')" @click="removingId = phone.id"><Icon name="trash" class="h-4 w-4" />{{ t('Remove') }}</button>
                    </div>
                </div>

                <form v-if="verifyingId === phone.id" class="mt-3 flex flex-col gap-2 rounded-xl bg-slate-50 p-3 sm:flex-row sm:items-end" @submit.prevent="verify(phone)">
                    <div class="flex-1">
                        <label class="label" :for="`verify-${phone.id}`">{{ t('Code from the SMS') }}</label>
                        <input :id="`verify-${phone.id}`" v-model="verifyCode" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" class="input tracking-[0.4em]" required @input="verifyCode = verifyCode.replace(/\D/g, '')" />
                        <p v-if="verifyNotice" class="mt-1 text-xs text-ink-muted">{{ verifyNotice }}</p>
                    </div>
                    <div class="flex gap-2">
                        <button class="btn-primary" :disabled="busy || verifyCode.length !== 6">{{ t('Confirm') }}</button>
                        <button type="button" class="btn-secondary" :disabled="busy" @click="requestVerification(phone)">{{ t('Send again') }}</button>
                        <button type="button" class="btn-secondary" @click="verifyingId = null">{{ t('Cancel') }}</button>
                    </div>
                </form>
            </div>
            <div v-if="!phones.length" class="p-4 text-sm text-slate-500">{{ t('No phone numbers yet.') }}</div>
        </div>

        <p v-if="!verificationEnabled && phones.some((p) => !p.verified)" class="text-xs text-ink-muted">{{ t('Numbers you add yourself cannot be verified online yet; the helpdesk can confirm them for you.') }}</p>

        <form class="card grid gap-3 p-5 sm:grid-cols-[1fr_1fr_auto]" @submit.prevent="add">
            <div><label class="label" for="number">{{ t('Phone number') }}</label><input id="number" v-model="number" class="input" placeholder="+36 30 123 4567" required autocomplete="tel" :disabled="atCap" /></div>
            <div><label class="label" for="label">{{ t('Label (optional)') }}</label><input id="label" v-model="label" class="input" :placeholder="t('mobile, office…')" maxlength="50" :disabled="atCap" /></div>
            <div class="self-end"><button class="btn-primary w-full" :disabled="busy || atCap">{{ t('Add') }}</button></div>
            <p v-if="atCap" class="text-xs text-ink-muted sm:col-span-3">{{ t('You can keep at most {n} phone numbers. Remove one to add another.', { n: maxNumbers }) }}</p>
        </form>
    </div>
</template>
