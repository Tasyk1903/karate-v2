<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ArrowLeft, Eye, Link2, Pencil, Plus, RefreshCw, Search, Trash2, Upload, X } from '@lucide/vue';
import ExternalStudentLinkModal from '../../components/panel/ExternalStudentLinkModal.vue';
import AccountPager from '../../components/panel/AccountPager.vue';
import ConfirmActionModal from '../../components/panel/ConfirmActionModal.vue';
import ExternalParticipantFields from '../../components/panel/ExternalParticipantFields.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ championshipId: Number, formId: Number, locale: String, t: Object });
const emit = defineEmits(['navigate']);
const { request, run, busy, error } = useAccountRequest(() => props.t);
const data = ref(null), search = ref(''), editing = ref(null), confirmation = ref(null), syncProfiles = ref(false), report = ref(null);
const linking = ref(null);
const base = computed(() => `/api/panel/tournaments/${props.championshipId}/forms/${props.formId}`);
const importing = computed(() => ['queued', 'running'].includes(report.value?.status));
let timer, searchTimer;
function blank() { return { first_name: '', last_name: '', birthday: '', rank: '', weight: '', age: '', gender: '', category: [], kata_group: '', region: '', city: '', club: '', coach_first_name: '', coach_last_name: '', razriad: '', best_results: '' }; }
function invalidFields(entry) {
    const keys = { first_name: 'firstName', last_name: 'lastName', birthday: 'birthDate', rank: 'kyuDan', weight: 'weight', age: 'age', gender: 'gender', category: 'category', kata_group: 'kataGroup', region: 'region', city: 'city', club: 'club', coach_first_name: 'coachFirstName', coach_last_name: 'coachLastName', razriad: 'rankSport', best_results: 'bestResults' };
    return [...new Set((entry.fields || []).map(field => field.split('.').find(part => keys[part])).filter(Boolean).map(field => props.t[keys[field]]))].join(', ');
}
async function fetchRows(page = 1) { data.value = await request(`${base.value}?${new URLSearchParams({ page, search: search.value, locale: props.locale })}`); }
function load(page = 1) { run(() => fetchRows(page)); }
function find() { clearTimeout(searchTimer); searchTimer = setTimeout(() => load(), 300); }
function edit(row) { editing.value = { ...blank(), ...JSON.parse(JSON.stringify(row || {})) }; error.value = ''; }
async function save() { await run(async () => { await request(base.value + '/rows', { revision: data.value.revision, upserts: [editing.value], locale: props.locale }, 'PUT'); editing.value = null; await fetchRows(data.value.meta.current_page); }); }
function close() { if (!busy.value) { editing.value = null; confirmation.value = null; error.value = ''; } }
async function confirm() {
    await run(async () => {
        if (confirmation.value.type === 'delete') {
            await request(base.value + '/rows', { revision: data.value.revision, deletes: [confirmation.value.row.row_id], locale: props.locale }, 'PUT');
        } else {
            const result = await request(base.value + '/import', { revision: data.value.revision, sync_profiles: syncProfiles.value, locale: props.locale });
            await fetchReport(result.run_id);
        }
        confirmation.value = null; await fetchRows(data.value.meta.current_page);
    });
}
async function fetchReport(id, page = 1) {
    clearTimeout(timer);
    report.value = await request(`${base.value}/imports/${id}?page=${page}`);
    if (importing.value) scheduleReport(id, page);
}
function scheduleReport(id, page) { timer = setTimeout(() => { if (busy.value) { scheduleReport(id, page); return; } run(async () => { await fetchReport(id, page); if (!importing.value) await fetchRows(data.value?.meta.current_page ?? 1); }); }, 1500); }
function reportPage(page) { run(() => fetchReport(report.value.id, page)); }
onMounted(() => run(async () => { await fetchRows(); if (data.value.latest_run_id) await fetchReport(data.value.latest_run_id); }));
function escape(event) { if (event.key === 'Escape') close(); }
onMounted(() => window.addEventListener('keydown', escape));
onBeforeUnmount(() => { clearTimeout(timer); clearTimeout(searchTimer); window.removeEventListener('keydown', escape); });
</script>
<template>
    <ExternalStudentLinkModal v-if="linking" :base="base" :row="linking" :revision="data.revision" :locale="locale" :t="t" @close="linking = null" @linked="linking = null; load(data.meta.current_page)"/>
    <section class="account-page form-editor-page">
        <header class="account-section-heading"><div><h1>{{ data?.form.organization_name || t.formEditor }}</h1><small class="account-muted">{{ data?.championship.name }}</small></div><button :title="t.accountBack" :aria-label="t.accountBack" @click="$emit('navigate', `/panel/tournaments/${championshipId}?tab=forms`)"><ArrowLeft :size="18"/></button></header>
        <p v-if="error && !editing && !confirmation" class="account-error" role="alert">{{ error }} <button :disabled="busy" @click="load(data?.meta.current_page)">{{ t.formReload }}</button></p>
        <div class="form-editor-toolbar"><label class="form-search"><Search :size="17"/><input v-model="search" :placeholder="t.search" :aria-label="t.search" @input="find"></label><button :disabled="busy || importing" @click="edit()"><Plus :size="16"/>{{ t.formAddParticipant }}</button><button v-if="data?.form.status === 'closed'" class="account-primary" :disabled="busy || importing" @click="syncProfiles = false; confirmation = { type: 'import' }"><Upload :size="16"/>{{ t.formImport }}</button><span v-if="data?.form.status === 'closed'" class="account-muted">{{ t.formRegistrationClosed }}</span></div>
        <p v-if="busy && !data">{{ t.loading }}</p>
        <template v-if="data"><table v-responsive-table class="form-editor-table"><thead><tr><th>{{ t.fio }}</th><th>{{ t.category }}</th><th>{{ t.club }} / {{ t.trainer }}</th><th>{{ t.actions }}</th></tr></thead><tbody>
            <tr v-for="row in data.rows" :key="row.row_id"><td><strong>{{ row.last_name }} {{ row.first_name }}</strong><small>{{ row.birthday || row.age }} · {{ row.weight || '—' }} {{ t.kg }} · {{ row.rank || '—' }}</small></td><td><span v-for="category in row.category" :key="category" class="form-category-label">{{ category === 'kata_point' ? t.formBothKata : t['teamFormCategory_' + category] }}<template v-if="category === 'kata_group'"> · {{ row.kata_group }}</template></span></td><td>{{ row.club || '—' }}<small>{{ row.coach_last_name }} {{ row.coach_first_name }}</small></td><td><div class="form-row-actions"><a v-if="row.student_id" :href="`/panel/team/students/${row.student_id}`" target="_blank" rel="noopener noreferrer" :title="t.view" :aria-label="t.view"><Eye :size="16"/></a><button v-if="!row.student_id" :title="t.formLinkStudent" :aria-label="t.formLinkStudent" :disabled="busy || importing" @click="linking = row"><Link2 :size="16"/></button><button :title="t.edit" :aria-label="t.edit" :disabled="busy || importing" @click="edit(row)"><Pencil :size="16"/></button><button :title="t.delete" :aria-label="t.delete" :disabled="busy || importing" @click="confirmation = { type: 'delete', row }"><Trash2 :size="16"/></button></div></td></tr>
            <tr v-if="!data.rows.length"><td colspan="4">{{ t.emptyTeam }}</td></tr>
        </tbody></table><AccountPager :t="t" :page="data.meta.current_page" :last="data.meta.last_page" :busy="busy" @change="load"/></template>
        <section class="form-import-report"><header class="account-section-heading"><h2>{{ t.formReport }}</h2><button v-if="report" :disabled="busy" :title="t.accountRetry" :aria-label="t.accountRetry" @click="reportPage(report.meta.current_page)"><RefreshCw :size="16"/></button></header>
            <p v-if="!report" class="account-muted">{{ t.formNoReport }}</p>
            <template v-else><p role="status">{{ report.status === 'queued' ? t.formQueued : report.status === 'running' ? t.formRunning : report.status === 'failed' ? t.formFailed : t.formCompleted }}</p><p v-if="report.error_code" class="account-error">{{ t.formStatus[report.error_code] }}</p>
                <div v-if="report.status === 'completed'" class="form-import-counts"><span>{{ t.formCreated }}: <b>{{ report.result.created_users }}</b></span><span>{{ t.formAttached }}: <b>{{ report.result.attached }}</b></span><span>{{ t.formRemoved }}: <b>{{ report.result.removed }}</b></span><span>{{ t.formIssues }}: <b>{{ report.result.issues }}</b></span></div>
                <div v-for="(entry, index) in report.entries" :key="index" class="form-report-row"><a v-if="entry.student_id" :href="`/panel/team/students/${entry.student_id}`" target="_blank" rel="noopener noreferrer">{{ entry.name || entry.student_id }}</a><span v-else>{{ entry.name || entry.row_number }}</span><small>{{ entry.category === 'kata_point' ? t.formBothKata : t['teamFormCategory_' + entry.category] }}</small><span :class="['attached', 'unchanged', 'removed'].includes(entry.status) ? 'account-muted' : 'account-error'">{{ t.formStatus[entry.status] || t.formStatus.invalid_row }}<small v-if="invalidFields(entry)">{{ invalidFields(entry) }}</small></span></div>
                <AccountPager :t="t" :page="report.meta.current_page" :last="report.meta.last_page" :busy="busy" @change="reportPage"/>
            </template>
        </section>
    </section>
    <div v-if="editing" class="modal-backdrop form-modal-backdrop" @click.self="close"><form class="template-modal form-participant-modal" role="dialog" aria-modal="true" :aria-label="editing.row_id ? t.formEditParticipant : t.formAddParticipant" @submit.prevent="save"><header><h2>{{ editing.row_id ? t.formEditParticipant : t.formAddParticipant }}</h2><button class="panel-icon-button" type="button" :title="t.cancel" :disabled="busy" @click="close"><X :size="18"/></button></header><p v-if="error" class="form-error" role="alert">{{ error }}</p><fieldset :disabled="busy"><ExternalParticipantFields :row="editing" :options="data.category_options" :t="t"/></fieldset><footer><button type="button" class="soft-button" :disabled="busy" @click="close">{{ t.cancel }}</button><button type="submit" class="save-button" :disabled="busy">{{ busy ? t.saving : t.formSaveRow }}</button></footer></form></div>
    <ConfirmActionModal v-if="confirmation" :title="confirmation.type === 'delete' ? t.formDeleteRow : t.formImportConfirm" :message="confirmation.type === 'delete' ? t.formDeleteNote : ''" :confirm-label="confirmation.type === 'delete' ? t.delete : t.formImport" :busy="busy" :error="error" :t="t" @close="close" @confirm="confirm"><label v-if="confirmation.type === 'import'" class="form-sync-checkbox"><input v-model="syncProfiles" type="checkbox" :disabled="busy">{{ t.formSyncProfiles }}</label></ConfirmActionModal>
</template>
<style>
.form-editor-toolbar { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin:20px 0; }
.form-search { display:flex; align-items:center; gap:8px; border:1px solid var(--panel-line); border-radius:6px; padding:8px 10px; }
.form-search input { background:transparent; color:inherit; border:0; min-width:0; width:190px; font:inherit; }
.form-editor-table { width:100%; table-layout:fixed; border-collapse:collapse; font-size:13px; }
.form-editor-table th,.form-editor-table td { padding:12px 9px; border-bottom:1px solid var(--panel-line); text-align:left; vertical-align:top; overflow-wrap:anywhere; }
.form-editor-table th { font-size:12px; color:var(--panel-muted); font-weight:500; }
.form-editor-table th:last-child { width:120px; }
.form-editor-table strong { font-weight:600; }
.form-editor-table small,.form-category-label { display:block; margin-top:4px; font-size:12px; color:var(--panel-muted); }
.form-row-actions { display:flex; justify-content:flex-end; gap:6px; }
.form-row-actions button,.form-row-actions a { width:30px; height:30px; min-height:30px; padding:6px; display:grid; place-items:center; }
.form-import-report { margin-top:36px; border-top:1px solid var(--panel-line); padding-top:24px; }
.form-import-counts { display:flex; gap:18px; flex-wrap:wrap; margin:16px 0; font-size:13px; }
.form-report-row { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,2fr); gap:12px; border-bottom:1px solid var(--panel-line); padding:12px 0; font-size:13px; overflow-wrap:anywhere; }
.form-modal-backdrop { z-index:100; }
.template-modal.form-participant-modal { grid-template-columns:minmax(0,1fr); width:min(760px,calc(100vw - 28px)); max-width:760px; max-height:calc(100dvh - 32px); overflow-y:auto; overscroll-behavior:contain; }
.form-participant-modal > fieldset { border:0; padding:0; min-width:0; }
.form-participant-fields { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
.form-field-wide,.form-category-options { grid-column:1 / -1; }
.form-category-options { border:0; padding:0; display:flex; gap:12px; flex-wrap:wrap; }
.form-category-options legend { margin-bottom:8px; font-size:12px; }
.form-category-options label,.form-sync-checkbox { display:flex; gap:8px; align-items:center; font-size:13px; }
.form-category-options input,.form-sync-checkbox input { width:17px; height:17px; accent-color:var(--panel-red); }
.form-participant-fields textarea { border:1px solid var(--panel-line); background:var(--panel-card-solid); color:var(--panel-text); padding:10px; border-radius:6px; width:100%; }
@media(max-width:620px) { .form-editor-table th:last-child { width:72px; } .form-row-actions { flex-wrap:wrap; gap:3px; } .form-editor-table th,.form-editor-table td { padding:9px 4px; font-size:12px; } .form-report-row { grid-template-columns:minmax(0,1fr) minmax(0,1fr); } .form-report-row > span:last-child { grid-column:1 / -1; } }
</style>
