<script setup>
import { Search, X } from '@lucide/vue';
defineProps({ modelValue: String, t: Object });
defineEmits(['update:modelValue']);
</script>

<template>
    <div class="admin-search-field">
        <Search :size="18" aria-hidden="true"/>
        <input type="search" :value="modelValue" :placeholder="t.search" :aria-label="t.search" @input="$emit('update:modelValue', $event.target.value)">
        <button v-if="modelValue" type="button" :title="t.clearSearch" :aria-label="t.clearSearch" @click="$emit('update:modelValue', '')"><X :size="16"/></button>
    </div>
</template>
