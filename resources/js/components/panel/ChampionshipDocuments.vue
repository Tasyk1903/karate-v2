<script setup>
import { onMounted, ref } from 'vue';
import { Plus, Pencil, Trash2, Download, ExternalLink, FileText, X } from '@lucide/vue';
import AccountPager from './AccountPager.vue';
import FileDropzone from './FileDropzone.vue';
import ConfirmActionModal from './ConfirmActionModal.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ championshipId: Number, t: Object });
const { request, run, busy, error } = useAccountRequest(() => props.t);
const rows = ref([]), page = ref(1), last = ref(1), canManage = ref(false);
const editor = ref(null), removing = ref(null), name = ref(''), file = ref(null);
const base = `/api/panel/tournaments/${props.championshipId}/documents`;
async function load(target = page.value) {
    const data = await request(`${base}?page=${target}`);
    rows.value = data.data; page.value = data.meta.current_page; last.value = data.meta.last_page; canManage.value = data.can_manage;
}
onMounted(() => run(() => load()));
function edit(item = {}) { error.value = ''; editor.value = item; name.value = item.name || ''; file.value = null; }
function save() {
    run(async () => {
        const body = new FormData(); body.set('name', name.value);
        if (file.value) body.set('file', file.value);
        if (editor.value.id) body.set('_method', 'PUT');
        await request(editor.value.id ? `${base}/${editor.value.id}` : base, body);
        editor.value = null; await load(1);
    });
}
function remove() {
    run(async () => {
        await request(`${base}/${removing.value.id}`, {}, 'DELETE'); removing.value = null;
        await load(rows.value.length === 1 ? Math.max(1, page.value - 1) : page.value);
    });
}
</script>
<template>
    <section class="championship-documents" :aria-label="t.documents" :aria-busy="busy">
        <header><h2>{{ t.documents }}</h2><button v-if="canManage" class="create-button" :disabled="busy" @click="edit()"><Plus :size="18" />{{ t.championshipAddDocument }}</button></header>
        <p v-if="error && !editor && !removing" role="alert" class="form-error">{{ error }} <button class="soft-button" @click="run(() => load())">{{ t.retry }}</button></p>
        <p v-if="!busy && !rows.length && !error">{{ t.championshipNoDocuments }}</p>
        <ul>
            <li v-for="item in rows" :key="item.id">
                <FileText :size="22" /><div class="document-title"><strong>{{ item.name }}</strong><small>{{ item.extension.toUpperCase() }}</small></div>
                <div class="document-actions">
                    <a class="panel-icon-button" :title="t.championshipOpenDocument" :aria-label="t.championshipOpenDocument" :href="`${base}/${item.id}/file`" target="_blank" rel="noopener"><ExternalLink :size="18" /></a>
                    <a class="panel-icon-button" :title="t.download" :aria-label="t.download" :href="`${base}/${item.id}/file?download=1`"><Download :size="18" /></a>
                    <button v-if="canManage" class="panel-icon-button" :disabled="busy" :title="t.edit" :aria-label="t.edit" @click="edit(item)"><Pencil :size="18" /></button>
                    <button v-if="canManage" class="panel-icon-button" :disabled="busy" :title="t.delete" :aria-label="t.delete" @click="error = ''; removing = item"><Trash2 :size="18" /></button>
                </div>
            </li>
        </ul>
        <AccountPager :page="page" :last="last" :busy="busy" :t="t" @change="target => run(() => load(target))" />
        <div v-if="editor" class="modal-backdrop application-modal-backdrop" @click.self="!busy && (editor = null)">
            <form class="template-modal application-modal" role="dialog" aria-modal="true" :aria-label="t.documents" @submit.prevent="save">
                <header><h2>{{ editor.id ? t.edit : t.championshipAddDocument }}</h2><button type="button" class="panel-icon-button" :title="t.cancel" :disabled="busy" @click="editor = null"><X :size="18" /></button></header>
                <p v-if="error" role="alert" class="form-error">{{ error }}</p>
                <label class="modal-field"><span>{{ t.championshipDocumentName }}</span><input v-model="name" required maxlength="160" /></label>
                <FileDropzone v-model="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp" :label="t.championshipDocumentFile" :placeholder="t.dropFile" :hint="t.championshipDocumentLimit" />
                <footer><button type="button" class="soft-button" :disabled="busy" @click="editor = null">{{ t.cancel }}</button><button class="save-button" :disabled="busy || (!editor.id && !file)">{{ t.save }}</button></footer>
            </form>
        </div>
        <ConfirmActionModal v-if="removing" :t="t" :title="t.delete" :message="`${t.championshipDeleteDocument} ${removing.name}`" :busy="busy" :error="error" @close="removing = null" @confirm="remove" />
    </section>
</template>
<style scoped>
.championship-documents { padding: 16px 0; }
header, .document-actions { display:flex; align-items:center; gap:8px; }
header { justify-content:space-between; flex-wrap:wrap; }
h2 { font-size:18px; margin:0; }
ul { padding:0; margin:16px 0; list-style:none; }
li { display:flex; align-items:center; gap:12px; padding:14px 0; border-bottom:1px solid var(--border-color, #d8d8df); }
.document-title { min-width:0; flex:1; overflow-wrap:anywhere; }
strong { font-size:14px; font-weight:600; }
small { display:block; font-size:11px; opacity:.6; margin-top:4px; }
.document-actions { flex-wrap:wrap; justify-content:flex-end; }
@media(max-width:480px) { li { flex-wrap:wrap; } .document-actions { width:100%; } }
</style>
