<script setup>
import { ref, computed } from 'vue';
import { activateAdminRow } from '../../components/admin/rowAction';
import { Pencil, ArrowLeft, Save } from '@lucide/vue';
import { adminLabels } from '../../i18n/admin';
import { useAdminList } from '../../composables/useAdminList';
import RichTextEditor from '../../components/admin/RichTextEditor.vue';
const props = defineProps({ locale: String });
const t = computed(() => adminLabels[props.locale]);
const { rows, loading, message, error, busy, request, load, mutate } = useAdminList(() => '/api/admin/agreements', () => t.value, () => ({ locale: props.locale }));
const editing = ref(null), language = ref('ru');
function openAgreement(row) { editing.value = { ...row }; language.value = props.locale; }
async function save() { await mutate(async () => { await request('/api/admin/agreements/' + editing.value.id, editing.value, 'PUT'); editing.value = null; }); }
</script>
<template><p v-if="message" class="admin-notice" role="status">{{ message }}</p><p v-if="error" class="admin-error" role="alert">{{ error }} <button v-if="!editing" @click="load()">{{ t.retry }}</button></p><div v-if="editing" class="admin-agreement-editor"><div class="admin-toolbar"><button :disabled="busy" @click="editing = null"><ArrowLeft :size="18"/>{{ editing.name }}</button><button class="admin-primary" :disabled="busy" @click="save"><Save :size="18"/>{{ t.save }}</button></div><div class="admin-tabs" role="tablist"><button role="tab" :aria-selected="language === 'ru'" @click="language = 'ru'">Русский</button><button role="tab" :aria-selected="language === 'en'" @click="language = 'en'">English</button></div><RichTextEditor :key="language" v-model="editing[language === 'ru' ? 'description' : 'description_en']" :t="t"/></div><p v-else-if="loading" class="admin-empty">{{ t.loading }}</p><table v-else v-responsive-table class="admin-table"><thead><tr><th>{{ t.name }}</th><th>RU / EN</th><th class="admin-actions">{{ t.actions }}</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id" class="admin-interactive-row" @click="activateAdminRow($event, () => openAgreement(row))"><td><button class="admin-cell-link" @click="openAgreement(row)">{{ row.name }}</button></td><td><div class="admin-language-status"><span class="admin-badge" :class="{ 'is-ready': row.description }">RU</span><span class="admin-badge" :class="{ 'is-ready': row.description_en, 'is-missing': !row.description_en }" :title="!row.description_en ? t.missingEnglish : undefined">EN</span><small v-if="!row.description_en">{{ t.missingEnglish }}</small></div></td><td class="admin-actions"><button :title="t.edit" @click="openAgreement(row)"><Pencil :size="18"/></button></td></tr></tbody></table></template>
