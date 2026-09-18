<script setup>
import { ref } from 'vue';
import { Trash2, X } from '@lucide/vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ championshipId: [Number, String], tournament: Object, t: Object });
const emit = defineEmits(['done']);
const open = ref(false);
const { request, run, busy, error } = useAccountRequest(() => props.t);
function remove() {
    run(async () => {
        await request(`/api/panel/tournaments/${props.championshipId}/items/${props.tournament.id}`, {}, 'DELETE');
        open.value = false; emit('done');
    });
}
</script>
<template>
    <button type="button" class="danger-icon-button" :title="t.tourDelete" :aria-label="t.tourDelete" @click.stop="open = true"><Trash2 :size="16" /></button>
        <div v-if="open" class="modal-backdrop application-modal-backdrop" @click.self="!busy && (open = false)">
            <form class="template-modal application-modal" role="dialog" aria-modal="true" :aria-label="t.tourDelete" @submit.prevent="remove">
                <header><h2>{{ t.tourDelete }}</h2><button type="button" class="panel-icon-button" :disabled="busy" :aria-label="t.cancel" @click="open = false"><X :size="18" /></button></header>
                <p>{{ tournament.name }}</p><p>{{ t.tourDeleteConfirm }}</p>
                <p v-if="error" role="alert" class="form-error">{{ error }}</p>
                <footer><button type="button" class="soft-button" :disabled="busy" @click="open = false">{{ t.cancel }}</button><button type="submit" class="danger-button" :disabled="busy">{{ t.delete }}</button></footer>
            </form>
        </div>
</template>
