<script setup>
import { computed, ref } from 'vue';
import { X } from '@lucide/vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ student: Object, tournament: Object, championshipId: [String, Number], lists: Array, initialList: [String, Number], action: String, t: Object, locale: { type: String, default: 'ru' } });
const emit = defineEmits(['close', 'done']);
const { request, run, busy, error } = useAccountRequest(() => props.t);
const selected = ref(props.student.memberships?.find(item => Number(item.list_id) === Number(props.initialList))?.id ?? (props.student.memberships?.length === 1 ? props.student.memberships[0].id : null));
const target = ref('');
const membership = computed(() => props.student.memberships?.find(item => item.id === selected.value));
const targets = computed(() => (props.lists || []).filter(list => {
    if (!membership.value || Number(list.id) === Number(membership.value.list_id) || list.has_generated) return false;
    if (Number(props.tournament.tournament_type) === 1) return list.type === 'kumite' && !list.kata_type;
    return list.type === 'kata' && list.kata_type === (Number(props.tournament.tournament_type_kata) === 2 ? (membership.value.group_id ? 'group' : 'personal') : 'flag');
}));
function submit() { run(async () => {
    const path = `/api/panel/tournaments/${props.championshipId}/items/${props.tournament.id}/students/${props.student.pivot_id}`;
    await request(path + (props.action === 'move' ? '/list' : '') + '?locale=' + props.locale, { membership_id: selected.value, ...(props.action === 'move' ? { list_tournament_id: Number(target.value) } : {}) }, props.action === 'move' ? 'PUT' : 'DELETE');
    emit('done');
}); }
</script>
<template>
    <div class="modal-backdrop application-modal-backdrop" @click.self="!busy && emit('close')"><form class="template-modal application-modal" role="dialog" aria-modal="true" :aria-label="action === 'move' ? t.moveToList : t.listDetachApplication" @submit.prevent="submit">
        <header><h2>{{ action === 'move' ? t.moveToList : t.listDetachApplication }}</h2><button type="button" class="panel-icon-button" :disabled="busy" :aria-label="t.cancel" @click="emit('close')"><X :size="18"/></button></header>
        <strong>{{ student.name }}</strong><p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <label v-for="item in student.memberships" :key="item.id" class="application-option"><input v-model="selected" type="radio" :value="item.id" :disabled="busy" @change="target = ''"><span>{{ item.name }}<small>{{ item.group_id ? t.listGroupApplication : t.listPersonalApplication }}</small></span></label>
        <p v-if="membership?.locked" class="form-error">{{ t.listGeneratedLocked }}</p>
        <p v-else-if="membership?.group_id">{{ action === 'move' ? t.listMoveWholeGroup : t.listDetachWholeGroup }}</p>
        <label v-if="action === 'move'" class="modal-field"><span>{{ t.list }}</span><select v-model="target" :disabled="busy || membership?.locked"><option value="">{{ t.selectList }}</option><option v-for="list in targets" :key="list.id" :value="list.id">{{ list.name }}</option></select></label>
        <footer><button type="button" class="soft-button" :disabled="busy" @click="emit('close')">{{ t.cancel }}</button><button type="submit" class="save-button" :disabled="busy || !membership || membership.locked || (action === 'move' && !target)">{{ action === 'move' ? t.moveToList : t.listDetachApplication }}</button></footer>
    </form></div>
</template>
<style>
.application-modal-backdrop { z-index:100; }
.template-modal.application-modal { grid-template-columns:minmax(0,1fr); max-height:calc(100dvh - 32px); overflow:auto; }
.application-option { display:flex; align-items:center; gap:10px; font-size:13px; }
.application-option input { width:16px; accent-color:var(--panel-red); }
.application-option small { display:block; margin-top:4px; color:var(--panel-muted); }
</style>
