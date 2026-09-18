<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { X } from '@lucide/vue';
defineProps({ document: Object, t: Object });
const emit = defineEmits(['close']);
const failed = ref(false);
function keydown(event) { if (event.key === 'Escape') emit('close'); }
onMounted(() => window.addEventListener('keydown', keydown));
onUnmounted(() => window.removeEventListener('keydown', keydown));
</script>
<template>
    <div class="document-lightbox-backdrop" @click.self="emit('close')">
        <section class="document-lightbox" role="dialog" aria-modal="true" :aria-label="t[document.key]">
            <header><h2>{{ t[document.key] }}</h2><button type="button" class="panel-icon-button" :title="t.cancel" @click="emit('close')"><X :size="20" /></button></header>
            <div class="document-lightbox-stage"><p v-if="failed" role="alert">{{ t.documentUnavailable }}</p><img v-else :src="document.file" :alt="t[document.key]" @error="failed = true"></div>
        </section>
    </div>
</template>
