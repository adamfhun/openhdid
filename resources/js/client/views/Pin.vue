<script setup>
import { ref, computed } from 'vue';
import { put, del } from '../api';
import { store } from '../store';
import Icon from '../components/Icon.vue';
import PageHeader from '../components/PageHeader.vue';
import { i18n, t } from '../i18n';

const pin = ref('');
const confirmation = ref('');
const busy = ref(false);
const changesEnabled = computed(() => store.user?.identification?.pin_changes_enabled === true);
const minLength = computed(() => store.user?.identification?.pin_min_length ?? 6);
const maxLength = computed(() => store.user?.identification?.pin_max_length ?? 10);
const longEnough = (value) => value.length >= minLength.value && value.length <= maxLength.value;

const removing = ref(false);
const reveal = ref(false);
const digitsOnly = (value) => value.replace(/\D/g, '');
const mismatch = computed(() => longEnough(pin.value) && longEnough(confirmation.value) && pin.value !== confirmation.value);
const nonDigits = computed(() => /\D/.test(pin.value) || /\D/.test(confirmation.value));
const canSave = computed(() => changesEnabled.value && !busy.value && longEnough(pin.value) && longEnough(confirmation.value) && !mismatch.value && !nonDigits.value);

async function save() {
    if (!changesEnabled.value) return;
    busy.value = true;
    try {
        await put('/client/pin', { pin: pin.value, pin_confirmation: confirmation.value });
        pin.value = confirmation.value = '';
        store.toast(t('PIN saved.'));
        await store.refreshUser();
    } catch (e) { store.toast(e.firstError ?? e.message, 'error'); } finally { busy.value = false; }
}

async function remove() {
    if (!changesEnabled.value) return;
    busy.value = true;
    try { await del('/client/pin'); removing.value = false; store.toast(t('PIN removed.')); await store.refreshUser(); }
    catch (e) { store.toast(e.message, 'error'); } finally { busy.value = false; }
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('PIN')" :subtitle="t('A code of {min} to {max} digits you type into the phone menu. Never tell it to anyone.', { min: minLength, max: maxLength })" icon="key" />

        <div class="card p-6">
            <div class="mb-4 flex items-center gap-2 text-sm">
                <span class="badge" :class="store.user?.has_pin ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ store.user?.has_pin ? t('PIN set') : t('No PIN') }}</span>
                <span v-if="store.user?.pin_set_at" class="text-slate-400">{{ t('since {date}', { date: new Date(store.user?.pin_set_at).toLocaleDateString(i18n.locale === 'hu' ? 'hu-HU' : 'en-GB', { year: 'numeric', month: '2-digit', day: '2-digit' }) }) }}</span>
            </div>
            <p v-if="!changesEnabled" class="text-sm text-ink-muted" role="status">{{ t('Your PIN is managed by the helpdesk. Contact them to set, replace or remove it.') }}</p>
            <form v-else class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                <div><label class="label" for="pin">{{ t('New PIN') }}</label><input id="pin" v-model="pin" :type="reveal ? 'text' : 'password'" inputmode="numeric" pattern="[0-9]*" :maxlength="maxLength" class="input tracking-[0.5em]" autocomplete="new-password" required @input="pin = digitsOnly(pin)" /></div>
                <div><label class="label" for="pin2">{{ t('Repeat PIN') }}</label><input id="pin2" v-model="confirmation" :type="reveal ? 'text' : 'password'" inputmode="numeric" pattern="[0-9]*" :maxlength="maxLength" class="input tracking-[0.5em]" autocomplete="new-password" required :aria-invalid="mismatch || undefined" :aria-describedby="mismatch ? 'pin-mismatch' : undefined" @input="confirmation = digitsOnly(confirmation)" /></div>
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs sm:col-span-2">
                    <label class="flex cursor-pointer items-center gap-2 text-ink-muted"><input v-model="reveal" type="checkbox" class="rounded border-slate-300" />{{ t('Show PIN') }}</label>
                    <span v-if="mismatch" id="pin-mismatch" class="font-medium text-red-700" role="alert">{{ t('The two PINs do not match.') }}</span>
                    <span v-else-if="pin.length && pin.length < minLength" class="text-ink-muted">{{ t('{n} more digits', { n: minLength - pin.length }) }}</span>
                </div>
                <div v-if="removing" class="flex flex-wrap items-center gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 sm:col-span-2">
                    <span class="flex-1">{{ t('Remove your PIN? You will no longer be able to identify yourself in the phone menu.') }}</span>
                    <button type="button" class="btn-danger !py-1.5" :disabled="busy" @click="remove">{{ t('Remove PIN') }}</button>
                    <button type="button" class="btn-secondary !py-1.5" @click="removing = false">{{ t('Cancel') }}</button>
                </div>
                <div v-else class="flex gap-2 sm:col-span-2">
                    <button class="btn-primary" :disabled="!canSave">{{ store.user?.has_pin ? t('Replace PIN') : t('Set PIN') }}</button>
                    <button v-if="store.user?.has_pin" type="button" class="btn-danger" :disabled="busy" @click="removing = true"><Icon name="trash" class="h-4 w-4" />{{ t('Remove PIN') }}</button>
                </div>
            </form>
        </div>
    </div>
</template>
