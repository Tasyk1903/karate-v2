<script setup>
import { computed } from 'vue';
import { adminLabels } from '../../i18n/admin';
import '../../styles/admin.css';
import AdminRecordsPage from './AdminRecordsPage.vue';
import AdminFeedPage from './AdminFeedPage.vue';
import AdminAgreementsPage from './AdminAgreementsPage.vue';
import AdminActivityPage from './AdminActivityPage.vue';
const props = defineProps({ section: String, locale: String });
const t = computed(() => adminLabels[props.locale]);
</script>
<template><div class="admin-workspace"><h1 class="admin-workspace-title">{{ t[section] }}</h1><AdminFeedPage v-if="section === 'feed'" :locale="locale"/><AdminAgreementsPage v-else-if="section === 'agreements'" :locale="locale"/><AdminActivityPage v-else-if="section === 'activity'" :locale="locale"/><AdminRecordsPage v-else :key="section" :kind="section" :locale="locale"/></div></template>
