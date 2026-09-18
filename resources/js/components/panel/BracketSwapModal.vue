<script setup>
import { computed, ref } from 'vue';
import { X } from '@lucide/vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ bracket: Object, listId: [Number, String], basePath: String, t: Object, locale: String });
const emit = defineEmits(['close', 'done']);
const { request, run, busy, error } = useAccountRequest(() => props.t);
const first = ref(''); const second = ref('');
const participants = computed(() => {
    const seeds = new Set((props.bracket.swap_participant_ids || []).map(Number));
    const unique = new Map();
    for (const pool of props.bracket.tournament.pools) for (const side of ['student', 'opponent']) {
        const person = pool[side];
        if (person && seeds.has(Number(person.id))) unique.set(Number(person.id), person);
    }
    return [...unique.values()];
});
function name(person) { return [person?.last_name, person?.first_name].filter(Boolean).join(' '); }
function submit() { run(async () => {
    await request(`${props.basePath}/brackets/${props.listId}/swap`, { participant_1: Number(first.value), participant_2: Number(second.value), pool_ids: props.bracket.tournament.pools.map(pool => pool.id), revision: props.bracket.revision, locale: props.locale });
    emit('done');
}); }
</script>
<template>
    <div class="modal-backdrop application-modal-backdrop" @click.self="!busy && emit('close')"><form class="template-modal application-modal fight-editor" role="dialog" aria-modal="true" :aria-label="t.fightSwap" @submit.prevent="submit">
        <header><h2>{{ t.fightSwap }}</h2><button type="button" class="panel-icon-button" :disabled="busy" :aria-label="t.cancel" @click="emit('close')"><X :size="18" /></button></header>
        <label class="modal-field"><span>{{ t.fightFirstParticipant }}</span><select v-model="first" required :disabled="busy" @change="first === second && (second = '')"><option value="">{{ t.selectParticipant }}</option><option v-for="person in participants" :key="person.id" :value="person.id">{{ name(person) }}</option></select><small>{{ participants.find(person => person.id === first)?.coach_line }}</small></label>
        <label class="modal-field"><span>{{ t.fightSecondParticipant }}</span><select v-model="second" required :disabled="busy"><option value="">{{ t.selectParticipant }}</option><option v-for="person in participants.filter(person => Number(person.id) !== Number(first))" :key="person.id" :value="person.id">{{ name(person) }}</option></select><small>{{ participants.find(person => person.id === second)?.coach_line }}</small></label>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <footer><button v-if="error" type="button" class="soft-button" :disabled="busy" @click="emit('done')">{{ t.fightRefresh }}</button><button type="button" class="soft-button" :disabled="busy" @click="emit('close')">{{ t.cancel }}</button><button type="submit" class="save-button" :disabled="busy || !first || !second || first === second">{{ t.fightSwap }}</button></footer>
    </form></div>
</template>

<style>
.bracket-swap-button { display:inline-flex; align-items:center; gap:6px; }
.bracket-swap-button svg { width:16px; height:16px; flex:none; }
</style>
