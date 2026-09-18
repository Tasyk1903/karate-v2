<script setup>
import TemplateModal from '../../components/panel/TemplateModal.vue';
import PaginationBar from '../../components/panel/PaginationBar.vue';
import { ArrowUp, ArrowDown } from '@lucide/vue';

const props = defineProps({
    displayedPages: {
        type: Array,
        required: true,
    },
    draggedTemplateId: {
        type: [Number, String],
        default: null,
    },
    filters: {
        type: Object,
        required: true,
    },
    isLoadingLists: {
        type: Boolean,
        default: false,
    },
    listMeta: {
        type: Object,
        required: true,
    },
    listStats: {
        type: Object,
        required: true,
    },
    showAllTemplates: {
        type: Boolean,
        required: true,
    },
    showTemplateModal: {
        type: Boolean,
        required: true,
    },
    templateError: {
        type: String,
        default: '',
    },
    templateForm: {
        type: Object,
        required: true,
    },
    templateLists: {
        type: Array,
        required: true,
    },
    editingTemplate: {
        type: Object,
        default: null,
    },
    t: {
        type: Object,
        required: true,
    },
    visibleFormFields: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits([
    'close-template-modal',
    'delete-template',
    'drop-template',
    'duplicate-template',
    'load-template-lists',
    'open-template-modal',
    'reset-filters',
    'save-template',
    'start-template-drag',
    'toggle-show-all-templates',
]);

function moveTemplate(item, offset) {
    const index = props.templateLists.findIndex(row => row.id === item.id);
    const target = props.templateLists[index + offset];
    if (!target || props.isLoadingLists) return;
    emit('start-template-drag', item);
    emit('drop-template', target);
}

function labelFor(t, type, value) {
    const map = {
        list_type: { kumite: t.kumite, kata: t.kata },
        kata_type: { personal: t.personal, group: t.group, flag: t.flag },
        gender: { m: t.male, f: t.female },
    };

    return map[type]?.[value] ?? value ?? '-';
}
</script>

<template>
    <section class="page-hero">
        <div class="hero-title-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/></svg>
        </div>
        <div class="page-title">
            <h1>{{ t.templates }}</h1>
            <p>{{ t.pageLead }}</p>
        </div>
        <div class="stat-card">
            <i><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/></svg></i>
            <span>{{ t.totalTemplates }}</span>
            <strong>{{ listStats.total }}</strong>
        </div>
        <div class="stat-card">
            <i class="blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16"/><path d="M7 12h10"/><path d="M10 19h4"/></svg></i>
            <span>{{ t.activeFilters }}</span>
            <strong>{{ listStats.active_filters }}</strong>
        </div>
        <div class="stat-card">
            <i><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/><path d="M9 4v16"/><path d="M15 4v16"/></svg></i>
            <span>{{ t.kataCreated }}</span>
            <strong>{{ listStats.kata }}</strong>
        </div>
        <button type="button" class="create-button" @click="emit('open-template-modal')">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            {{ t.createTemplate }}
        </button>
    </section>

    <section class="filters-card">
        <div class="filters-grid">
            <label><span>{{ t.listType }}</span><select v-model="filters.list_type"><option value="">{{ t.all }}</option><option value="kumite">{{ t.kumite }}</option><option value="kata">{{ t.kata }}</option></select></label>
            <label><span>{{ t.kataType }}</span><select v-model="filters.kata_type"><option value="">{{ t.all }}</option><option value="personal">{{ t.personal }}</option><option value="group">{{ t.group }}</option><option value="flag">{{ t.flag }}</option></select></label>
            <label><span>{{ t.gender }}</span><select v-model="filters.gender"><option value="">{{ t.all }}</option><option value="m">{{ t.male }}</option><option value="f">{{ t.female }}</option></select></label>
            <label><span>{{ t.ageFrom }}</span><input v-model="filters.age_from" type="number" placeholder="4"></label>
            <label><span>{{ t.ageTo }}</span><input v-model="filters.age_to" type="number" placeholder="5"></label>
            <label><span>{{ t.weightFrom }}</span><input v-model="filters.weight_from" type="number" placeholder="0"></label>
            <label><span>{{ t.weightTo }}</span><input v-model="filters.weight_to" type="number" placeholder="100"></label>
            <label><span>{{ t.rankFrom }}</span><input v-model="filters.rang_from" type="number" placeholder="0"></label>
            <label><span>{{ t.rankTo }}</span><input v-model="filters.rang_to" type="number" placeholder="1"></label>
        </div>
        <div class="filters-footer compact">
            <div class="filter-actions">
                <label class="table-search">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.8-3.8"/></svg>
                    <input v-model="filters.search" :placeholder="t.searchByName">
                </label>
                <button type="button" class="soft-button" @click="emit('reset-filters')">{{ t.reset }}</button>
                <button type="button" class="apply-button" @click="emit('load-template-lists', 1)">{{ t.apply }}</button>
            </div>
        </div>
    </section>

    <section class="data-card" :class="{ loading: isLoadingLists }">
        <div class="table-scroll">
            <table v-responsive-table>
                <thead>
                    <tr>
                        <th class="drag-col"></th>
                        <th>{{ t.name }}</th>
                        <th>{{ t.ageFrom }}</th>
                        <th>{{ t.ageTo }}</th>
                        <th>{{ t.weightFrom }}</th>
                        <th>{{ t.weightTo }}</th>
                        <th>{{ t.rankFrom }}</th>
                        <th>{{ t.rankTo }}</th>
                        <th>{{ t.gender }}</th>
                        <th>{{ t.actions }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(item, index) in templateLists"
                        :key="item.id"
                        :class="{ 'drag-over': draggedTemplateId && draggedTemplateId !== item.id }"
                        @dragover.prevent
                        @drop="emit('drop-template', item)"
                    >
                        <td class="drag-col">
                            <button
                                type="button"
                                class="drag-handle"
                                draggable="true"
                                @dragstart="emit('start-template-drag', item)"
                                @dragend="emit('start-template-drag', null)"
                            >
                                <span></span><span></span><span></span><span></span><span></span><span></span>
                            </button>
                        </td>
                        <td>
                            <div class="name-cell">
                                <span class="gender-dot" :class="item.gender === 'f' ? 'female' : 'male'">{{ item.gender === 'f' ? '♀' : '♂' }}</span>
                                <strong>{{ item.name }}</strong>
                                <small>{{ labelFor(t, 'list_type', item.list_type) }}<template v-if="item.kata_type"> · {{ labelFor(t, 'kata_type', item.kata_type) }}</template></small>
                            </div>
                        </td>
                        <td>{{ item.age_from ?? '-' }}</td>
                        <td>{{ item.age_to ?? '-' }}</td>
                        <td>{{ item.weight_from ?? '-' }}</td>
                        <td>{{ item.weight_to ?? '-' }}</td>
                        <td>{{ item.rang_from ?? '-' }}</td>
                        <td>{{ item.rang_to ?? '-' }}</td>
                        <td><span v-if="item.gender" class="gender-badge" :class="item.gender === 'f' ? 'female' : 'male'">{{ labelFor(t, 'gender', item.gender) }}</span><span v-else>-</span></td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="mobile-template-move" :title="t.mobileMoveUp" :aria-label="t.mobileMoveUp" :disabled="isLoadingLists || index === 0" @click="moveTemplate(item, -1)"><ArrowUp :size="18"/></button>
                                <button type="button" class="mobile-template-move" :title="t.mobileMoveDown" :aria-label="t.mobileMoveDown" :disabled="isLoadingLists || index === templateLists.length - 1" @click="moveTemplate(item, 1)"><ArrowDown :size="18"/></button>
                                <button type="button" :title="t.edit" @click="emit('open-template-modal', item)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg></button>
                                <button type="button" :title="t.duplicate" @click="emit('duplicate-template', item)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 8h11v11H8z"/><path d="M5 16H4V4h12v1"/></svg></button>
                                <button type="button" class="danger" :title="t.delete" @click="emit('delete-template', item)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationBar
            v-if="!showAllTemplates"
            :meta="listMeta"
            :of="t.of"
            :pages="displayedPages"
            :rows-per-page="t.rowsPerPage"
            :rows-shown="t.rowsShown"
            @change-page="emit('load-template-lists', $event)"
            @change-per-page="listMeta.per_page = $event; emit('load-template-lists', 1)"
        >
            <template #actions>
                <button type="button" class="expand-page-button" @click="emit('toggle-show-all-templates')">{{ t.showAll }}</button>
            </template>
        </PaginationBar>
        <div v-else class="table-footer">
            <span>{{ t.rowsShown }} {{ listMeta.from ?? 0 }}-{{ listMeta.to ?? 0 }} {{ t.of }} {{ listMeta.total }}</span>
            <div class="pagination">
                <button type="button" class="expand-page-button" @click="emit('toggle-show-all-templates')">{{ t.showPages }}</button>
            </div>
        </div>
    </section>

    <TemplateModal
        v-if="showTemplateModal"
        :editing-template="editingTemplate"
        :template-error="templateError"
        :template-form="templateForm"
        :t="t"
        :visible-form-fields="visibleFormFields"
        @close="emit('close-template-modal')"
        @save="emit('save-template')"
    />
</template>
