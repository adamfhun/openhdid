<script setup>
import { ref, onMounted } from 'vue';
import { get } from '../api';
import { i18n, t } from '../i18n';
import Icon from './Icon.vue';

const props = defineProps({ limit: { type: Number, default: 20 }, compact: { type: Boolean, default: false } });
const posts = ref([]);
const total = ref(0);
const loaded = ref(false);
const error = ref(null);

const formatDate = (iso) => new Date(iso).toLocaleDateString(i18n.locale === 'hu' ? 'hu-HU' : 'en-GB', { year: 'numeric', month: '2-digit', day: '2-digit' });

async function load() {
    error.value = null;
    loaded.value = false;
    try {
        const r = await get(`/client/news?limit=${props.limit}`);
        posts.value = r.data; total.value = r.meta.total;
    } catch (e) {
        // "No news" and "could not load the news" are different things.
        error.value = e.message;
    } finally { loaded.value = true; }
}

onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div v-if="loaded && error" class="card flex flex-wrap items-center justify-between gap-3 p-4 text-sm" role="alert">
            <span class="text-red-700">{{ t('The news could not be loaded.') }}</span>
            <button type="button" class="btn-secondary !py-1.5" @click="load"><Icon name="refresh" class="h-4 w-4" />{{ t('Try again') }}</button>
        </div>
        <p v-else-if="loaded && !posts.length" class="card p-6 text-sm text-ink-muted">{{ t('No news at the moment.') }}</p>
        <article v-for="post in posts" :key="post.id" class="card p-5 sm:p-6">
            <div class="flex items-center gap-2 text-xs text-ink-muted">
                <Icon name="sparkle" class="h-4 w-4 text-brand-accent" />
                <time :datetime="post.published_at">{{ formatDate(post.published_at) }}</time>
            </div>
            <h2 class="mt-1 text-lg font-semibold text-ink">{{ post.title }}</h2>
            <div class="prose-news mt-2 text-sm text-ink" :class="{ 'line-clamp-4': compact }" v-html="post.body_html"></div>
        </article>
        <RouterLink v-if="compact && total > posts.length" to="/news" class="btn-secondary">{{ t('All news') }}</RouterLink>
    </div>
</template>
