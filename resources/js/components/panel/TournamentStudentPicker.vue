<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { X } from '@lucide/vue';
import AccountPager from './AccountPager.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ championshipId: [String, Number], tournament: Object, t: Object, locale: { type: String, default: 'ru' } });
const emit = defineEmits(['close', 'done']);
const { request, run, busy, error } = useAccountRequest(() => props.t);
const search = ref(''), selected = ref([]), page = ref(null);
const base = `/api/panel/tournaments/${props.championshipId}/items/${props.tournament.id}`;
let timer;
function load(number = 1) { return run(async () => { page.value = await request(base + '/student-attach-options?' + new URLSearchParams({ page: number, search: search.value, locale: props.locale })); }); }
function find() { clearTimeout(timer); timer = setTimeout(() => { if (busy.value) find(); else load(); }, 250); }
function attach() { run(async () => { await request(base + '/students/group?locale=' + props.locale, { student_ids: selected.value }); emit('done'); }); }
onMounted(() => load());
onBeforeUnmount(() => clearTimeout(timer));
</script>
<template>
    <div class="modal-backdrop application-modal-backdrop" @click.self="!busy && emit('close')"><form class="template-modal application-modal" role="dialog" aria-modal="true" :aria-label="t.attachGroupStudents" @submit.prevent="attach">
        <header><h2>{{ t.attachGroupStudents }}</h2><button type="button" class="panel-icon-button" :disabled="busy" :aria-label="t.cancel" @click="emit('close')"><X :size="18"/></button></header>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <input v-model="search" class="modal-search-input" :placeholder="t.studentSearch" :aria-label="t.studentSearch" @input="find">
        <div class="modal-options-list"><label v-for="student in page?.data" :key="student.id" class="modal-check-row"><input v-model="selected" type="checkbox" :value="student.id" :disabled="busy || (selected.length >= 3 && !selected.includes(student.id))"><span>{{ student.name }}<small>{{ student.club || '-' }} · {{ student.rang || '-' }} · {{ student.age || '-' }}</small></span></label><p v-if="page && !page.data.length">{{ t.emptyTeam }}</p></div>
        <span>{{ t.listSelected }}: {{ selected.length }}</span>
        <AccountPager v-if="page" :t="t" :page="page.meta.current_page" :last="page.meta.last_page" :busy="busy" @change="load"/>
        <footer><button type="button" class="soft-button" :disabled="busy" @click="emit('close')">{{ t.cancel }}</button><button type="submit" class="save-button" :disabled="busy || selected.length < 2 || selected.length > 3">{{ t.add }}</button></footer>
    </form></div>
</template>
