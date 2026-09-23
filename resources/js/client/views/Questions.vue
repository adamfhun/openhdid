<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { get, put, del } from '../api';
import { store } from '../store';
import PageHeader from '../components/PageHeader.vue';
import Skeleton from '../components/Skeleton.vue';
import Icon from '../components/Icon.vue';
import { t } from '../i18n';

const questions = ref([]);
const meta = ref({ answered: 0, required: 0, eligible: false });
const loaded = ref(false);
const loadError = ref(null);
const busy = ref(null);

const selectedId = ref('');
const answer = ref('');
const answerInput = ref(null);
const questionSelect = ref(null);

const replacingId = ref(null);
const replacement = ref('');
const removingId = ref(null);

const open = computed(() => questions.value.filter((q) => !q.answered));
const answered = computed(() => questions.value.filter((q) => q.answered));
const selected = computed(() => questions.value.find((q) => q.id === selectedId.value) ?? null);
const progress = computed(() => (meta.value.required ? Math.min(100, Math.round((meta.value.answered / meta.value.required) * 100)) : 0));
const remaining = computed(() => Math.max(0, meta.value.required - meta.value.answered));

async function load() {
    loadError.value = null;
    try {
        const r = await get('/client/questions');
        questions.value = r.data;
        meta.value = r.meta;
        loaded.value = true;
        if (!open.value.some((q) => q.id === selectedId.value)) {
            selectedId.value = (open.value.find((q) => q.needs_update) ?? open.value[0])?.id ?? '';
        }
    } catch (e) {
        // A failed first load must not leave the skeletons up forever.
        loadError.value = e.message;
        if (loaded.value) store.toast(e.message, 'error');
    }
}

async function saveNext() {
    const text = answer.value.trim();
    if (!selected.value || !text) return;
    busy.value = selected.value.id;
    try {
        await put(`/client/answers/${selected.value.id}`, { answer: text });
        answer.value = '';
        store.toast(t('Answer saved. Only you know it; the helpdesk sees it during a call.'));
        await load();
        await store.refreshUser();
        await nextTick();
        (open.value.length ? answerInput.value : null)?.focus();
    } catch (e) { store.toast(e.firstError ?? e.message, 'error'); } finally { busy.value = null; }
}

function startReplace(q) {
    replacingId.value = q.id; replacement.value = ''; removingId.value = null;
    nextTick(() => document.getElementById(`replace-${q.id}`)?.focus());
}

async function saveReplacement(q) {
    const text = replacement.value.trim();
    if (!text) return;
    busy.value = q.id;
    try {
        await put(`/client/answers/${q.id}`, { answer: text });
        replacingId.value = null; replacement.value = '';
        store.toast(t('Answer replaced.'));
        await load(); await store.refreshUser();
    } catch (e) { store.toast(e.firstError ?? e.message, 'error'); } finally { busy.value = null; }
}

async function remove(q) {
    busy.value = q.id;
    try {
        await del(`/client/answers/${q.id}`);
        removingId.value = null;
        store.toast(t('Answer removed.'));
        await load(); await store.refreshUser();
    } catch (e) { store.toast(e.firstError ?? e.message, 'error'); } finally { busy.value = null; }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('Security questions')" :subtitle="t('Answer at least {n} questions. Answers are stored encrypted and never shown back to you.', { n: meta.required })" icon="question">
            <span class="badge" :class="meta.eligible ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ meta.answered }} / {{ meta.required }}</span>
        </PageHeader>

        <div v-if="loadError && !loaded" class="card p-6 text-center" role="alert">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600"><Icon name="alert" class="h-6 w-6" /></div>
            <h2 class="mt-3 font-semibold text-ink">{{ t('The questions could not be loaded.') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ loadError }}</p>
            <button class="btn-primary mt-4" @click="load"><Icon name="refresh" class="h-4 w-4" />{{ t('Try again') }}</button>
        </div>

        <template v-else-if="!loaded">
            <Skeleton :lines="4" />
            <Skeleton :lines="2" />
        </template>

        <template v-else>
            <div class="card p-5" :class="meta.eligible ? 'ring-emerald-200' : ''">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="font-semibold text-ink">
                        <template v-if="meta.eligible"><span class="mr-1">🎉</span>{{ t('You are ready to be identified by your answers.') }}</template>
                        <template v-else>{{ t('{n} more to go', { n: remaining }) }}</template>
                    </span>
                    <span class="text-ink-muted">{{ meta.answered }} / {{ meta.required }}</span>
                </div>
                <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full rounded-full transition-all duration-700" :class="meta.eligible ? 'bg-emerald-500' : (store.isPremium ? 'bg-premium' : 'bg-brand')" :style="{ width: progress + '%' }"></div>
                </div>
            </div>

            <section v-if="open.length" class="card next-question p-5 sm:p-6" :class="{ 'premium-card': store.isPremium }" aria-labelledby="next-question-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white" :class="store.isPremium ? 'bg-premium' : 'bg-brand'"><Icon name="plus" class="h-5 w-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p v-if="remaining > 0" class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('{n} more to go', { n: remaining }) }}</p>
                        <h2 id="next-question-title" class="text-lg font-bold text-ink">{{ t('Add your next answer') }}</h2>
                        <p class="text-sm text-ink-muted">{{ t('Pick a question only you can answer, then type the answer exactly as you would say it on the phone.') }}</p>
                    </div>
                </div>
                <form class="mt-4 space-y-3" @submit.prevent="saveNext">
                    <div>
                        <label class="label" for="next-question">{{ t('Question') }}</label>
                        <select id="next-question" ref="questionSelect" v-model="selectedId" class="input" required>
                            <option v-for="q in open" :key="q.id" :value="q.id">{{ q.needs_update ? '⟳ ' : '' }}{{ q.text }}</option>
                        </select>
                        <p v-if="selected?.hint" class="mt-1 text-xs text-ink-muted">{{ selected.hint }}</p>
                        <p v-if="selected?.needs_update" class="mt-1 text-xs text-amber-700">{{ t('The wording of this question changed, please answer it again.') }}</p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <div class="flex-1">
                            <label class="label" for="next-answer">{{ t('Your answer') }}</label>
                            <input id="next-answer" ref="answerInput" v-model="answer" class="input" :placeholder="t('Type the answer exactly as you would say it on the phone')" autocomplete="off" maxlength="200" required />
                        </div>
                        <button class="btn-primary shrink-0 sm:self-end" :disabled="busy !== null || !answer.trim()">{{ busy === selectedId ? t('Saving…') : t('Save answer') }}</button>
                    </div>
                    <p class="text-xs text-ink-muted">{{ t('Only you know the answer; the helpdesk sees it during a call.') }}</p>
                </form>
            </section>

            <section v-else class="card p-6 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><Icon name="check" class="h-6 w-6" /></div>
                <h2 class="mt-3 font-semibold text-ink">{{ questions.length ? t('You have answered every question.') : t('No questions have been published yet.') }}</h2>
                <p v-if="questions.length" class="mt-1 text-sm text-ink-muted">{{ t('You can replace or remove any answer below at any time.') }}</p>
            </section>

            <section v-if="answered.length" class="space-y-3" aria-labelledby="answered-title">
                <h2 id="answered-title" class="text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ t('Answered questions') }}</h2>
                <div v-for="q in answered" :key="q.id" class="card p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-ink">{{ q.text }}</h3>
                            <p v-if="q.hint" class="text-sm text-ink-muted">{{ q.hint }}</p>
                        </div>
                        <span class="badge shrink-0 bg-emerald-50 text-emerald-700">{{ t('Answered') }}</span>
                    </div>

                    <form v-if="replacingId === q.id" class="mt-3 flex flex-col gap-2 sm:flex-row" @submit.prevent="saveReplacement(q)">
                        <input :id="`replace-${q.id}`" v-model="replacement" class="input" :placeholder="t('Type a new answer to replace the old one')" autocomplete="off" maxlength="200" required />
                        <div class="flex gap-2">
                            <button class="btn-primary" :disabled="busy === q.id || !replacement.trim()">{{ t('Save') }}</button>
                            <button type="button" class="btn-secondary" @click="replacingId = null">{{ t('Cancel') }}</button>
                        </div>
                    </form>

                    <div v-else-if="removingId === q.id" class="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
                        <span class="flex-1">{{ t('Remove this answer? The helpdesk will no longer be able to ask this question.') }}</span>
                        <button type="button" class="btn-danger !py-1.5" :disabled="busy === q.id" @click="remove(q)">{{ t('Remove') }}</button>
                        <button type="button" class="btn-secondary !py-1.5" @click="removingId = null">{{ t('Cancel') }}</button>
                    </div>

                    <div v-else class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="btn-secondary !py-1.5 text-xs" :disabled="busy === q.id" @click="startReplace(q)"><Icon name="pencil" class="h-4 w-4" />{{ t('Replace answer') }}</button>
                        <button type="button" class="btn-danger !py-1.5 text-xs" :disabled="busy === q.id" @click="removingId = q.id; replacingId = null"><Icon name="trash" class="h-4 w-4" />{{ t('Remove') }}</button>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
