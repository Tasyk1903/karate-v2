<script setup>
import { computed, ref, watch } from 'vue';
import { ArrowLeft, Check, ChevronRight, FileText } from '@lucide/vue';
import AccountPager from '../../components/panel/AccountPager.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ t: Object, locale: String, path: String, required: Boolean });
const emit = defineEmits(['navigate', 'accepted']);
const { busy, error, request, run } = useAccountRequest(() => props.t);
const data = ref({ data: [], current_page: 1, last_page: 1 }), document = ref(null), checked = ref(false);
const id = computed(() => props.path.match(/\/(\d+)\/?$/)?.[1]);
async function fetchPage(page = 1) {
    checked.value = false;
    if (id.value) document.value = await request('/api/panel/account/agreements/' + id.value);
    else data.value = await request('/api/panel/account/agreements?page=' + page);
}
function load(page = 1) { run(() => fetchPage(page)); }
function accept() { run(async () => { const result = await request('/api/panel/account/agreements/' + id.value + '/accept', { accepted: checked.value, version: document.value.version, locale: props.locale }); emit('accepted', result.agreements_required); await fetchPage(); }); }
watch(() => props.path, () => { document.value = null; load(); }, { immediate: true });
</script>
<template>
    <section class="account-page">
        <header class="account-section-heading"><h1>{{ t.accountAgreements }}</h1><button v-if="id" :title="t.accountBack" :aria-label="t.accountBack" @click="$emit('navigate', '/panel/documents')"><ArrowLeft :size="18"/></button></header>
        <p v-if="required" class="account-consent-notice">{{ t.accountRequired }}</p>
        <p v-if="error" class="account-error" role="alert">{{ error }} <button @click="load()">{{ t.accountRetry }}</button></p>
        <p v-if="busy">{{ t.loading }}</p>
        <template v-if="id && document">
            <h2>{{ t.accountAgreementTypes[document.type] ?? document.type }}</h2><div class="account-rich account-agreement" v-html="document.content"></div>
            <form v-if="!document.accepted_at" class="account-consent" @submit.prevent="accept"><label><input v-model="checked" type="checkbox" required :disabled="busy">{{ t.accountAcceptCheck }}</label><button type="submit" class="account-primary" :disabled="busy || !checked"><Check :size="16"/>{{ t.accountAccept }}</button></form>
            <p v-else class="account-success" role="status">{{ t.accountAccepted }}</p>
        </template>
        <template v-else-if="!id">
            <p v-if="!busy && !error && !data.data.length">{{ t.accountNoAgreements }}</p>
            <a v-for="item in data.data" :key="item.id" class="account-document" :href="'/panel/documents/' + item.id" @click.prevent="$emit('navigate', '/panel/documents/' + item.id)"><FileText :size="19"/><strong>{{ t.accountAgreementTypes[item.type] ?? item.type }}</strong><span :class="item.accepted_at ? 'account-success' : 'account-muted'">{{ item.accepted_at ? t.accountAccepted : (item.required ? t.accountRequired : '') }}</span><ChevronRight :size="18"/></a>
            <AccountPager :t="t" :page="data.current_page" :last="data.last_page" :busy="busy" @change="load"/>
        </template>
        <a v-if="!required && !busy" class="account-continue" href="/panel/dashboard" @click.prevent="$emit('navigate', '/panel/dashboard')">{{ t.accountContinue }}<ChevronRight :size="16"/></a>
    </section>
</template>
