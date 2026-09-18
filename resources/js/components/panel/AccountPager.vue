<script setup>
import { ChevronLeft, ChevronRight } from '@lucide/vue';
defineProps({ page: Number, last: Number, busy: Boolean, t: Object });
defineEmits(['change']);
</script>
<template>
    <nav v-if="last > 1" class="account-pager">
        <button type="button" :disabled="busy || page <= 1" :title="t.accountPrevious" :aria-label="t.accountPrevious" @click="$emit('change', page - 1)"><ChevronLeft :size="18"/></button>
        <span>{{ t.accountPage }} {{ page }} / {{ last }}</span>
        <button type="button" :disabled="busy || page >= last" :title="t.accountNext" :aria-label="t.accountNext" @click="$emit('change', page + 1)"><ChevronRight :size="18"/></button>
    </nav>
</template>
