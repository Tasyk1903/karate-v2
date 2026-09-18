<script setup>
import { ref } from 'vue';

const props = defineProps({
    accept: {
        type: String,
        default: '',
    },
    hint: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        required: true,
    },
    modelValue: {
        type: [File, Object],
        default: null,
    },
    placeholder: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['update:modelValue']);
const isDragging = ref(false);

function setFile(file) {
    emit('update:modelValue', file ?? null);
}

function chooseFile(event) {
    setFile(event.target.files?.[0] ?? null);
    event.target.value = '';
}

function dropFile(event) {
    isDragging.value = false;
    setFile(event.dataTransfer.files?.[0] ?? null);
}
</script>

<template>
    <label
        class="file-dropzone"
        :class="{ dragging: isDragging, filled: modelValue }"
        @dragenter.prevent="isDragging = true"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="dropFile"
    >
        <span>{{ label }}</span>
        <input type="file" :accept="accept" @change="chooseFile">
        <strong>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M20 16.5V19a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2.5"/></svg>
            {{ modelValue?.name ?? placeholder }}
        </strong>
        <small v-if="hint">{{ hint }}</small>
    </label>
</template>
