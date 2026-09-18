<script setup>
import { ref } from 'vue';
import { UserPlus, Unlink } from '@lucide/vue';
import ConfirmActionModal from './ConfirmActionModal.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
import { studentLabels } from '../../i18n/student';
const props = defineProps({ tournament: Object, championshipId: Number, t: Object, locale: String });
const emit = defineEmits(['updated']);
const action = ref(null);
const { busy, error, request, run } = useAccountRequest(() => props.t);
async function confirm() {
    await run(async () => {
        const base = `/api/panel/tournaments/${props.championshipId}/items/${props.tournament.id}/self`;
        await request(action.value.id ? `${base}/${action.value.id}` : base, { confirmed: true }, action.value.id ? 'DELETE' : 'POST');
        action.value = null; emit('updated');
    });
}
</script>
<template>
    <template v-if="tournament.self_enrollment">
        <button v-if="tournament.self_enrollment.can_attach" type="button" class="save-button" @click="action = { label: studentLabels[locale].enroll, name: tournament.name }"><UserPlus :size="16"/>{{ studentLabels[locale].enroll }}</button>
        <template v-for="membership in tournament.self_enrollment.memberships" :key="membership.id"><button v-if="membership.can_detach" type="button" class="soft-button" @click="action = { ...membership, label: studentLabels[locale].withdraw }"><Unlink :size="16"/>{{ studentLabels[locale].withdraw }}</button></template>
        <ConfirmActionModal v-if="action" :title="action.label" :message="action.name" :confirm-label="action.label" :busy="busy" :error="error" :t="t" @close="action = null" @confirm="confirm"/>
    </template>
</template>
