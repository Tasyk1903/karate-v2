<script setup>
import { onMounted, ref } from 'vue';
import { CheckCheck } from '@lucide/vue';
import AccountPager from '../../components/panel/AccountPager.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ t: Object });
const emit = defineEmits(['unread']);
const { busy, error, request, run } = useAccountRequest(() => props.t);
const data = ref({ data: [], current_page: 1, last_page: 1, unread: 0 });
async function fetchPage(page) { data.value = await request('/api/panel/account/notifications?page=' + page); emit('unread', data.value.unread); }
function load(page = 1) { run(() => fetchPage(page)); }
function read(id = null) { run(async () => { const result = await request('/api/panel/account/notifications/' + (id ? id + '/read' : 'read-all'), {}); emit('unread', result.unread); await fetchPage(data.value.current_page); }); }
onMounted(() => load());
</script>
<template>
    <section class="account-page">
        <header class="account-section-heading"><h1>{{ t.accountNotifications }}</h1><button :disabled="busy || !data.unread" @click="read()"><CheckCheck :size="17"/>{{ t.accountReadAll }}</button></header>
        <p v-if="error" class="account-error" role="alert">{{ error }} <button @click="load(data.current_page)">{{ t.accountRetry }}</button></p>
        <p v-if="busy">{{ t.loading }}</p>
        <p v-if="!busy && !error && !data.data.length" class="account-empty">{{ t.accountNoNotifications }}</p>
        <article v-for="item in data.data" :key="item.id" class="account-notification">
            <div class="account-rich" v-html="item.content"></div>
            <button v-if="!item.read_at" :disabled="busy" @click="read(item.id)"><CheckCheck :size="16"/>{{ t.accountRead }}</button><small v-else class="account-muted">{{ t.accountReadStatus }}</small>
        </article>
        <AccountPager :t="t" :page="data.current_page" :last="data.last_page" :busy="busy" @change="load"/>
    </section>
</template>
