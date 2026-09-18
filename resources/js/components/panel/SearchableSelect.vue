<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    options: {
        type: Array,
        required: true,
    },
    placeholder: {
        type: String,
        required: true,
    },
    searchPlaceholder: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['update:modelValue']);
const isOpen = ref(false);
const search = ref('');

const selectedOption = computed(() => props.options.find((option) => String(option.id) === String(props.modelValue)));
const filteredOptions = computed(() => {
    const value = search.value.trim().toLowerCase();

    if (! value) {
        return props.options;
    }

    return props.options.filter((option) => option.name.toLowerCase().includes(value));
});

function select(value) {
    emit('update:modelValue', value);
    isOpen.value = false;
    search.value = '';
}
</script>

<template>
    <div class="searchable-select" :class="{ open: isOpen }">
        <button type="button" @click="isOpen = !isOpen">
            <span>{{ selectedOption?.name ?? placeholder }}</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>

        <div v-if="isOpen" class="searchable-select-menu">
            <input v-model="search" :placeholder="searchPlaceholder" autofocus>
            <button type="button" class="option" @click="select('')">{{ placeholder }}</button>
            <button
                v-for="option in filteredOptions"
                :key="option.id"
                type="button"
                class="option"
                :class="{ active: String(option.id) === String(modelValue) }"
                @click="select(option.id)"
            >
                {{ option.name }}
            </button>
        </div>
    </div>
</template>
