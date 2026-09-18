<script setup>
import { ref, watch, onMounted } from 'vue';
import { ArrowLeft, Check, X, UserPlus, Unlink, Users } from '@lucide/vue';
import AccountPager from '../../components/panel/AccountPager.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ t: Object, locale: String });
const { request, run, busy, error } = useAccountRequest(() => props.t);
const view = ref('discover'), search = ref(''), page = ref(1), data = ref({ data: [], last_page: 1 });
const team = ref(null), teamId = ref(null), confirmation = ref(null);
let revision = 0;
async function load() {
    const version = ++revision;
    const params = new URLSearchParams({ view: view.value, search: search.value, page: page.value, locale: props.locale });
    const result = await request(`/api/panel/tournament-applications${teamId.value ? `/${teamId.value}/team` : ''}?${params}`);
    if (version !== revision) return;
    if (teamId.value) { team.value = result; data.value = result.coaches; } else data.value = result;
}
function refresh() { return run(load); }
function changeView(value) { if (busy.value) return; view.value = value; teamId.value = null; team.value = null; page.value = 1; search.value = ''; refresh(); }
function find() { page.value = 1; refresh(); }
function changePage(value) { page.value = value; refresh(); }
function openTeam(row) { teamId.value = row.application_id; page.value = 1; search.value = ''; refresh(); }
function ask(row, action) { error.value = ''; confirmation.value = { row, action }; }
function confirm() {
    run(async () => {
        const { row, action } = confirmation.value;
        const root = '/api/panel/tournament-applications';
        if (action === 'apply') await request(`${root}/${row.id}/apply`, { locale: props.locale });
        else if (action === 'attach' || action === 'detach') await request(`${root}/${teamId.value}/coaches`, { ids: [row.id], attach: action === 'attach', locale: props.locale });
        else await request(`${root}/${row.application_id}/decision`, { status: action, locale: props.locale });
        confirmation.value = null;
        await load();
    });
}
function message(action) { return props.t[{ apply: 'tourApplyConfirm', accepted: 'tourAcceptConfirm', canceled: 'tourRejectConfirm', attach: 'tourAttach', detach: 'tourDetachConfirm' }[action]]; }
function statusLabel(status) { return props.t[{ pending: 'tourPending', accepted: 'tourAccepted', canceled: 'tourCanceled' }[status]]; }
watch(() => props.locale, refresh);
onMounted(refresh);
</script>
<template>
    <section class="org-applications">
        <header class="page-heading"><a href="/panel/tournaments" class="panel-icon-button" :title="t.tourBack" :aria-label="t.tourBack"><ArrowLeft :size="18" /></a><h1>{{ t.tourApplications }}</h1></header>
        <nav class="org-application-tabs">
            <button v-for="tab in [{ key: 'discover', label: t.tourDiscover }, { key: 'incoming', label: t.tourIncoming }, { key: 'outgoing', label: t.tourOutgoing }]" :key="tab.key" type="button" :class="{ active: view === tab.key }" :disabled="busy" @click="changeView(tab.key)">{{ tab.label }}</button>
        </nav>
        <div v-if="teamId" class="org-team-heading"><button type="button" class="panel-icon-button" :title="t.tourBack" :disabled="busy" @click="changeView('outgoing')"><ArrowLeft :size="18" /></button><div><h2>{{ team?.tournament.name }}</h2><p>{{ team?.tournament.championship }} · {{ team?.tournament.date }} · {{ team?.tournament.address }}</p></div></div>
        <form class="org-application-search" @submit.prevent="find"><input v-model="search" type="search" maxlength="100" :aria-label="t.search" :placeholder="t.search" /><button class="soft-button" :disabled="busy">{{ t.search }}</button></form>
        <p v-if="error && !confirmation" class="form-error" role="alert">{{ error }}</p>
        <div :aria-busy="busy">
            <p v-if="!data.data.length && !busy" class="empty-cell">{{ t.tourEmpty }}</p>
            <article v-for="row in data.data" :key="row.application_id || row.id" class="org-application-row">
                <div><h2>{{ row.name }}</h2><p v-if="teamId">{{ row.club }}</p><template v-else><p>{{ row.championship }} · {{ row.date }} – {{ row.date_finish }}</p><p>{{ row.address }}</p><p v-if="row.organization">{{ row.organization }}</p><p>{{ t.participationPrice }}: {{ row.price }}</p></template></div>
                <div class="org-application-actions">
                    <template v-if="teamId"><span v-if="row.attached">{{ t.tourAttached }}</span><button v-if="team?.can_manage_team && (!row.attached || row.can_detach)" type="button" class="panel-icon-button" :title="row.attached ? t.tourDetach : t.tourAttach" :aria-label="row.attached ? t.tourDetach : t.tourAttach" :disabled="busy" @click="ask(row, row.attached ? 'detach' : 'attach')"><Unlink v-if="row.attached" :size="18" /><UserPlus v-else :size="18" /></button></template>
                    <template v-else>
                        <span v-if="row.application_id" class="org-application-status" :data-status="row.status">{{ statusLabel(row.status) }}</span>
                        <button v-if="row.can_apply" type="button" class="soft-button" :disabled="busy" @click="ask(row, 'apply')">{{ t.tourApply }}</button>
                        <template v-if="row.can_decide"><button v-if="row.status !== 'accepted'" type="button" class="panel-icon-button" :title="t.tourAccept" :aria-label="t.tourAccept" :disabled="busy" @click="ask(row, 'accepted')"><Check :size="18" /></button><button v-if="row.status !== 'canceled'" type="button" class="panel-icon-button" :title="t.tourReject" :aria-label="t.tourReject" :disabled="busy" @click="ask(row, 'canceled')"><X :size="18" /></button></template>
                        <button v-if="row.can_open_team" type="button" class="soft-button" :disabled="busy" @click="openTeam(row)"><Users :size="16" />{{ t.tourTeam }}</button>
                    </template>
                </div>
            </article>
        </div>
        <AccountPager :page="page" :last="data.last_page" :busy="busy" :t="t" @change="changePage" />
    </section>
    <div v-if="confirmation" class="modal-backdrop application-modal-backdrop" @click.self="!busy && (confirmation = null)"><form class="template-modal application-modal" role="dialog" aria-modal="true" :aria-label="t.tourConfirm" @submit.prevent="confirm"><header><h2>{{ t.tourConfirm }}</h2></header><p>{{ confirmation.row.name }}</p><p>{{ message(confirmation.action) }}</p><p v-if="error" class="form-error" role="alert">{{ error }}</p><footer><button type="button" class="soft-button" :disabled="busy" @click="confirmation = null">{{ t.cancel }}</button><button class="save-button" :disabled="busy">{{ t.tourConfirm }}</button></footer></form></div>
</template>
<style>
.org-applications { min-width:0; padding:20px; background:var(--panel-card-solid); }
.org-application-tabs { display:flex; gap:4px; flex-wrap:wrap; border-bottom:1px solid var(--panel-line); margin:12px 0 20px; }
.org-application-tabs button { border:0; border-bottom:2px solid transparent; background:transparent; padding:12px 14px; font-size:13px; color:var(--panel-muted); cursor:pointer; }
.org-application-tabs button.active { border-bottom-color:var(--panel-red); color:var(--panel-red); }
.org-applications .application-modal { font-size:14px; }
.org-applications .page-heading { justify-content:flex-start; gap:12px; }
.org-application-search { display:flex; gap:8px; margin:16px 0; }
.org-application-search input { width:320px; max-width:70%; min-width:0; padding:9px 12px; border:1px solid var(--panel-line); border-radius:6px; background:var(--panel-card); color:inherit; }
.org-application-row { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:16px 4px; border-bottom:1px solid var(--panel-line); }
.org-application-row > div:first-child { min-width:0; overflow-wrap:anywhere; }
.org-application-row h2, .org-team-heading h2 { font-size:15px; font-weight:600; margin:0 0 6px; }
.org-application-row p, .org-team-heading p { font-size:13px; margin:4px 0; color:var(--panel-muted); }
.org-application-actions, .org-team-heading { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.org-application-actions { justify-content:flex-end; flex-shrink:0; max-width:45%; }
.org-application-actions .soft-button { display:inline-flex; align-items:center; gap:6px; }
.org-application-status { font-size:12px; color:var(--panel-muted); }
.org-application-status[data-status=accepted] { color:#178354; }
.org-application-status[data-status=canceled] { color:#c32b37; }
@media(max-width:600px) { .org-application-row { flex-direction:column; align-items:stretch; }.org-application-actions { max-width:100%; justify-content:flex-start; }.org-applications { padding:14px; }.org-applications .page-heading h1 { font-size:18px; }.org-application-tabs button { font-size:12px; padding:10px 8px; } }
</style>
