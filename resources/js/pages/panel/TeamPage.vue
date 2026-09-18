<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import { Copy, Trash2 } from '@lucide/vue';
import ConfirmActionModal from '../../components/panel/ConfirmActionModal.vue';
import TeamMemberModal from '../../components/panel/TeamMemberModal.vue';
import PaginationBar from '../../components/panel/PaginationBar.vue';
import TrainerInviteModal from '../../components/panel/TrainerInviteModal.vue';

const props = defineProps({
    deleteMembers: { type: Function, required: true },
    allowedSections: { type: Array, default: () => [] },
    activeSection: {
        type: String,
        required: true,
    },
    displayedPages: {
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
    rows: {
        type: Array,
        required: true,
    },
    search: {
        type: String,
        default: '',
    },
    sort: {
        type: String,
        default: 'name',
    },
    stats: {
        type: Object,
        required: true,
    },
    editingTeamMember: {
        type: Object,
        default: null,
    },
    showTeamMemberModal: {
        type: Boolean,
        default: false,
    },
    showTrainerInviteModal: {
        type: Boolean,
        default: false,
    },
    teamMemberError: {
        type: String,
        default: '',
    },
    teamMemberForm: {
        type: Object,
        required: true,
    },
    trainerInviteError: {
        type: String,
        default: '',
    },
    trainerInviteForm: {
        type: Object,
        required: true,
    },
    isSendingTrainerInvite: {
        type: Boolean,
        default: false,
    },
    t: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits([
    'add-trainer',
    'change-page',
    'change-per-page',
    'close-team-member-modal',
    'close-trainer-invite-modal',
    'delete-trainer',
    'export',
    'open-team-member-modal',
    'open-student',
    'open-trainer',
    'delete-pending-invitation',
    'resend-pending-invitation',
    'send-trainer-invite',
    'save-team-member',
    'set-search',
    'set-section',
    'set-sort',
]);

const trainerToDelete = ref(null);
const organizationCode = ref('');
const codeError = ref('');
const codeCopied = ref(false);
const selected = ref([]);
const deletingIds = ref(null);
const deletionBusy = ref(false);
const deletionError = ref('');
const privilegedSection = computed(() => ['judges', 'secretaries'].includes(props.activeSection) && props.allowedSections.includes(props.activeSection));
const allSelected = computed(() => props.rows.length > 0 && props.rows.every(row => selected.value.includes(row.id)));
let pendingTimer;
function refreshPending() {
    if (props.activeSection === 'pending' && document.visibilityState === 'visible' && !props.isLoading && !props.showTrainerInviteModal && !pendingInvitationToDelete.value) {
        emit('change-page', props.meta.current_page);
    }
}
onMounted(() => { pendingTimer = window.setInterval(refreshPending, 30000); window.addEventListener('focus', refreshPending); });
onUnmounted(() => { window.clearInterval(pendingTimer); window.removeEventListener('focus', refreshPending); });
watch(() => [props.activeSection, props.rows], () => { selected.value = []; });
onMounted(async () => {
    try {
        const response = await fetch('/api/panel/team/invitation-code', { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(props.t.actionFailed);
        organizationCode.value = (await response.json()).code;
    } catch (failure) { codeError.value = failure.message; }
});
async function copyCode() {
    try { await navigator.clipboard.writeText(organizationCode.value); codeCopied.value = true; }
    catch { codeError.value = props.t.actionFailed; }
}
function requestDeletion(ids) { deletionError.value = ''; deletingIds.value = [...ids]; }
async function confirmMemberDeletion() {
    if (deletionBusy.value) return;
    deletionBusy.value = true;
    try { await props.deleteMembers(deletingIds.value); deletingIds.value = null; selected.value = []; }
    catch (failure) { deletionError.value = failure.message || props.t.actionFailed; }
    finally { deletionBusy.value = false; }
}
const pendingInvitationToDelete = ref(null);

function openDeleteTrainerModal(row) {
    trainerToDelete.value = row;
}

function closeDeleteTrainerModal() {
    trainerToDelete.value = null;
}

function confirmDeleteTrainer() {
    if (! trainerToDelete.value) return;

    emit('delete-trainer', trainerToDelete.value);
    closeDeleteTrainerModal();
}

function openDeletePendingInvitationModal(row) {
    pendingInvitationToDelete.value = row;
}

function closeDeletePendingInvitationModal() {
    pendingInvitationToDelete.value = null;
}

function confirmDeletePendingInvitation() {
    if (! pendingInvitationToDelete.value) return;

    emit('delete-pending-invitation', pendingInvitationToDelete.value);
    closeDeletePendingInvitationModal();
}

const tabs = computed(() => [
    { key: 'judges', stat: 'judges', icon: 'scale' },
    { key: 'secretaries', stat: 'secretaries', icon: 'user-check' },
    { key: 'trainers', stat: 'trainers', icon: 'users' },
    { key: 'students', stat: 'students', icon: 'graduation' },
    { key: 'pending', stat: 'pending', icon: 'clock' },
].filter((tab) => props.allowedSections.includes(tab.key)));

function initials(row) {
    return `${row.last_name?.[0] ?? ''}${row.first_name?.[0] ?? ''}` || 'KR';
}

function formatAge(row, t) {
    if (! row.age) return '-';

    return typeof row.age === 'string' && row.age.includes(' ')
        ? row.age
        : `${row.age} ${t.yearsShort}`;
}
</script>

<template>
    <section class="team-heading">
        <div>
            <h1>{{ t.team }}</h1>
            <p>{{ t.teamLead }}</p>
        </div>
        <button
            v-if="activeSection === 'trainers'"
            type="button"
            class="create-button team-add-button"
            @click="emit('add-trainer')"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            {{ t.addTrainer }}
        </button>
        <button
            v-else-if="activeSection === 'judges' || activeSection === 'secretaries'"
            type="button"
            class="create-button team-add-button"
            @click="emit('open-team-member-modal')"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            {{ activeSection === 'judges' ? t.createJudge : t.createSecretary }}
        </button>
    </section>

    <div class="organization-code-row">
        <span>{{ t.organizationCode }}</span>
        <code>{{ organizationCode || '-' }}</code>
        <button type="button" class="panel-icon-button" :disabled="!organizationCode" :title="codeCopied ? t.codeCopied : t.copyCode" @click="copyCode"><Copy :size="18" /></button>
        <span v-if="codeError" role="alert" class="form-error">{{ codeError }}</span>
    </div>

    <section class="team-stats">
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            class="team-stat-card"
            :class="{ active: activeSection === tab.key }"
            @click="emit('set-section', tab.key)"
        >
            <i :data-icon="tab.icon">
                <svg v-if="tab.icon === 'scale'" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18"/><path d="M5 7h14"/><path d="M6 7l-3 7h6L6 7z"/><path d="M18 7l-3 7h6l-3-7z"/></svg>
                <svg v-else-if="tab.icon === 'user-check'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.8 19c.7-3 2.8-4.7 5.2-4.7"/><path d="M15 14l2 2 4-5"/></svg>
                <svg v-else-if="tab.icon === 'users'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.8 19.5c.7-3.2 2.8-5.1 5.2-5.1s4.5 1.9 5.2 5.1"/><circle cx="17.2" cy="9.7" r="2.4"/><path d="M14.8 16.1c.7-.8 1.5-1.2 2.5-1.2 1.6 0 2.9 1.1 3.4 3"/></svg>
                <svg v-else-if="tab.icon === 'graduation'" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8l9-4 9 4-9 4-9-4z"/><path d="M7 10.5v5c2.8 1.7 7.2 1.7 10 0v-5"/></svg>
                <svg v-else viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/></svg>
            </i>
            <strong>{{ stats[tab.stat] ?? 0 }}</strong>
            <span>{{ t[tab.key] }}</span>
        </button>
    </section>

    <section class="team-card" :class="{ loading: isLoading }">
        <nav v-responsive-tabs class="team-tabs">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                :class="{ active: activeSection === tab.key }"
                @click="emit('set-section', tab.key)"
            >
                {{ t[tab.key] }}
            </button>
        </nav>

        <div class="team-tools">
            <button v-if="privilegedSection && selected.length" type="button" class="panel-icon-button danger" :title="t.deleteSelected" @click="requestDeletion(selected)"><Trash2 :size="18" /></button>
            <label class="table-search compact-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.8-3.8"/></svg>
                <input :value="search" :placeholder="t.search" @input="emit('set-search', $event.target.value)">
            </label>
            <select class="team-sort" :value="sort" @change="emit('set-sort', $event.target.value)">
                <option value="name">{{ t.sortName }}</option>
                <option v-if="activeSection === 'trainers' || activeSection === 'students'" value="age">{{ t.sortAge }}</option>
                <option v-if="activeSection === 'trainers' || activeSection === 'students'" value="weight">{{ t.sortWeight }}</option>
                <option v-if="activeSection === 'trainers' || activeSection === 'students'" value="club">{{ t.sortClub }}</option>
            </select>
            <button type="button" class="soft-button export-button" @click="emit('export')">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="M8 11l4 4 4-4"/><path d="M5 21h14"/></svg>
                {{ t.export }}
            </button>
        </div>

        <div class="table-scroll team-table" :class="`team-table-${activeSection}`">
            <table v-responsive-table>
                <thead>
                    <tr v-if="activeSection === 'judges'">
                        <th class="team-select-cell"><input type="checkbox" :aria-label="t.selectAll" :checked="allSelected" @change="selected = $event.target.checked ? rows.map(row => row.id) : []"></th>
                        <th>{{ t.fio }}</th>
                        <th>Email</th>
                        <th>{{ t.scoreColumn }}</th>
                        <th>{{ t.actions }}</th>
                    </tr>
                    <tr v-else-if="activeSection === 'secretaries'">
                        <th class="team-select-cell"><input type="checkbox" :aria-label="t.selectAll" :checked="allSelected" @change="selected = $event.target.checked ? rows.map(row => row.id) : []"></th>
                        <th>{{ t.fio }}</th>
                        <th>{{ t.actions }}</th>
                    </tr>
                    <tr v-else-if="activeSection === 'pending'">
                        <th>Email</th>
                        <th>{{ t.date }}</th>
                        <th>{{ t.actions }}</th>
                    </tr>
                    <tr v-else-if="activeSection === 'trainers'">
                        <th>{{ t.photo }}</th>
                        <th>{{ t.fio }}</th>
                        <th>{{ t.age }}</th>
                        <th>{{ t.weight }}</th>
                        <th>{{ t.kyuDan }}</th>
                        <th>{{ t.club }}</th>
                        <th>{{ t.actions }}</th>
                    </tr>
                    <tr v-else>
                        <th>{{ t.photo }}</th>
                        <th>{{ t.fio }}</th>
                        <th>{{ t.age }}</th>
                        <th>{{ t.weight }}</th>
                        <th>{{ t.kyuDan }}</th>
                        <th>{{ t.trainer }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td class="empty-cell" :colspan="activeSection === 'secretaries' ? 3 : activeSection === 'judges' ? 5 : activeSection === 'pending' ? 3 : activeSection === 'students' ? 6 : 7">
                            {{ t.emptyTeam }}
                        </td>
                    </tr>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        :class="{ 'is-clickable-row': activeSection === 'students' || activeSection === 'trainers' }"
                        @click="activeSection === 'students' ? emit('open-student', row) : activeSection === 'trainers' && emit('open-trainer', row)"
                    >
                        <template v-if="activeSection === 'judges'">
                            <td class="team-select-cell"><input v-model="selected" type="checkbox" :value="row.id" :aria-label="`${t.selectMember}: ${row.full_name}`"></td>
                            <td><strong>{{ row.full_name }}</strong></td>
                            <td>{{ row.email }}</td>
                            <td><span class="score-badge">{{ t[row.judge_position] ?? row.judge_position_label }}</span></td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="danger" :title="t.delete" @click="requestDeletion([row.id])"><Trash2 :size="18" /></button>
                                    <button type="button" :title="t.edit" @click="emit('open-team-member-modal', row)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                                    </button>
                                </div>
                            </td>
                        </template>
                        <template v-else-if="activeSection === 'secretaries'">
                            <td class="team-select-cell"><input v-model="selected" type="checkbox" :value="row.id" :aria-label="`${t.selectMember}: ${row.full_name}`"></td>
                            <td><strong>{{ row.full_name }}</strong></td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="danger" :title="t.delete" @click="requestDeletion([row.id])"><Trash2 :size="18" /></button>
                                    <button type="button" :title="t.edit" @click="emit('open-team-member-modal', row)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                                    </button>
                                </div>
                            </td>
                        </template>
                        <template v-else-if="activeSection === 'pending'">
                            <td><strong>{{ row.email }}</strong></td>
                            <td>{{ row.created_at ?? '-' }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" :title="t.resendInvitation" @click="emit('resend-pending-invitation', row)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/><path d="M3 12A9 9 0 0 1 18 5.3L21 8"/><path d="M21 3v5h-5"/></svg>
                                    </button>
                                    <button type="button" class="danger" :title="t.delete" @click="openDeletePendingInvitationModal(row)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </template>
                        <template v-else-if="activeSection === 'trainers'">
                            <td class="team-avatar-cell">
                                <span class="team-avatar">
                                    <img v-if="row.avatar" :src="row.avatar" alt="">
                                    <b v-else>{{ initials(row) }}</b>
                                </span>
                            </td>
                            <td><strong>{{ row.full_name }}</strong><small v-if="row.is_external" class="external-team-label">{{ t.formExternal }}</small></td>
                            <td>{{ formatAge(row, t) }}</td>
                            <td>{{ row.weight ? `${row.weight} ${t.kg}` : '-' }}</td>
                            <td><span class="rank-badge">{{ row.rang ?? '-' }}</span></td>
                            <td>{{ row.club ?? '-' }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" :title="t.view" @click.stop="emit('open-trainer', row)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3"/></svg></button>
                                    <button type="button" class="danger" :title="t.delete" @click.stop="openDeleteTrainerModal(row)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg></button>
                                </div>
                            </td>
                        </template>
                        <template v-else>
                            <td class="team-avatar-cell">
                                <span class="team-avatar">
                                    <img v-if="row.avatar" :src="row.avatar" alt="">
                                    <b v-else>{{ initials(row) }}</b>
                                </span>
                            </td>
                            <td><strong>{{ row.full_name }}</strong></td>
                            <td>{{ formatAge(row, t) }}</td>
                            <td>{{ row.weight ? `${row.weight} ${t.kg}` : '-' }}</td>
                            <td><span class="rank-badge">{{ row.rang ?? '-' }}</span></td>
                            <td>{{ row.coach_name ?? '-' }}</td>
                        </template>
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

    <TeamMemberModal
        v-if="showTeamMemberModal"
        :error="teamMemberError"
        :form="teamMemberForm"
        :is-editing="Boolean(editingTeamMember)"
        :section="activeSection"
        :t="t"
        @close="emit('close-team-member-modal')"
        @save="emit('save-team-member')"
        @delete="requestDeletion([editingTeamMember.id])"
    />

    <TrainerInviteModal
        v-if="showTrainerInviteModal"
        :error="trainerInviteError"
        :form="trainerInviteForm"
        :is-submitting="isSendingTrainerInvite"
        :organization-code="organizationCode"
        :t="t"
        @close="emit('close-trainer-invite-modal')"
        @send="emit('send-trainer-invite')"
    />

    <ConfirmActionModal v-if="deletingIds" :title="t.deleteTeamMembers" :message="t.deleteTeamMembersConfirm" :busy="deletionBusy" :error="deletionError" :t="t" @close="deletingIds = null" @confirm="confirmMemberDeletion" />

    <div v-if="trainerToDelete" class="modal-backdrop">
        <form class="template-modal confirm-modal" @submit.prevent="confirmDeleteTrainer">
            <header>
                <h2>{{ t.deleteTrainer }}</h2>
                <button type="button" class="panel-icon-button" @click="closeDeleteTrainerModal">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>
            <p>{{ t.deleteTrainerConfirm }}</p>
            <footer>
                <button type="button" class="soft-button" @click="closeDeleteTrainerModal">{{ t.cancel }}</button>
                <button type="submit" class="danger-button">{{ t.delete }}</button>
            </footer>
        </form>
    </div>

    <div v-if="pendingInvitationToDelete" class="modal-backdrop">
        <form class="template-modal confirm-modal" @submit.prevent="confirmDeletePendingInvitation">
            <header>
                <h2>{{ t.deleteInvitation }}</h2>
                <button type="button" class="panel-icon-button" @click="closeDeletePendingInvitationModal">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>
            <p>{{ t.deleteInvitationConfirm }}</p>
            <footer>
                <button type="button" class="soft-button" @click="closeDeletePendingInvitationModal">{{ t.cancel }}</button>
                <button type="submit" class="danger-button">{{ t.delete }}</button>
            </footer>
        </form>
    </div>
</template>

<style scoped>
.organization-code-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 8px 0 16px; font-size: 13px; }
.organization-code-row code { user-select: all; }
.team-select-cell { width: 36px !important; min-width: 36px !important; }
.team-select-cell input { width: 16px; height: 16px; }
</style>
