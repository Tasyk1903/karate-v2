<script setup>
import { ref } from 'vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
import StudentExamAction from '../../components/panel/StudentExamAction.vue';
import ConfirmActionModal from '../../components/panel/ConfirmActionModal.vue';
import AttachStudentsModal from '../../components/panel/AttachStudentsModal.vue';
import ExaminationModal from '../../components/panel/ExaminationModal.vue';
import PaginationBar from '../../components/panel/PaginationBar.vue';
import SearchableSelect from '../../components/panel/SearchableSelect.vue';

const props = defineProps({
    onDetachStudent: { type: Function, required: true },
    locale: { type: String, default: 'ru' },
    canManage: { type: Boolean, default: false },
    attachOptions: {
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
    exam: {
        type: Object,
        default: null,
    },
    examError: {
        type: String,
        default: '',
    },
    examForm: {
        type: Object,
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
    search: {
        type: String,
        default: '',
    },
    selectedAttachIds: {
        type: Array,
        required: true,
    },
    selectedCoach: {
        type: String,
        default: '',
    },
    showAttachModal: {
        type: Boolean,
        default: false,
    },
    showExamModal: {
        type: Boolean,
        default: false,
    },
    students: {
        type: Array,
        required: true,
    },
    t: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits([
    'refresh',
    'attach-students',
    'change-page',
    'change-per-page',
    'close-attach-modal',
    'close-exam-modal',
    'export',
    'open-attach-modal',
    'open-exam-modal',
    'save-exam',
    'set-coach',
    'set-search',
    'toggle-attach-student',
]);
const detachTarget = ref(null);
const { busy: detaching, error: detachError, run } = useAccountRequest(() => props.t);
function confirmDetach() {
    run(async () => { await props.onDetachStudent(detachTarget.value); detachTarget.value = null; });
}
</script>

<template>
    <template v-if="exam">
        <section class="exam-detail-heading">
            <h1>{{ t.exam }} — {{ exam.name }}</h1>
        </section>

        <section class="exam-summary-card">
            <article>
                <span>{{ t.examDate }}</span>
                <strong>{{ exam.date_label }}</strong>
                <small>{{ exam.weekday }}</small>
            </article>
            <article>
                <span>{{ t.examCity }}</span>
                <strong>{{ exam.city }}</strong>
            </article>
            <article>
                <span>{{ t.examReceiving }}</span>
                <strong>{{ exam.receiving }}</strong>
            </article>
            <article>
                <span>{{ t.status }}</span>
                <strong><span class="status-badge" :data-status="exam.status">{{ t[exam.status] }}</span></strong>
            </article>
            <div class="exam-summary-actions">
                <button v-if="canManage" type="button" class="soft-button" @click="emit('open-exam-modal')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                    {{ t.edit }}
                </button>
            </div>
        </section>

        <nav class="exam-detail-tabs exam-detail-tabs-single">
            <button type="button" class="active">{{ t.overview }}</button>
        </nav>

        <section class="exam-detail-grid">
            <aside class="exam-info-card">
                <h2>{{ t.info }}</h2>
                <div>
                    <i>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M8 12h8"/></svg>
                    </i>
                    <span>{{ t.participantsInExam }}</span>
                    <strong>{{ meta.total }}</strong>
                </div>
                <div>
                    <i>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v4M17 3v4"/><rect x="4" y="6" width="16" height="15" rx="3"/><path d="M4 11h16"/></svg>
                    </i>
                    <span>{{ t.lastUpdate }}</span>
                    <strong>{{ exam.date_label }}</strong>
                </div>
            </aside>

            <section class="data-card exam-students-card" :class="{ loading: isLoading }">
                <div class="exam-student-tools">
                    <label class="table-search compact-search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.8-3.8"/></svg>
                        <input :value="search" :placeholder="t.studentSearch" @input="emit('set-search', $event.target.value)">
                    </label>
                    <SearchableSelect
                        :model-value="selectedCoach"
                        :options="coaches"
                        :placeholder="t.allTrainers"
                        :search-placeholder="t.searchTrainer"
                        @update:model-value="emit('set-coach', $event)"
                    />
                    <StudentExamAction :exam="exam" :t="t" :locale="locale" @updated="emit('refresh')"/>
                    <button v-if="exam.can_export !== false" type="button" class="excel-button" @click="emit('export')">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h10l4 4v14H5z"/><path d="M15 3v5h4"/><path d="m8 10 5 6M13 10l-5 6"/></svg>
                        Excel
                    </button>
                </div>

                <div class="table-scroll">
                    <table v-responsive-table>
                        <thead>
                            <tr>
                                <th>{{ t.student }}</th>
                                <th>{{ t.age }}</th>
                                <th>{{ t.kyuDan }}</th>
                                <th>{{ t.trainer }}</th>
                                <th>{{ t.club }}</th>
                                <th>{{ t.actions }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="students.length === 0">
                                <td colspan="6" class="empty-cell">{{ t.emptyTeam }}</td>
                            </tr>
                            <tr v-for="student in students" :key="student.id">
                                <td><strong>{{ student.full_name }}</strong></td>
                                <td>{{ student.age ?? '-' }}</td>
                                <td>{{ student.rang ?? '-' }}</td>
                                <td>{{ student.coach_name ?? '-' }}</td>
                                <td>{{ student.club ?? '-' }}</td>
                                <td>
                                    <button
                                        v-if="student.can_detach"
                                        type="button"
                                        class="detach-button"
                                        @click="detachTarget = student"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                        {{ t.detach }}
                                    </button>
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
        </section>

        <ConfirmActionModal v-if="detachTarget" :title="t.detach" :message="detachTarget.full_name" :confirm-label="t.detach" :t="t" :busy="detaching" :error="detachError" @close="detachTarget = null; detachError = ''" @confirm="confirmDetach"/>
        <ExaminationModal
            v-if="showExamModal"
            :error="examError"
            :form="examForm"
            is-editing
            :t="t"
            @close="emit('close-exam-modal')"
            @save="emit('save-exam')"
        />

        <AttachStudentsModal
            v-if="showAttachModal"
            :error="examError"
            :selected-ids="selectedAttachIds"
            :students="attachOptions"
            :t="t"
            @close="emit('close-attach-modal')"
            @save="emit('attach-students')"
            @toggle-student="emit('toggle-attach-student', $event)"
        />
    </template>
</template>
