<script setup>
import { LayoutGrid, FileText } from '@lucide/vue';
defineProps({
    name: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <span class="nav-icon" :data-icon="name">
        <LayoutGrid v-if="name === 'grid'" :size="20"/>
        <FileText v-else-if="name === 'file'" :size="20"/>
        <svg v-else-if="name === 'info'" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5"/>
            <path d="M12 10.8v5"/>
            <path d="M12 7.4h.01"/>
        </svg>
        <svg v-else-if="name === 'users'" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="9" cy="8" r="3"/>
            <path d="M3.8 19.5c.7-3.2 2.8-5.1 5.2-5.1s4.5 1.9 5.2 5.1"/>
            <circle cx="17.2" cy="9.7" r="2.4"/>
            <path d="M14.8 16.1c.7-.8 1.5-1.2 2.5-1.2 1.6 0 2.9 1.1 3.4 3"/>
        </svg>
        <svg v-else-if="name === 'book'" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 3h9l3 3v15H6z"/>
            <path d="M15 3v5h5"/>
            <path d="M9 12h6M9 16h5"/>
        </svg>
        <svg v-else-if="name === 'list'" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M8 6h12M8 12h12M8 18h12"/>
            <path d="M4 6h.01M4 12h.01M4 18h.01"/>
        </svg>
        <svg v-else-if="name === 'trophy'" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M8 4h8v3a4 4 0 0 1-8 0z"/>
            <path d="M8 5H4v2a4 4 0 0 0 4 4"/>
            <path d="M16 5h4v2a4 4 0 0 1-4 4"/>
            <path d="M12 11v5"/>
            <path d="M9 20h6"/>
            <path d="M10 16h4"/>
        </svg>
        <svg v-else-if="name === 'settings'" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 8.4a3.6 3.6 0 1 1 0 7.2 3.6 3.6 0 0 1 0-7.2z"/>
            <path d="M19.1 13.5c.1-.5.1-1 .1-1.5s0-1-.1-1.5l2-1.5-2-3.5-2.4 1a8 8 0 0 0-2.6-1.5L13.8 2h-4l-.3 3a8 8 0 0 0-2.6 1.5l-2.4-1-2 3.5 2 1.5c-.1.5-.1 1-.1 1.5s0 1 .1 1.5l-2 1.5 2 3.5 2.4-1a8 8 0 0 0 2.6 1.5l.3 3h4l.3-3a8 8 0 0 0 2.6-1.5l2.4 1 2-3.5z"/>
        </svg>
        <svg v-else viewBox="0 0 24 24" aria-hidden="true">
            <rect x="5" y="5" width="14" height="14" rx="3"/>
        </svg>
    </span>
</template>
