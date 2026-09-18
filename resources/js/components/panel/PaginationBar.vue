<script setup>
import { computed } from 'vue';

const props = defineProps({
    meta: {
        type: Object,
        required: true,
    },
    pages: {
        type: Array,
        required: true,
    },
    rowsShown: {
        type: String,
        required: true,
    },
    of: {
        type: String,
        required: true,
    },
    rowsPerPage: {
        type: String,
        required: true,
    },
});

const mobilePages = computed(() => {
    const current = props.pages.indexOf(props.meta.current_page);
    const start = Math.max(0, Math.min(current - 1, props.pages.length - 3));
    return props.pages.slice(start, start + 3);
});

const emit = defineEmits(['change-page', 'change-per-page']);
</script>

<template>
    <div class="table-footer">
        <span>{{ rowsShown }} {{ meta.from ?? 0 }}-{{ meta.to ?? 0 }} {{ of }} {{ meta.total }}</span>
        <div class="pagination">
            <slot name="actions"/>
            <span>{{ rowsPerPage }}</span>
            <select :value="meta.per_page" @change="emit('change-per-page', Number($event.target.value))">
                <option :value="6">6</option>
                <option :value="10">10</option>
                <option :value="20">20</option>
            </select>
            <button type="button" :disabled="meta.current_page <= 1" @click="emit('change-page', 1)">«</button>
            <button type="button" :disabled="meta.current_page <= 1" @click="emit('change-page', meta.current_page - 1)">‹</button>
            <button
                v-for="page in pages"
                :key="page"
                type="button"
                :class="{ active: page === meta.current_page, 'mobile-page-hidden': !mobilePages.includes(page) }"
                :aria-current="page === meta.current_page ? 'page' : undefined"
                @click="emit('change-page', page)"
            >
                {{ page }}
            </button>
            <button type="button" :disabled="meta.current_page >= meta.last_page" @click="emit('change-page', meta.current_page + 1)">›</button>
            <button type="button" :disabled="meta.current_page >= meta.last_page" @click="emit('change-page', meta.last_page)">»</button>
        </div>
    </div>
</template>
