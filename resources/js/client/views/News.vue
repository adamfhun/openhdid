<script setup>
import { t } from '../i18n';
import PageHeader from '../components/PageHeader.vue';
import NewsList from '../components/NewsList.vue';
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('News / Information')" :subtitle="t('Announcements from the helpdesk.')" icon="sparkle" />
        <NewsList :limit="50" />
    </div>
</template>
