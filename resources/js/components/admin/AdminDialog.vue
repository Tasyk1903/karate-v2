<script setup>
import { ref, onMounted } from 'vue';
import { X } from '@lucide/vue';
defineProps({ title: String, busy: Boolean, t: Object });
const emit = defineEmits(['close']);
const dialog = ref(null);
onMounted(() => dialog.value.showModal());
</script>
<template><dialog ref="dialog" class="admin-dialog admin-native" @cancel.prevent="!busy && emit('close')"><header><h2>{{ title }}</h2><button :disabled="busy" type="button" :title="t.close" @click="emit('close')"><X :size="18"/></button></header><slot/></dialog></template>
<style scoped>.admin-native { position:fixed; margin:auto; border:1px solid var(--admin-border); color:var(--admin-ink); }.admin-native::backdrop { background:#0008; }</style>
