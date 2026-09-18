<script setup>
import { ref, watch } from 'vue';
const props = defineProps({ value: [String, Number], disabled: Boolean, label: String, save: Function });
const value = ref(props.value ?? '');
const original = ref(props.value == null ? null : String(props.value));
const busy = ref(false); const dirty = ref(false); const error = ref('');
watch(() => props.value, next => {
    if (!dirty.value && !busy.value) { value.value = next ?? ''; original.value = next == null ? null : String(next); }
});
async function submit() {
    if (busy.value || !dirty.value) return;
    busy.value = true; error.value = '';
    try {
        await props.save(value.value, original.value);
        dirty.value = false; value.value = props.value ?? ''; original.value = props.value == null ? null : String(props.value);
    } catch (failure) { error.value = failure.message; }
    finally { busy.value = false; }
}
</script>
<template>
    <input v-model="value" class="compact-score-input" inputmode="decimal" :aria-label="label" :aria-invalid="Boolean(error)" :title="error || label" :disabled="disabled || busy" @input="dirty = true; error = ''" @change="submit" @keydown.enter.prevent="submit">
</template>
