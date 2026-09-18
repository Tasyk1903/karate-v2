<script setup>
import { computed } from 'vue';

const props = defineProps({ modelValue: [Number, String], t: Object });
const emit = defineEmits(['update:modelValue']);
const values = computed(() => {
    const ranks = Array.from({ length: 11 }, (_, index) => 10 - index);
    const legacy = Number(props.modelValue);
    return Number.isInteger(legacy) && legacy > 10 ? [legacy, ...ranks] : ranks;
});
function label(value) {
    if (value === 0) return props.t.listDanBoundary;
    if (value === 10) return props.t.listWhiteBoundary;
    return value > 10 ? String(value) : props.t.listKyuBoundary.replace('{rank}', value);
}
</script>
<template>
    <select :value="modelValue ?? ''" required @change="emit('update:modelValue', $event.target.value)">
        <option value="" disabled>{{ t.kyuDan }}</option>
        <option v-for="value in values" :key="value" :value="value">{{ label(value) }}</option>
    </select>
</template>
