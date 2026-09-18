<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Pencil, Save, X, Trash2, Undo2, ExternalLink } from '@lucide/vue';
import StudentDetailPage from './StudentDetailPage.vue';
import StudentOwnDocuments from '../../components/panel/StudentOwnDocuments.vue';
import FileDropzone from '../../components/panel/FileDropzone.vue';
import ConfirmActionModal from '../../components/panel/ConfirmActionModal.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
import { studentLabels } from '../../i18n/student';

const props = defineProps({ t: Object, locale: String });
const emit = defineEmits(['updated', 'navigate']);
const labels = computed(() => studentLabels[props.locale]);
const { busy, error, request, run } = useAccountRequest(() => props.t);
const detail = ref(null), editing = ref(false), saved = ref(false), deleting = ref(false), password = ref('');
const form = reactive({}), files = reactive({}), removed = ref([]);
const caps = computed(() => detail.value?.capabilities ?? {});
const fields = computed(() => [
    ['last_name', props.t.lastName, 'text', true], ['first_name', props.t.firstName, 'text', true],
    ['patronymic', labels.value.patronymic, 'text'], ['email', props.t.email, 'email', true],
    ['birthday', props.t.birthday, 'date'], ['weight', props.t.weight, 'number'],
    ['height', props.t.height, 'number'], ['city_training', labels.value.city, 'text'],
]);
const extraFields = computed(() => [
    ['number_brand', props.t.brandNumber], ['number_iko', props.t.ikoNumber],
    ['number_certificate', props.t.certificateNumber], ['last_examination_date', props.t.lastExamDate, 'date'],
    ['last_examination_city', props.t.lastExamCity], ['last_receiving', props.t.lastReceiving],
]);
const documentFields = computed(() => [
    ['avatar', props.t.accountAvatar], ['passport', props.t.passport], ['brand', props.t.brand],
    ['insurance', props.t.insurance], ['iko_card', props.t.ikoCard], ['certificate', props.t.certificate],
]);
const ranks = ['0 кю', ...Array.from({ length: 10 }, (_, i) => `${10 - i} кю`), ...Array.from({ length: 10 }, (_, i) => `${i + 1} дан`)];
function existingFile(field) {
    if (field === 'avatar') return detail.value?.student.avatar;
    const key = field === 'iko_card' ? 'ikoCard' : field;
    return detail.value?.documents.documents.find(document => document.key === key)?.file;
}
function edit() {
    Object.keys(form).forEach(key => delete form[key]);
    Object.assign(form, detail.value.profile);
    for (const key of ['birthday', 'last_examination_date']) form[key] = form[key]?.slice(0, 10) ?? '';
    Object.keys(files).forEach(key => delete files[key]);
    removed.value = []; saved.value = false; error.value = ''; editing.value = true;
}
function toggleRemove(field) {
    files[field] = null;
    removed.value = removed.value.includes(field) ? removed.value.filter(item => item !== field) : [...removed.value, field];
}
async function load() { await run(async () => { detail.value = await request('/api/panel/student/profile'); }); }
async function save() {
    await run(async () => {
        const data = new FormData();
        Object.entries(form).forEach(([key, value]) => data.append(key, value ?? ''));
        Object.entries(files).forEach(([key, file]) => { if (file) data.append(key, file); });
        removed.value.filter(field => field !== 'avatar').forEach(field => data.append('remove_documents[]', field));
        data.append('remove_avatar', removed.value.includes('avatar') ? '1' : '0');
        detail.value = await request('/api/panel/student/profile', data);
        editing.value = false; saved.value = true;
        emit('updated', { name: detail.value.student.full_name, avatar: detail.value.student.avatar });
    });
}
async function deleteAccount() {
    await run(async () => {
        await request('/api/panel/student/account', { password: password.value, confirmed: true }, 'DELETE');
        window.location.assign('/');
    });
}
onMounted(load);
</script>

<template>
    <section class="student-account">
        <div class="student-account-actions">
            <button v-if="detail && !editing" class="soft-button" @click="edit"><Pencil :size="16"/>{{ labels.edit }}</button>
            <p v-if="saved" role="status" class="account-success">{{ t.accountSaved }}</p>
        </div>
        <p v-if="error && !deleting" class="account-error" role="alert">{{ error }} <button v-if="!detail" @click="load">{{ t.accountRetry }}</button></p>
        <StudentDetailPage v-if="!editing" :detail="detail ?? {}" :is-loading="busy || !detail" :t="t" @open-tournament="emit('navigate', `/panel/tournaments/${$event.championship_id}/items/${$event.id}`)"><template #documents><StudentOwnDocuments v-if="detail" :detail="detail" :t="t"/></template></StudentDetailPage>
        <form v-else class="student-account-editor" @submit.prevent="save">
            <fieldset :disabled="busy">
                <header><h2>{{ labels.identity }}</h2><button class="panel-icon-button" type="button" :title="t.cancel" @click="editing = false"><X :size="20"/></button></header>
                <div class="student-account-fields">
                    <label v-for="[key, label, type, required] in fields" :key="key">{{ label }}
                        <input v-model="form[key]" :type="type" :required="required" :disabled="caps[key] === false" :title="caps[key] === false ? labels.locked : ''" :min="type === 'number' ? 0 : undefined" :max="key === 'weight' ? 300 : key === 'height' ? 250 : undefined" :maxlength="key === 'email' ? 255 : 100">
                    </label>
                    <label>{{ t.gender }}<select v-model="form.gender" required><option value="m">{{ t.male }}</option><option value="f">{{ t.female }}</option></select></label>
                    <label>{{ t.kyuDan }}<select v-model="form.rang" :disabled="!caps.rang"><option v-if="!ranks.includes(form.rang)" :value="form.rang">{{ form.rang || '-' }}</option><option v-for="rank in ranks" :key="rank" :value="rank">{{ locale === 'en' ? rank.replace('кю', 'kyu').replace('дан', 'dan') : rank }}</option></select></label>
                </div>
                <h2>{{ labels.documents }}</h2>
                <div class="student-account-files">
                    <div v-for="[field, label] in documentFields" :key="field" class="student-account-file">
                        <FileDropzone v-model="files[field]" :label="label" :placeholder="t.accountUpload" accept="image/jpeg,image/png,image/webp" @update:model-value="removed = removed.filter(item => item !== field)"/>
                        <div class="student-account-file-actions">
                            <a v-if="existingFile(field) && !removed.includes(field)" :href="existingFile(field)" target="_blank" rel="noopener noreferrer" :title="t.view"><ExternalLink :size="18"/></a>
                            <button v-if="existingFile(field) || files[field]" type="button" :title="removed.includes(field) ? labels.undo : labels.remove" @click="toggleRemove(field)"><Undo2 v-if="removed.includes(field)" :size="18"/><Trash2 v-else :size="18"/></button>
                            <span v-if="field !== 'avatar'">{{ t[detail.document_status.items[field].issue_key] || (detail.document_status.items[field].ok ? t.documentStatusOk : t.notConfirmed) }}</span>
                        </div>
                    </div>
                </div>
                <div class="student-account-fields">
                    <label v-for="[key, label, type] in extraFields" :key="key">{{ label }}<input v-model="form[key]" :type="type || 'text'" maxlength="255"></label>
                </div>
                <footer><button class="soft-button" type="button" @click="editing = false">{{ t.cancel }}</button><button class="save-button" type="submit"><Save :size="16"/>{{ busy ? t.loading : t.save }}</button></footer>
            </fieldset>
        </form>
        <button v-if="caps.delete_account && !editing" class="soft-button student-account-delete" @click="deleting = true; password = ''; error = ''"><Trash2 :size="16"/>{{ labels.deleteAccount }}</button>
        <ConfirmActionModal v-if="deleting" :title="labels.deleteAccount" :message="labels.deleteMessage" :busy="busy" :error="error" :t="t" @close="deleting = false" @confirm="deleteAccount"><label class="modal-field">{{ t.password }}<input v-model="password" type="password" autocomplete="current-password" required></label></ConfirmActionModal>
    </section>
</template>

<style scoped>
.student-account { min-width:0; }
.student-account :deep(.student-photo-panel) { width:112px; height:112px; min-height:0; border-radius:8px; }
.student-account :deep(.student-photo-panel img) { width:100%; height:100%; object-fit:cover; }
.student-account :deep(.student-hero-card) { grid-template-columns:112px minmax(0,1fr); }
.student-account :deep(.student-profile-stats) { grid-column:1 / -1; }
.student-account :deep(.student-hero-card) { align-items:start; gap:16px; padding:16px; }
.student-account :deep(.student-profile-main h1) { font-size:20px; font-weight:650; line-height:1.35; margin-bottom:12px; }
.student-account :deep(.student-profile-lines) { gap:8px; margin-bottom:16px; }
.student-account :deep(.student-profile-lines span) { display:block; font-weight:400; line-height:1.5; }
.student-account :deep(.student-line-icon) { display:inline-block; width:18px; height:18px; vertical-align:middle; margin-right:6px; }
.student-account :deep(.student-profile-lines b) { padding:0; border:0; background:none; font-weight:500; overflow-wrap:anywhere; }
.student-account :deep(.student-belt strong) { font-size:16px; font-weight:650; white-space:nowrap; }
.student-account :deep(.student-belt span) { font-size:12px; font-weight:500; padding:5px 8px; }
.student-account :deep(.student-belt i) { height:8px; }
.student-account-actions button, .student-account-delete { display:inline-flex; align-items:center; gap:8px; }
.student-account-actions, .student-account-file-actions { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:12px; }
.student-account-editor { padding:20px; background:var(--panel-card-solid); border:1px solid var(--panel-line); border-radius:8px; }
fieldset { border:0; padding:0; min-width:0; }
header, footer { display:flex; align-items:center; justify-content:space-between; gap:12px; }
h2 { font-size:17px; margin:0 0 16px; }
.student-account-fields, .student-account-files { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; margin-bottom:24px; }
label { display:grid; gap:6px; min-width:0; font-size:13px; }
input, select { width:100%; min-width:0; padding:10px; border:1px solid var(--panel-line); border-radius:6px; background:var(--panel-card-solid); color:inherit; }
.student-account-file { min-width:0; }
.student-account-file-actions { margin:10px 0 0; font-size:12px; }
.student-account-file-actions button, .student-account-file-actions a { display:inline-flex; padding:8px; color:inherit; }
.student-account-delete { margin-top:24px; color:var(--panel-red); }
@media(max-width:900px) { .student-account-fields, .student-account-files { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media(max-width:600px) { .student-account-fields, .student-account-files { grid-template-columns:minmax(0,1fr); } .student-account-editor { padding:12px; } input, select { font-size:16px; } }
@media(max-width:600px) { .student-account :deep(.student-hero-card) { grid-template-columns:minmax(0,1fr); } .student-account :deep(.student-photo-panel) { width:80px; height:80px; } .student-account :deep(.student-photo-panel span) { font-size:28px; } }
</style>
