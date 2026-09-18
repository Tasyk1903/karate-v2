<script setup>
import { computed, ref, watch } from 'vue';
import PaginationBar from '../../components/panel/PaginationBar.vue';

const props = defineProps({
    detail: {
        type: Object,
        required: true,
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
    filters: {
        type: Object,
        required: true,
    },
    meta: {
        type: Object,
        required: true,
    },
    displayedPages: {
        type: Array,
        required: true,
    },
    t: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['change-page', 'change-per-page', 'detach-student', 'open-student', 'set-tournament']);

const avatarFailed = ref(false);
const trainer = computed(() => props.detail?.trainer ?? {});
const students = computed(() => Array.isArray(props.detail?.students) ? props.detail.students : (props.detail?.students?.data ?? []));
const tournaments = computed(() => props.detail?.filters?.tournaments ?? []);
const exportQuery = computed(() => {
    const params = new URLSearchParams();

    if (props.filters.tournament_id) {
        params.set('tournament_id', props.filters.tournament_id);
    }

    return params.toString();
});
const exportBaseUrl = computed(() => `/api/panel/team/trainers/${trainer.value.id}/students/export`);

const ageIconUrl = '/assets/panel/icons/age.png';
const birthdayIconUrl = '/assets/panel/icons/birthday.png';
const clubIconUrl = '/assets/panel/icons/club.png';
const genderIconUrl = '/assets/panel/icons/gender.png';
const kyuDanIconUrl = '/assets/panel/icons/kyu_dan.png';
const weightIconUrl = '/assets/panel/icons/weight.png';

function initials(value) {
    return `${value.last_name?.[0] ?? ''}${value.first_name?.[0] ?? ''}` || 'KR';
}

function formatWithUnit(value, unit) {
    return value ? `${value} ${unit}` : '-';
}

function genderLabel(value) {
    if (['m', 'male', 'Мужской'].includes(value)) return props.t.male;
    if (['f', 'female', 'Женский'].includes(value)) return props.t.female;

    return '-';
}

function formatAge(row) {
    if (! row.age) return '-';

    return typeof row.age === 'string' && row.age.includes(' ')
        ? row.age
        : `${row.age} ${props.t.yearsShort}`;
}

watch(trainer, () => {
    avatarFailed.value = false;
});
</script>

<template>
    <section v-if="isLoading" class="student-detail-loading">{{ t.loading }}</section>

    <section v-else class="student-detail trainer-detail">
        <article class="student-hero-card">
            <div class="student-photo-panel">
                <img
                    v-if="trainer.avatar && !avatarFailed"
                    :src="trainer.avatar"
                    :alt="trainer.full_name"
                    @error="avatarFailed = true"
                >
                <span v-else>{{ initials(trainer) }}</span>
            </div>

            <div class="student-profile-main">
                <h1>{{ trainer.full_name }}</h1>
                <small v-if="trainer.is_external">{{ t.formExternal }}</small>

                <div class="student-profile-lines">
                    <span>
                        <img class="student-line-icon" :src="clubIconUrl" :alt="t.club">
                        {{ t.club }}:
                        <b>{{ trainer.club ?? '-' }}</b>
                    </span>
                </div>

                <div class="student-belt" :style="{ '--belt-main': trainer.belt?.color, '--belt-accent': trainer.belt?.accent }">
                    <i></i>
                    <div>
                        <strong>{{ trainer.rang ?? '-' }}</strong>
                        <span>{{ t[trainer.belt?.label_key] ?? t.beltNotSet }}</span>
                    </div>
                </div>
            </div>

            <div class="student-profile-stats">
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="ageIconUrl" :alt="t.age">
                    <b>{{ trainer.age_label ?? '-' }}</b>
                    <span>{{ t.age }}</span>
                </div>
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="genderIconUrl" :alt="t.gender">
                    <b>{{ genderLabel(trainer.gender) }}</b>
                    <span>{{ t.gender }}</span>
                </div>
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="birthdayIconUrl" :alt="t.birthday">
                    <b>{{ trainer.birthday ?? '-' }}</b>
                    <span>{{ t.birthday }}</span>
                </div>
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="weightIconUrl" :alt="t.weight">
                    <b>{{ formatWithUnit(trainer.weight, t.kg) }}</b>
                    <span>{{ t.weight }}</span>
                </div>
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="kyuDanIconUrl" :alt="t.kyuDan">
                    <b>{{ trainer.rang ?? '-' }}</b>
                    <span>{{ t.kyuDan }}</span>
                </div>
                <div class="student-info-chip">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 8h16"/><path d="M8 12h8"/><path d="M8 16h5"/></svg>
                    <b>{{ trainer.email ?? '-' }}</b>
                    <span>{{ t.email }}</span>
                </div>
            </div>
        </article>

        <article class="student-section-card team-card trainer-students-card">
            <header class="trainer-students-header">
                <h2>{{ t.students }}</h2>
            </header>

            <div class="trainer-students-tools">
                <label class="modal-field trainer-tournament-filter">
                    <span>{{ t.tournamentSingular }}</span>
                    <select :value="filters.tournament_id" @change="emit('set-tournament', $event.target.value)">
                        <option value="">{{ t.allTournaments }}</option>
                        <option v-for="tournament in tournaments" :key="tournament.id" :value="tournament.id">
                            {{ tournament.name }}
                        </option>
                    </select>
                </label>

                <div class="trainer-export-actions">
                    <a
                        class="file-action-button excel-file"
                        :href="`${exportBaseUrl}?format=xlsx${exportQuery ? `&${exportQuery}` : ''}`"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="m8 16 3-4-3-4"/><path d="m13 8-3 4 3 4"/></svg>
                        Excel
                    </a>
                    <a
                        class="file-action-button pdf-file"
                        :href="`${exportBaseUrl}?format=pdf${exportQuery ? `&${exportQuery}` : ''}`"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="M8 16h1.5a1.5 1.5 0 0 0 0-3H8v5"/><path d="M13 13v5"/><path d="M16 13h2"/><path d="M16 16h1.5"/></svg>
                        PDF
                    </a>
                </div>
            </div>

            <div class="table-scroll team-table trainer-students-table">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>{{ t.photo }}</th>
                            <th>{{ t.fio }}</th>
                            <th>{{ t.age }}</th>
                            <th>{{ t.weight }}</th>
                            <th>{{ t.kyuDan }}</th>
                            <th>{{ t.actions }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="students.length === 0">
                            <td colspan="6" class="empty-cell">{{ t.emptyTeam }}</td>
                        </tr>
                        <tr v-for="student in students" :key="student.id">
                            <td class="team-avatar-cell">
                                <span class="team-avatar">
                                    <img v-if="student.avatar" :src="student.avatar" alt="">
                                    <b v-else>{{ initials(student) }}</b>
                                </span>
                            </td>
                            <td><strong>{{ student.full_name }}</strong></td>
                            <td>{{ formatAge(student) }}</td>
                            <td>{{ student.weight ? `${student.weight} ${t.kg}` : '-' }}</td>
                            <td><span class="rank-badge">{{ student.rang ?? '-' }}</span></td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" :title="t.view" @click="emit('open-student', student)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                    <button v-if="detail.capabilities?.detach_students" type="button" class="danger" :title="t.detach" @click="emit('detach-student', student)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <PaginationBar
                v-if="meta.total > meta.per_page"
                :meta="meta"
                :of="t.of"
                :pages="displayedPages"
                :rows-per-page="t.rowsPerPage"
                :rows-shown="t.rowsShown"
                @change-page="emit('change-page', $event)"
                @change-per-page="emit('change-per-page', $event)"
            />
        </article>
    </section>
</template>
