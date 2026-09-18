<script setup>
import { ref } from 'vue';
import { X } from '@lucide/vue';
import FileDropzone from './FileDropzone.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ championship: Object, t: Object });
const emit = defineEmits(['close', 'done']);
const name = ref(props.championship.name), banner = ref(null);
const { request, run, busy, error } = useAccountRequest(() => props.t);
function save() {
    run(async () => {
        const data = new FormData(); data.set('_method', 'PUT'); data.set('name', name.value);
        if (banner.value) data.set('banner', banner.value);
        await request(`/api/panel/tournaments/${props.championship.id}`, data);
        emit('done');
    });
}
</script>
<template>
    <div class="modal-backdrop application-modal-backdrop" @click.self="!busy && emit('close')">
        <form class="template-modal application-modal" role="dialog" aria-modal="true" :aria-label="t.tourEditChampionship" @submit.prevent="save">
            <header><h2>{{ t.tourEditChampionship }}</h2><button type="button" class="panel-icon-button" :disabled="busy" :aria-label="t.cancel" @click="emit('close')"><X :size="18" /></button></header>
            <p v-if="error" role="alert" class="form-error">{{ error }}</p>
            <label class="modal-field"><span>{{ t.tourChampionshipName }}</span><input v-model="name" required maxlength="255" /></label>
            <img v-if="championship.cover && !banner" :src="championship.cover" :alt="championship.name" class="championship-edit-image" />
            <FileDropzone v-model="banner" accept="image/jpeg,image/png,image/webp" :label="t.poster" :placeholder="t.dropPoster" />
            <footer><button type="button" class="soft-button" :disabled="busy" @click="emit('close')">{{ t.cancel }}</button><button type="submit" class="save-button" :disabled="busy">{{ t.save }}</button></footer>
        </form>
    </div>
</template>
<style>.championship-edit-image { width:100%; height:180px; object-fit:contain; }</style>
