<script setup>
import { X } from '@lucide/vue';
defineProps({ title: String, message: String, busy: Boolean, error: String, t: Object, confirmLabel: String });
const emit = defineEmits(['close', 'confirm']);
</script>
<template>
    <div class="modal-backdrop" @click.self="!busy && emit('close')">
        <form class="template-modal confirm-modal" role="dialog" aria-modal="true" :aria-label="title" @submit.prevent="emit('confirm')">
            <header><h2>{{ title }}</h2><button type="button" class="panel-icon-button" :disabled="busy" :title="t.cancel" @click="emit('close')"><X :size="20" /></button></header>
            <p>{{ message }}</p><p v-if="error" role="alert" class="form-error">{{ error }}</p>
            <slot/>
            <footer><button type="button" class="soft-button" :disabled="busy" @click="emit('close')">{{ t.cancel }}</button><button type="submit" class="danger-button" :disabled="busy">{{ confirmLabel || t.delete }}</button></footer>
        </form>
    </div>
</template>
<style scoped>
.modal-backdrop { z-index:100; }
</style>
