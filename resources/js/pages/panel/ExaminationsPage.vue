<script setup>
import ExaminationModal from '../../components/panel/ExaminationModal.vue';
import PaginationBar from '../../components/panel/PaginationBar.vue';

defineProps({
    canManage: { type: Boolean, default: false },
    cities: {
        type: Array,
        required: true,
    },
    coaches: {
        type: Array,
        required: true,
    },
    displayedPages: {
        type: Array,
        required: true,
    },
    editingExam: {
        type: Object,
        default: null,
    },
    examError: {
        type: String,
        default: '',
    },
    examFilters: {
        type: Object,
        required: true,
    },
    examForm: {
        type: Object,
        required: true,
    },
    exams: {
        type: Array,
        required: true,
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
    meta: {
        type: Object,
        required: true,
    },
    showExamModal: {
        type: Boolean,
        default: false,
    },
    stats: {
        type: Object,
        required: true,
    },
    t: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits([
    'change-page',
    'change-per-page',
    'close-exam-modal',
    'delete-exam',
    'open-exam',
    'open-exam-modal',
    'save-exam',
]);

const statCards = [
    { key: 'total', label: 'totalExams', icon: 'book' },
    { key: 'planned', label: 'planned', icon: 'calendar' },
    { key: 'completed', label: 'completed', icon: 'check' },
    { key: 'students', label: 'students', icon: 'users' },
];
</script>

<template>
    <section class="page-heading exam-heading">
        <div>
            <h1>{{ t.exams }}</h1>
        </div>
        <button v-if="canManage" type="button" class="create-button" @click="emit('open-exam-modal')">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            {{ t.createExam }}
        </button>
    </section>

    <section class="exam-stats">
        <article v-for="card in statCards" :key="card.key" class="exam-stat-card" :data-icon="card.icon">
            <i>
                <svg v-if="card.icon === 'book'" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h10l2 2v16H6z"/><path d="M16 3v4h4"/></svg>
                <svg v-else-if="card.icon === 'calendar'" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v4M17 3v4"/><rect x="4" y="6" width="16" height="15" rx="3"/><path d="M4 11h16"/></svg>
                <svg v-else-if="card.icon === 'check'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="m8.5 12.5 2.2 2.2 4.8-5.2"/></svg>
                <svg v-else viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.8 19.5c.7-3.2 2.8-5.1 5.2-5.1s4.5 1.9 5.2 5.1"/><circle cx="17.2" cy="9.7" r="2.4"/><path d="M14.8 16.1c.7-.8 1.5-1.2 2.5-1.2 1.6 0 2.9 1.1 3.4 3"/></svg>
            </i>
            <strong>{{ stats[card.key] ?? 0 }}</strong>
            <span>{{ t[card.label] }}</span>
        </article>
    </section>

    <section class="exam-filter-card">
        <label class="table-search compact-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.8-3.8"/></svg>
            <input v-model="examFilters.search" :placeholder="t.examSearch">
        </label>
        <select v-model="examFilters.city">
            <option value="">{{ t.allCities }}</option>
            <option v-for="city in cities" :key="city" :value="city">{{ city }}</option>
        </select>
        <select v-model="examFilters.coach_id">
            <option value="">{{ t.allTrainers }}</option>
            <option v-for="coach in coaches" :key="coach.id" :value="coach.id">{{ coach.name }}</option>
        </select>
        <input v-model="examFilters.date_from" type="date">
        <input v-model="examFilters.date_to" type="date">
    </section>

    <section class="data-card exam-table-card" :class="{ loading: isLoading }">
        <div class="table-scroll">
            <table v-responsive-table>
                <thead>
                    <tr>
                        <th>{{ t.examName }}</th>
                        <th>{{ t.examCity }}</th>
                        <th>{{ t.examDate }}</th>
                        <th>{{ t.examReceiving }}</th>
                        <th>{{ t.participants }}</th>
                        <th>{{ t.status }}</th>
                        <th>{{ t.actions }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="exams.length === 0">
                        <td class="empty-cell" colspan="7">{{ t.emptyTeam }}</td>
                    </tr>
                    <tr v-for="exam in exams" :key="exam.id">
                        <td><strong>{{ exam.name }}</strong></td>
                        <td>{{ exam.city }}</td>
                        <td>{{ exam.date_label }}</td>
                        <td>{{ exam.receiving }}</td>
                        <td>{{ exam.students_count }}</td>
                        <td><span class="status-badge" :data-status="exam.status">{{ t[exam.status] }}</span></td>
                        <td>
                            <div class="row-actions">
                                <button type="button" :title="t.view" @click="emit('open-exam', exam)">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                                <button v-if="canManage" type="button" :title="t.edit" @click="emit('open-exam-modal', exam)">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                                </button>
                                <button v-if="canManage" type="button" class="danger" :title="t.delete" @click="emit('delete-exam', exam)">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <PaginationBar
            :meta="meta"
            :of="t.of"
            :pages="displayedPages"
            :rows-per-page="t.rowsPerPage"
            :rows-shown="t.rowsShown"
            @change-page="emit('change-page', $event)"
            @change-per-page="emit('change-per-page', $event)"
        />
    </section>

    <ExaminationModal
        v-if="showExamModal"
        :error="examError"
        :form="examForm"
        :is-editing="Boolean(editingExam)"
        :t="t"
        @close="emit('close-exam-modal')"
        @save="emit('save-exam')"
    />
</template>
