<script setup>
import { ref } from 'vue';
import { UserPlus } from '@lucide/vue';
import ConfirmActionModal from './ConfirmActionModal.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
import { studentLabels } from '../../i18n/student';
const props = defineProps({ exam: Object, t: Object, locale: String });
const emit = defineEmits(['updated']);
const confirming = ref(false);
const { busy, error, request, run } = useAccountRequest(() => props.t);
async function attach() {
    await run(async () => {
        await request(`/api/panel/examinations/${props.exam.id}/attach-self`, {});
        confirming.value = false;
        emit('updated');
    });
}
</script>
<template>
    <button v-if="exam.can_attach_self" type="button" class="save-button" @click="confirming = true"><UserPlus :size="16"/>{{ studentLabels[locale].enroll }}</button>
    <ConfirmActionModal v-if="confirming" :title="studentLabels[locale].enroll" :message="exam.name" :confirm-label="studentLabels[locale].enroll" :busy="busy" :error="error" :t="t" @close="confirming = false" @confirm="attach"/>
</template>
