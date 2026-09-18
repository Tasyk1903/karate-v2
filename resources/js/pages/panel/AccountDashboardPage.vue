<script setup>
import { computed, onMounted, ref } from 'vue';
import { Users, UserRound, Mail, Bell, ChevronRight, CalendarDays, Trophy } from '@lucide/vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ t: Object, locale: String });
defineEmits(['navigate']);
const { busy, error, request, run } = useAccountRequest(() => props.t);
const data = ref(null);
const stats = computed(() => [
    { key: 'trainers', text: props.t.accountTrainers, icon: Users, path: '/panel/team?section=trainers' },
    { key: 'students', text: props.t.accountStudents, icon: UserRound, path: '/panel/team?section=students' },
    { key: 'pending', text: props.t.accountPending, icon: Mail, path: '/panel/team?section=pending' },
    { key: 'unread', text: props.t.accountUnread, icon: Bell, path: '/panel/notifications' },
]);
function date(value) { return new Intl.DateTimeFormat(props.locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(value + 'T12:00:00')); }
function load() { run(async () => { data.value = await request('/api/panel/account/dashboard'); }); }
onMounted(load);
</script>
<template>
    <section class="account-page">
        <h1>{{ t.dashboard }}</h1>
        <p v-if="error" class="account-error" role="alert">{{ error }} <button @click="load">{{ t.accountRetry }}</button></p>
        <p v-if="busy">{{ t.loading }}</p>
        <template v-if="data">
            <div class="account-stats"><a v-for="stat in stats" :key="stat.key" :href="stat.path"><component :is="stat.icon" :size="21"/><span>{{ stat.text }}</span><strong>{{ data[stat.key] }}</strong><ChevronRight :size="16"/></a></div>
            <header class="account-section-heading"><h2><CalendarDays :size="19"/>{{ t.accountUpcoming }}</h2><a href="/panel/tournaments" @click.prevent="$emit('navigate', '/panel/tournaments')">{{ t.accountAllTournaments }}<ChevronRight :size="16"/></a></header>
            <p v-if="!data.upcoming.length" class="account-empty">{{ t.accountNoTournaments }}</p>
            <a v-for="item in data.upcoming" :key="item.id" class="account-tournament" :href="item.path" @click.prevent="$emit('navigate', item.path)">
                <span class="account-tournament-icon"><Trophy :size="23"/></span><span><strong>{{ item.name }}</strong><small>{{ item.championship }}</small></span><time>{{ date(item.date) }}</time><ChevronRight :size="18"/>
            </a>
        </template>
    </section>
</template>
