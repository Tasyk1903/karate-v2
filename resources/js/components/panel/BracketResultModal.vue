<script setup>
import { computed, reactive } from 'vue';
import { X, RefreshCw } from '@lucide/vue';
import { useAccountRequest } from '../../composables/useAccountRequest';

const props = defineProps({ pool: Object, initialWinner: [Number, String], revision: String, basePath: String, t: Object, locale: String });
const emit = defineEmits(['close', 'done']);
const { request, run, busy, error } = useAccountRequest(() => props.t);
const sides = computed(() => ['student', 'opponent'].filter(side => props.pool[side] && props.pool[`${side}_id`]));
const form = reactive({ winner_id: '', student_wazari_count: 0, opponent_wazari_count: 0, student_ippon: false, opponent_ippon: false, absent_student: false, absent_opponent: false });
function name(side) { return [props.pool[side]?.last_name, props.pool[side]?.first_name].filter(Boolean).join(' '); }
function clearScores() {
    for (const side of ['student', 'opponent']) { form[`${side}_wazari_count`] = 0; form[`${side}_ippon`] = false; }
}
function chooseWinner(id) {
    form.winner_id = id;
    form.absent_student = false; form.absent_opponent = false;
    clearScores();
}
function changeAbsence() { form.winner_id = ''; clearScores(); }
if (props.pool.absent_student || props.pool.absent_opponent) {
    form.absent_student = Boolean(props.pool.absent_student); form.absent_opponent = Boolean(props.pool.absent_opponent);
} else {
    chooseWinner(props.initialWinner || props.pool.winner_id || '');
    if (Number(form.winner_id) === Number(props.pool.winner_id)) for (const side of sides.value) {
        if (Number(props.pool[`${side}_id`]) !== Number(form.winner_id)) continue;
        form[`${side}_wazari_count`] = Number(props.pool[`${side}_wazari_count`] || 0);
        form[`${side}_ippon`] = Boolean(props.pool[`${side}_ippon`]);
    }
}
function submit() { run(async () => {
    const absentIds = sides.value.filter(side => form[`absent_${side}`]).map(side => props.pool[`${side}_id`]);
    const winner = Boolean(form.winner_id) && !absentIds.length;
    const body = winner ? { winner_id: Number(form.winner_id), ...Object.fromEntries(['student', 'opponent'].flatMap(side => [[`${side}_wazari_count`, form[`${side}_wazari_count`]], [`${side}_ippon`, form[`${side}_ippon`]]])) } : { absent_ids: absentIds };
    await request(`${props.basePath}/pools/${props.pool.id}/${winner ? 'winner' : 'absences'}`, { ...body, revision: props.revision, locale: props.locale });
    emit('done');
}); }
</script>
<template>
    <div class="modal-backdrop application-modal-backdrop" @click.self="!busy && emit('close')">
        <form class="template-modal application-modal fight-editor" role="dialog" aria-modal="true" :aria-label="t.fightResult" @submit.prevent="submit">
            <header><h2>{{ t.fightResult }} #{{ pool.position_in_round || pool.id }}</h2><button class="panel-icon-button" type="button" :aria-label="t.cancel" :disabled="busy" @click="emit('close')"><X :size="18" /></button></header>
            <div v-for="side in sides" :key="side" class="fight-editor-side">
                <label class="fight-editor-person"><input type="radio" name="winner" :checked="Number(form.winner_id) === Number(pool[`${side}_id`])" :disabled="busy" @change="chooseWinner(pool[`${side}_id`])"><span>{{ name(side) }}<small>{{ pool[side].coach_line || '-' }}</small></span></label>
                <div class="fight-editor-scores">
                    <label><span>{{ t.wazari }}</span><input v-model.number="form[`${side}_wazari_count`]" type="number" min="0" max="2" step="1" required :aria-label="`${t.wazari}: ${name(side)}`" :disabled="busy || Number(form.winner_id) !== Number(pool[`${side}_id`])"></label>
                    <label><input v-model="form[`${side}_ippon`]" type="checkbox" :disabled="busy || Number(form.winner_id) !== Number(pool[`${side}_id`])"><span>{{ t.ippon }}</span></label>
                    <label><input v-model="form[`absent_${side}`]" type="checkbox" :disabled="busy" @change="changeAbsence"><span>{{ t.absence }}</span></label>
                </div>
            </div>
            <label class="fight-editor-person"><input type="radio" name="winner" :checked="!form.winner_id && !form.absent_student && !form.absent_opponent" :disabled="busy" @change="chooseWinner('')"><span>{{ t.fightNoResult }}</span></label>
            <p v-if="error" class="form-error" role="alert">{{ error }}</p>
            <footer><button v-if="error" type="button" class="soft-button" :disabled="busy" @click="emit('done')"><RefreshCw :size="16" />{{ t.fightRefresh }}</button><button type="button" class="soft-button" :disabled="busy" @click="emit('close')">{{ t.cancel }}</button><button type="submit" class="save-button" :disabled="busy">{{ t.saveResult }}</button></footer>
        </form>
    </div>
</template>
<style>
.fight-editor { width:min(560px, calc(100vw - 32px)); font-size:13px; }
.fight-editor header h2 { font-size:17px; }
.fight-editor-side { min-width:0; padding:12px 0; border-bottom:1px solid var(--panel-line); }
.fight-editor-person { display:flex; align-items:center; gap:10px; min-width:0; }
.fight-editor-person span { overflow-wrap:anywhere; line-height:1.4; }
.fight-editor-person small { display:block; margin-top:3px; color:var(--panel-muted); font-size:12px; }
.fight-editor input[type=radio], .fight-editor input[type=checkbox] { width:16px; height:16px; padding:0; flex:none; accent-color:var(--panel-red); }
.fight-editor-scores { display:flex; flex-wrap:wrap; gap:12px 20px; margin:12px 0 0 26px; }
.fight-editor-scores label { display:flex; align-items:center; gap:7px; }
.fight-editor-scores input[type=number] { width:60px; min-height:32px; padding:5px 8px; border:1px solid var(--panel-line); border-radius:6px; background:var(--panel-card-solid); color:var(--panel-text); }
.fight-editor input:disabled { opacity:.45; }
.fight-editor footer { flex-wrap:wrap; }
.fight-editor footer button { display:inline-flex; align-items:center; justify-content:center; gap:6px; width:auto; }
@media (max-width:600px) { .fight-editor footer button { flex:1; } }
</style>
