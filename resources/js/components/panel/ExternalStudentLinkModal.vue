<script setup>
import { onMounted, ref } from 'vue';
import { X } from '@lucide/vue';
import AccountPager from './AccountPager.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ base: String, row: Object, revision: String, locale: String, t: Object });
const emit = defineEmits(['close', 'linked']);
const { request, run, busy, error } = useAccountRequest(() => props.t);
const search = ref(''), data = ref(null), selected = ref(null);
function load(page = 1) { run(async () => { data.value = await request(`${props.base}/link-options?${new URLSearchParams({ search: search.value, page })}`); }); }
function link() { run(async () => { await request(props.base + '/links', { row_id: props.row.row_id, student_id: selected.value, revision: props.revision, locale: props.locale }); emit('linked'); }); }
onMounted(() => load());
</script>
<template>
    <div class="modal-backdrop form-modal-backdrop" @click.self="!busy && emit('close')"><section class="template-modal form-participant-modal" role="dialog" aria-modal="true" :aria-label="t.formLinkStudent">
        <header><div><h2>{{ t.formLinkStudent }}</h2><small>{{ row.last_name }} {{ row.first_name }} · {{ row.birthday }}</small></div><button type="button" class="panel-icon-button" :disabled="busy" :aria-label="t.cancel" @click="emit('close')"><X :size="18"/></button></header>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <form class="form-link-search" @submit.prevent="load()"><input v-model="search" :placeholder="t.search" :aria-label="t.search"><button type="submit" class="soft-button" :disabled="busy">{{ t.search }}</button></form>
        <label v-for="student in data?.rows" :key="student.id" class="form-link-option"><input v-model="selected" type="radio" :value="student.id" name="linked-student"><span>{{ student.name }}<small>{{ student.birthday }} · {{ student.club }} · {{ student.coach }}</small></span></label>
        <p v-if="data && !data.rows.length">{{ t.emptyTeam }}</p>
        <AccountPager v-if="data" :t="t" :page="data.meta.current_page" :last="data.meta.last_page" :busy="busy" @change="load"/>
        <footer><button class="soft-button" :disabled="busy" @click="emit('close')">{{ t.cancel }}</button><button class="save-button" :disabled="busy || !selected" @click="link">{{ t.formConfirmLink }}</button></footer>
    </section></div>
</template>
<style>
.form-link-search { display:flex; gap:8px; margin:16px 0; }
.form-link-search input { min-width:0; flex:1; padding:8px; background:var(--panel-card-solid); border:1px solid var(--panel-line); color:inherit; border-radius:6px; }
.form-link-option { display:flex; gap:10px; padding:12px 0; border-bottom:1px solid var(--panel-line); font-size:13px; }
.form-link-option input { width:16px; flex-shrink:0; accent-color:var(--panel-red); }
.form-link-option small { display:block; color:var(--panel-muted); margin-top:4px; }
</style>
