<script setup>
import { computed, ref, reactive, watch } from 'vue';
import { Plus, Pencil, Trash2, Play, ArrowLeft, ChevronRight, Folder, Film, Building2, X } from '@lucide/vue';
import { adminLabels } from '../../i18n/admin';
import { useAdminList } from '../../composables/useAdminList';
import AdminPager from '../../components/admin/AdminPager.vue';
import AdminDialog from '../../components/admin/AdminDialog.vue';
import AdminSearch from '../../components/admin/AdminSearch.vue';
import { activateAdminRow } from '../../components/admin/rowAction';
const props = defineProps({ kind: String, locale: String });
const t = computed(() => adminLabels[props.locale]);
const section = ref('kata_attestation'), category = ref(null), editing = ref(null), deleting = ref(null), preview = ref(null), regionOptions = ref([]);
const form = reactive({}), upload = ref(null), poster = ref(null);
const isVideos = computed(() => props.kind === 'education' && category.value);
const url = computed(() => props.kind === 'education' ? `/api/admin/education/${section.value}${category.value ? `/${category.value.id}/videos` : ''}` : props.kind === 'organizations' ? '/api/admin/organizations' : `/api/admin/directories/${props.kind}`);
const { rows, page, last, total, range, loading, search, selected, message, error, busy, load, request, mutate } = useAdminList(() => url.value, () => t.value);
const isCategory = computed(() => props.kind === 'education' && !category.value && section.value !== 'reviews');
const primaryLabel = computed(() => isCategory.value ? t.value.videos : isVideos.value ? t.value.open : t.value.edit);
function openRecord(row) {
    if (isCategory.value) openCategory(row);
    else if (isVideos.value) preview.value = row;
    else edit(row);
}
function backToCategories() { category.value = null; search.value = ''; }
const fields = computed(() => isVideos.value ? ['title'] : props.kind === 'organizations' ? ['name', 'email', 'region_id', 'password'] : props.kind === 'education' && section.value === 'reviews' ? ['name', 'price'] : ['name']);
function selectPage(checked) {
    const ids = rows.value.map(row => row.id);
    selected.value = checked ? [...new Set([...selected.value, ...ids])] : selected.value.filter(id => !ids.includes(id));
}
async function edit(row = {}) {
    editing.value = row;
    Object.keys(form).forEach(key => delete form[key]);
    fields.value.forEach(key => form[key] = key === 'password' ? '' : row[key] ?? '');
    upload.value = null; poster.value = null; error.value = '';
    if (props.kind === 'organizations') {
        try {
            let all = [], next = 1, result;
            do { result = await request(`/api/admin/directories/regions?page=${next++}`); all.push(...result.data); } while (result.current_page < result.last_page);
            regionOptions.value = all;
        } catch (failure) { error.value = failure.message; }
    }
}
async function save() {
    await mutate(async () => {
        const id = editing.value.id;
        if (isVideos.value) {
            const data = new FormData(); data.append('title', form.title);
            if (upload.value) data.append('video', upload.value);
            if (poster.value) data.append('poster', poster.value, 'cover.jpg');
            await request(`${url.value}${id ? '/' + id : ''}`, data);
        } else await request(`${url.value}${id ? '/' + id : ''}`, form, id ? 'PUT' : 'POST');
        editing.value = null;
    });
}
async function remove() { await mutate(async () => { await request(url.value, { ids: deleting.value, confirmed: true }, 'DELETE'); deleting.value = null; }); }
function openCategory(row) { if (props.kind === 'education' && section.value !== 'reviews') { category.value = row; search.value = ''; } }
watch(section, () => { category.value = null; search.value = ''; });
async function chooseVideo(event) {
    const file = event.target.files[0];
    if (file && file.size > 104857600) { upload.value = null; poster.value = null; error.value = t.value.uploadLimit; event.target.value = ''; return; }
    upload.value = file; error.value = ''; poster.value = null;
    if (!file) return;
    // Capture the actual uploaded frame locally; no permanent public video URL is needed.
    const video = document.createElement('video'), source = URL.createObjectURL(file);
    video.muted = true; video.preload = 'auto'; video.src = source;
    const cleanup = () => { clearTimeout(timeout); URL.revokeObjectURL(source); video.removeAttribute('src'); video.load(); };
    const timeout = setTimeout(cleanup, 10000);
    video.onloadeddata = () => { video.currentTime = Math.min(1, video.duration / 2); };
    video.onseeked = () => {
        const canvas = document.createElement('canvas'); canvas.width = 640; canvas.height = Math.round(640 * video.videoHeight / video.videoWidth);
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(blob => { if (upload.value === file && !poster.value) poster.value = blob; cleanup(); }, 'image/jpeg', .85);
    };
    video.onerror = cleanup;
}
</script>
<template>
    <div v-if="kind === 'education'" class="admin-tabs" role="tablist"><button v-for="tab in ['kata_attestation','kihon','ido_geiko','competition','reviews']" :key="tab" role="tab" :aria-selected="section === tab" @click="section = tab">{{ t[tab] }}</button></div>
    <nav v-if="kind === 'education'" class="admin-breadcrumb" :aria-label="t.education">
        <button v-if="category" :title="t.back" :aria-label="t.back" @click="backToCategories"><ArrowLeft :size="18"/></button>
        <button v-if="category" class="admin-breadcrumb-link" @click="backToCategories">{{ t[section] }}</button>
        <span v-else>{{ t[section] }}</span><ChevronRight :size="15"/><strong>{{ category ? category.name : t.categories }}</strong>
    </nav>
    <div class="admin-toolbar">
        <AdminSearch v-model="search" :t="t"/>
        <button class="admin-primary admin-create" @click="edit()"><Plus :size="18"/>{{ t.create }}</button>
    </div>
    <div v-if="selected.length" class="admin-selection" role="status">
        <span>{{ t.selected }}: <strong>{{ selected.length }}</strong></span>
        <button class="admin-danger" :title="t.remove" :aria-label="t.remove" @click="deleting = [...selected]"><Trash2 :size="18"/></button>
        <button :title="t.clearSelection" :aria-label="t.clearSelection" @click="selected = []"><X :size="18"/></button>
    </div>
    <p v-if="message" class="admin-notice" role="status">{{ message }}</p><p v-if="error && !editing && !deleting" class="admin-error" role="alert">{{ error }} <button @click="load(page)">{{ t.retry }}</button></p>
    <p v-if="loading && !rows.length" class="admin-empty">{{ t.loading }}</p>
    <table v-else v-responsive-table class="admin-table" :aria-busy="loading" :inert="loading || undefined">
        <thead><tr>
            <th class="admin-check"><input type="checkbox" :aria-label="t.all" :checked="rows.length > 0 && rows.every(row => selected.includes(row.id))" :indeterminate="rows.some(row => selected.includes(row.id)) && !rows.every(row => selected.includes(row.id))" @change="selectPage($event.target.checked)"></th>
            <th>{{ isVideos ? t.titleField : t.name }}</th>
            <th v-if="kind === 'organizations'" class="admin-secondary">{{ t.email }}</th>
            <th v-if="kind === 'organizations'" class="admin-secondary">{{ t.code }}</th>
            <th v-if="kind === 'education' && section === 'reviews'">{{ t.price }}</th>
            <th class="admin-actions">{{ t.actions }}</th>
        </tr></thead>
        <tbody><tr v-for="row in rows" :key="row.id" class="admin-interactive-row" :class="{ 'admin-row-selected': selected.includes(row.id) }" @click="activateAdminRow($event, () => openRecord(row))">
            <td class="admin-check"><input v-model="selected" type="checkbox" :value="row.id" :aria-label="row.name || row.title"></td>
            <td class="admin-name-cell">
                <button class="admin-cell-link" @click="openRecord(row)">
                    <span v-if="kind === 'education' || kind === 'organizations'" class="admin-record-icon" aria-hidden="true"><component :is="isCategory ? Folder : kind === 'organizations' ? Building2 : Film" :size="20"/></span>
                    <span>{{ row.name || row.title }}</span>
                </button>
            </td>
            <td v-if="kind === 'organizations'" class="admin-secondary">{{ row.email }}</td>
            <td v-if="kind === 'organizations'" class="admin-secondary"><span class="admin-code">{{ row.code || '—' }}</span></td>
            <td v-if="kind === 'education' && section === 'reviews'">{{ row.price }}</td>
            <td class="admin-actions"><div class="admin-row-actions">
                <button v-if="isCategory || isVideos" class="admin-open-action" :title="primaryLabel" :aria-label="primaryLabel" @click="openRecord(row)"><component :is="isVideos ? Play : ChevronRight" :size="18"/></button>
                <button :title="t.edit" :aria-label="t.edit" @click="edit(row)"><Pencil :size="18"/></button>
                <button class="admin-danger" :title="t.remove" :aria-label="t.remove" @click="deleting = [row.id]"><Trash2 :size="18"/></button>
            </div></td>
        </tr></tbody>
    </table>
    <p v-if="!loading && !rows.length" class="admin-empty">{{ t.empty }}</p>
    <AdminPager :page="page" :last="last" :total="total" :range="range" :busy="loading" :t="t" @page="load"/>
    <AdminDialog v-if="editing" :title="editing.id ? t.edit : t.create" :t="t" :busy="busy" @close="editing = null"><form @submit.prevent="save"><label v-for="field in fields" :key="field">{{ t[field === 'title' ? 'titleField' : field] }}<select v-if="field === 'region_id'" v-model="form[field]"><option value="">—</option><option v-for="region in regionOptions" :value="region.id" :key="region.id">{{ region.name }}</option></select><input v-else v-model="form[field]" :type="field === 'password' ? 'password' : field === 'email' ? 'email' : field === 'price' ? 'number' : 'text'" :required="field !== 'password' || !editing.id" :minlength="field === 'password' ? 10 : undefined" :min="field === 'price' ? 0 : undefined" :autocomplete="field === 'password' ? 'new-password' : 'off'"></label><template v-if="isVideos"><label class="admin-upload">{{ upload?.name || t.upload }}<input type="file" accept="video/mp4,video/quicktime,video/webm,video/mpeg,video/x-msvideo" :required="!editing.id" @change="chooseVideo"></label><label>{{ t.uploadPoster }}<input type="file" accept="image/*" @change="poster = $event.target.files[0]"></label></template><p v-if="error" role="alert" class="admin-error">{{ error }}</p><footer><button type="button" :disabled="busy" @click="editing = null">{{ t.cancel }}</button><button class="admin-primary" :disabled="busy">{{ busy ? t.loading : t.save }}</button></footer></form></AdminDialog>
    <AdminDialog v-if="deleting" :title="t.deleteTitle" :t="t" :busy="busy" @close="deleting = null"><p>{{ t.deleteMessage }} {{ t.selected }}: {{ deleting.length }}</p><p v-if="error" role="alert" class="admin-error">{{ error }}</p><footer><button :disabled="busy" @click="deleting = null">{{ t.cancel }}</button><button class="admin-primary" :disabled="busy" @click="remove">{{ t.remove }}</button></footer></AdminDialog>
    <AdminDialog v-if="preview" :title="preview.title" :t="t" @close="preview = null"><video class="admin-video" controls playsinline :src="preview.video_url" :poster="preview.poster_url"/></AdminDialog>
</template>
