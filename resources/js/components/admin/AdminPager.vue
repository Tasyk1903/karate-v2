<script setup>
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
const props = defineProps({ page: Number, last: Number, total: Number, busy: Boolean, t: Object, range: Object });
defineEmits(['page']);
const pages = computed(() => {
    const start = Math.max(1, Math.min(props.page - 1, props.last - 2));
    const nearby = Array.from({ length: Math.min(3, props.last) }, (_, index) => start + index);
    const numbers = [...new Set([1, ...nearby, props.last])].sort((a, b) => a - b);
    return numbers.flatMap((number, index) => [
        ...(index && number - numbers[index - 1] > 1 ? [{ key: `gap-${number}`, gap: true }] : []),
        { key: number, number, boundary: !nearby.includes(number) },
    ]);
});
</script>
<template>
    <footer class="admin-pager">
        <span class="admin-page-summary"><template v-if="total && range?.from != null">{{ t.shown }} <strong>{{ range.from }}–{{ range.to }}</strong> {{ t.of }} <strong>{{ total }}</strong></template><template v-else>{{ t.total }}: <strong>{{ total }}</strong></template></span>
        <nav v-if="last > 1" :aria-label="t.pagination">
            <button :disabled="busy || page <= 1" :title="t.previous" :aria-label="t.previous" @click="$emit('page', page - 1)"><ChevronLeft :size="18"/></button>
            <template v-for="item in pages" :key="item.key">
                <span v-if="item.gap" class="admin-page-gap" aria-hidden="true">…</span>
                <button v-else :class="{ 'admin-page-boundary': item.boundary }" :aria-label="`${t.page} ${item.number}`" :aria-current="page === item.number ? 'page' : undefined" :disabled="busy" @click="$emit('page', item.number)">{{ item.number }}</button>
            </template>
            <button :disabled="busy || page >= last" :title="t.next" :aria-label="t.next" @click="$emit('page', page + 1)"><ChevronRight :size="18"/></button>
        </nav>
    </footer>
</template>
