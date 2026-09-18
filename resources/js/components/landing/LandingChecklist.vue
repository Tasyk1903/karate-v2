<script setup>
import { CircleCheck } from '@lucide/vue';
defineProps({ items: Array });
</script>
<template><ul class="landing-checklist"><li v-for="item in items" :key="item"><CircleCheck aria-hidden="true"/><span>{{ item }}</span></li></ul></template>
