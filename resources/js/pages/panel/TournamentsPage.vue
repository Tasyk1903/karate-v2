<script setup>
import { computed, ref, watch } from 'vue';
import FileDropzone from '../../components/panel/FileDropzone.vue';
import ChampionshipEditor from '../../components/panel/ChampionshipEditor.vue';
import TournamentDeleteButton from '../../components/panel/TournamentDeleteButton.vue';
import TournamentBulkAction from '../../components/panel/TournamentBulkAction.vue';
import { Pencil } from '@lucide/vue';
const editingChampionship = ref(null);
import PaginationBar from '../../components/panel/PaginationBar.vue';

const props = defineProps({
    locale: { type: String, default: 'ru' },
    canUseApplications: { type: Boolean, default: false },
    championship: {
        type: Object,
        default: null,
    },
    championshipError: {
        type: String,
        default: '',
    },
    championshipForm: {
        type: Object,
        required: true,
    },
    displayedPages: {
        type: Array,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    formError: {
        type: String,
        default: '',
    },
    formModel: {
        type: Object,
        required: true,
    },
    forms: {
        type: Array,
        default: () => [],
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
    isDetail: {
        type: Boolean,
        default: false,
    },
    isTournamentDetail: {
        type: Boolean,
        default: false,
    },
    meta: {
        type: Object,
        required: true,
    },
    regions: {
        type: Array,
        required: true,
    },
    showFormModal: {
        type: Boolean,
        default: false,
    },
    showTournamentModal: {
        type: Boolean,
        default: false,
    },
    showChampionshipModal: {
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
    tournament: {
        type: Object,
        default: null,
    },
    tournaments: {
        type: Array,
        required: true,
    },
    tournamentError: {
        type: String,
        default: '',
    },
    tournamentForm: {
        type: Object,
        required: true,
    },
    tournamentOptions: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits([
    'reload',
    'change-page',
    'change-per-page',
    'close-championship-modal',
    'close-form-modal',
    'close-tournament-modal',
    'create-championship',
    'create-form',
    'create-tournament',
    'delete-championship',
    'delete-form',
    'import-form',
    'open-championship-modal',
    'open-form-modal',
    'open-tournament-modal',
    'open-championship',
    'open-tournament-edit',
    'open-tournament',
    'set-form-status',
    'set-status',
]);

const statCards = [
    { key: 'total', label: 'totalTournaments', icon: 'cup' },
    { key: 'active', label: 'activeTournaments', icon: 'play' },
    { key: 'completed', label: 'completedTournaments', icon: 'check' },
    { key: 'this_month', label: 'thisMonth', icon: 'calendar' },
];

const activeDetailTab = ref('tournaments');
const expandedClubRows = ref([]);
const showDeleteChampionshipModal = ref(false);
const showParticipantExportModal = ref(false);
const participantExportCoachIds = ref([]);
const pendingTeamFormAction = ref(null);

const participantExportUrl = computed(() => {
    if (!props.championship?.id) return '';

    const params = new URLSearchParams();
    participantExportCoachIds.value.forEach((id) => params.append('trainer_ids[]', id));
    const query = params.toString();

    return `/api/panel/tournaments/${props.championship.id}/export${query ? `?${query}` : ''}`;
});

watch(
    [() => props.isDetail, () => props.championship?.id],
    () => {
        activeDetailTab.value = 'tournaments';
    },
);

function initials(name) {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase();
}

function visibleClubs(row, key) {
    if (expandedClubRows.value.includes(key)) {
        return row.clubs;
    }

    return row.clubs.slice(0, 3);
}

function toggleClubs(row, key) {
    if (row.clubs.length <= 3) return;

    expandedClubRows.value = expandedClubRows.value.includes(key)
        ? expandedClubRows.value.filter((id) => id !== key)
        : [...expandedClubRows.value, key];
}

function categoryLabel(category) {
    return props.t[`teamFormCategory_${category}`] ?? category;
}

async function copyLink(url) {
    await navigator.clipboard.writeText(url);
}

function askTeamFormAction(action, form) {
    pendingTeamFormAction.value = { action, form };
}

function closeTeamFormAction() {
    pendingTeamFormAction.value = null;
}

function confirmTeamFormAction() {
    if (!pendingTeamFormAction.value) return;

    const { action, form } = pendingTeamFormAction.value;
    pendingTeamFormAction.value = null;

    if (action === 'delete') emit('delete-form', form);
    if (action === 'import') emit('import-form', form);
    if (action === 'close') emit('set-form-status', { form, status: 'closed' });
    if (action === 'open') emit('set-form-status', { form, status: 'open' });
}

function openParticipantExportModal() {
    participantExportCoachIds.value = [];
    showParticipantExportModal.value = true;
}

function downloadParticipants() {
    if (!participantExportUrl.value) return;

    window.open(participantExportUrl.value, '_blank', 'noopener');
    showParticipantExportModal.value = false;
}
</script>

<template>
    <ChampionshipEditor v-if="editingChampionship" :championship="editingChampionship" :t="t" @close="editingChampionship = null" @done="editingChampionship = null; emit('reload')" />
    <section v-if="isTournamentDetail" class="tournament-placeholder-card">
        <header>
            <span class="tournament-status" :data-status="tournament?.status">{{ t[tournament?.status] }}</span>
            <h1>{{ tournament?.name }}</h1>
        </header>
        <dl>
            <div>
                <dt>{{ t.commissionDate }}</dt>
                <dd>{{ tournament?.date_commission_label || '-' }}</dd>
            </div>
            <div>
                <dt>{{ t.tournamentDate }}</dt>
                <dd>{{ tournament?.date_label || '-' }}</dd>
            </div>
            <div>
                <dt>{{ t.finishDate }}</dt>
                <dd>{{ tournament?.date_finish_label || '-' }}</dd>
            </div>
            <div>
                <dt>{{ t.region }}</dt>
                <dd>{{ tournament?.region || '-' }}</dd>
            </div>
            <div>
                <dt>{{ t.scale }}</dt>
                <dd>{{ tournament?.scale || '-' }}</dd>
            </div>
            <div>
                <dt>{{ t.address }}</dt>
                <dd>{{ tournament?.address || '-' }}</dd>
            </div>
        </dl>
    </section>

    <section v-else-if="isDetail" class="championship-detail-hero">
        <div>
            <h1>{{ t.tournamentSingular }} — {{ championship?.name }}</h1>
            <span class="tournament-status" :data-status="championship?.status">
                {{ championship?.status === 'active' ? t.published : t.completed }}
            </span>
        </div>
        <div class="championship-detail-actions">
            <button v-if="championship?.can_manage" type="button" class="panel-icon-button" :title="t.tourEditChampionship" :aria-label="t.tourEditChampionship" @click="editingChampionship = championship"><Pencil :size="18" /></button>
            <TournamentBulkAction v-if="activeDetailTab === 'forms' && championship?.can_delete_forms" :items="forms.map(form => ({ id: form.id, name: form.organization_name }))" :endpoint="`/api/panel/tournaments/${championship.id}/forms/bulk-delete`" :label="t.tourDeleteForms" :confirm="t.tourDeleteFormsConfirm" :t="t" :locale="locale" @done="emit('reload')" />
            <button
                v-if="activeDetailTab === 'tournaments' && championship?.can_manage"
                type="button"
                class="create-button championship-form-button"
                @click="emit('open-tournament-modal')"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                {{ t.createTournament }}
            </button>
            <button
                v-if="activeDetailTab === 'tournaments' && championship?.can_manage"
                type="button"
                class="soft-button championship-form-button participant-export-button"
                @click="openParticipantExportModal"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                {{ t.downloadParticipantList }}
            </button>
            <button
                v-if="activeDetailTab === 'forms'"
                type="button"
                class="create-button championship-form-button"
                @click="emit('open-form-modal')"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                {{ t.createTeamForm }}
            </button>
            <button
                v-if="championship?.can_manage"
                type="button"
                class="danger-button"
                :title="t.deleteChampionship"
                @click="showDeleteChampionshipModal = true"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 14h10l1-14"/><path d="M9 7V4h6v3"/></svg>
                {{ t.deleteChampionship }}
            </button>
        </div>
    </section>

    <section v-else class="page-heading tournament-heading">
        <h1>{{ t.tournaments }}</h1>
        <a v-if="canUseApplications" class="soft-button" href="/panel/tournaments/applications">{{ t.tourApplications }}</a>
        <button type="button" class="create-button" @click="emit('open-championship-modal')">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            {{ t.createChampionship }}
        </button>
    </section>

    <section v-if="!isTournamentDetail && isDetail" class="championship-detail-tabs">
        <button type="button" :class="{ active: activeDetailTab === 'tournaments' }" @click="activeDetailTab = 'tournaments'">
            {{ t.tournaments }}
        </button>
        <button type="button" :class="{ active: activeDetailTab === 'forms' }" @click="activeDetailTab = 'forms'">
            {{ t.teamForms }}
        </button>
    </section>

    <section v-else class="tournament-status-tabs" aria-label="Tournament status">
        <button type="button" :class="{ active: filters.status === '' }" @click="emit('set-status', '')">
            {{ t.all }}
        </button>
        <button type="button" :class="{ active: filters.status === 'active' }" @click="emit('set-status', 'active')">
            {{ t.active }}
        </button>
        <button type="button" :class="{ active: filters.status === 'completed' }" @click="emit('set-status', 'completed')">
            {{ t.completedPlural }}
        </button>
    </section>

    <section v-if="!isDetail" class="tournament-filters-card">
        <label>
            <span>{{ t.tournaments }}</span>
            <div class="segmented-control">
                <button type="button" :class="{ active: filters.scope === '' }" @click="filters.scope = ''">
                    {{ t.all }}
                </button>
                <button type="button" :class="{ active: filters.scope === 'mine' }" @click="filters.scope = 'mine'">
                    {{ t.myTournaments }}
                </button>
            </div>
        </label>

        <label>
            <span>{{ t.region }}</span>
            <select v-model="filters.region_id">
                <option value="">{{ t.allRegions }}</option>
                <option v-for="region in regions" :key="region.id" :value="region.id">{{ region.name }}</option>
            </select>
        </label>

        <label class="tournament-search">
            <span class="visually-hidden">{{ t.search }}</span>
            <div class="table-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.8-3.8"/></svg>
                <input v-model="filters.search" :placeholder="isDetail ? t.tournamentSearch : t.championshipSearch">
            </div>
        </label>
    </section>

    <section v-if="!isDetail" class="exam-stats tournament-stats">
        <article v-for="card in statCards" :key="card.key" class="exam-stat-card" :data-icon="card.icon">
            <i>
                <svg v-if="card.icon === 'cup'" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8v3a4 4 0 0 1-8 0z"/><path d="M8 5H4v2a4 4 0 0 0 4 4"/><path d="M16 5h4v2a4 4 0 0 1-4 4"/><path d="M12 11v5"/><path d="M9 20h6"/><path d="M10 16h4"/></svg>
                <svg v-else-if="card.icon === 'play'" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                <svg v-else-if="card.icon === 'check'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="m8.5 12.5 2.2 2.2 4.8-5.2"/></svg>
                <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v4M17 3v4"/><rect x="4" y="6" width="16" height="15" rx="3"/><path d="M4 11h16"/></svg>
            </i>
            <strong>{{ stats[card.key] ?? 0 }}</strong>
            <span>{{ t[card.label] }}</span>
        </article>
    </section>

    <section
        v-if="!isTournamentDetail && (!isDetail || activeDetailTab === 'tournaments')"
        :class="[isDetail ? 'championship-items-list' : 'tournament-grid', { loading: isLoading }]"
    >
        <article v-if="tournaments.length === 0" class="tournament-empty">
            {{ t.emptyTournaments }}
        </article>

        <article
            v-for="tournament in tournaments"
            :key="tournament.id"
            :class="[isDetail ? 'championship-item-card' : 'tournament-card', { clickable: true }]"
            @click="isDetail ? emit('open-tournament', tournament) : emit('open-championship', tournament)"
        >
            <div :class="isDetail ? 'championship-item-cover' : 'tournament-cover'">
                <img v-if="tournament.cover || (isDetail && championship?.cover)" :src="tournament.cover || championship?.cover" :alt="tournament.name">
                <div v-else class="tournament-cover-fallback">
                    <span>{{ initials(tournament.name) }}</span>
                    <strong>{{ tournament.name }}</strong>
                </div>
                <span class="tournament-status" :data-status="tournament.status">{{ t[tournament.status] }}</span>
            </div>

            <div v-if="isDetail" class="championship-item-main">
                <header>
                    <h2>{{ tournament.name }}</h2>
                    <span class="tournament-status" :data-status="tournament.status">{{ t[tournament.status] }}</span>
                </header>
                <dl class="team-form-meta-row tournament-detail-meta">
                    <div>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v4M17 3v4"/><rect x="4" y="6" width="16" height="15" rx="3"/><path d="M4 11h16"/></svg>
                        <span>
                            <dt>{{ t.commissionDate }}</dt>
                            <dd>{{ tournament.date_commission_label || '-' }}</dd>
                        </span>
                    </div>
                    <div>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v4M17 3v4"/><rect x="4" y="6" width="16" height="15" rx="3"/><path d="M4 11h16"/></svg>
                        <span>
                            <dt>{{ t.tournamentDate }}</dt>
                            <dd>{{ tournament.date_label || '-' }}</dd>
                        </span>
                    </div>
                    <div>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v4M17 3v4"/><rect x="4" y="6" width="16" height="15" rx="3"/><path d="M4 11h16"/></svg>
                        <span>
                            <dt>{{ t.finishDate }}</dt>
                            <dd>{{ tournament.date_finish_label || '-' }}</dd>
                        </span>
                    </div>
                    <div>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11h8a4 4 0 0 0 0-8H10v18"/><path d="M7 15h8"/></svg>
                        <span>
                            <dt>{{ t.price }}</dt>
                            <dd>{{ tournament.price_label || '-' }}</dd>
                        </span>
                    </div>
                    <div>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-5.4 7-11a7 7 0 0 0-14 0c0 5.6 7 11 7 11z"/><circle cx="12" cy="10" r="2.3"/></svg>
                        <span>
                            <dt>{{ t.region }}</dt>
                            <dd>{{ tournament.region || '-' }}</dd>
                        </span>
                    </div>
                    <div>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8v3a4 4 0 0 1-8 0z"/><path d="M8 5H4v2a4 4 0 0 0 4 4"/><path d="M16 5h4v2a4 4 0 0 1-4 4"/><path d="M12 11v5"/><path d="M9 20h6"/></svg>
                        <span>
                            <dt>{{ t.scale }}</dt>
                            <dd>{{ tournament.scale || '-' }}</dd>
                        </span>
                    </div>
                    <div class="wide">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9h1M9 13h1M9 17h1"/></svg>
                        <span>
                            <dt>{{ t.address }}</dt>
                            <dd>{{ tournament.address || '-' }}</dd>
                        </span>
                    </div>
                </dl>
                <dl class="team-form-bottom-row">
                    <div class="team-form-counter">
                        <dt>{{ t.participants }}</dt>
                        <dd>{{ tournament.students_count }}</dd>
                    </div>
                    <div class="team-form-counter">
                        <dt>{{ t.trainers }}</dt>
                        <dd>{{ tournament.trainers_count }}</dd>
                    </div>
                    <div class="team-form-clubs" :class="{ expandable: tournament.clubs.length > 3 }" @click.stop="toggleClubs(tournament, `tournament-${tournament.id}`)">
                        <dt>{{ t.participatingClubs }}</dt>
                        <dd>
                            <span v-for="club in visibleClubs(tournament, `tournament-${tournament.id}`)" :key="club">{{ club }}</span>
                            <button v-if="tournament.clubs.length > 3" type="button">
                                {{ expandedClubRows.includes(`tournament-${tournament.id}`) ? t.hide : `+${tournament.more_clubs_count}` }}
                            </button>
                            <span v-if="tournament.clubs.length === 0">-</span>
                        </dd>
                    </div>
                </dl>
            </div>

            <div v-else class="tournament-card-body">
                <h2>{{ tournament.name }}</h2>
                <dl>
                    <div>
                        <dt>{{ t.tournaments }}</dt>
                        <dd>{{ tournament.tournaments_count }}</dd>
                    </div>
                    <div>
                        <dt>{{ t.active }}</dt>
                        <dd>{{ tournament.active_tournaments_count }}</dd>
                    </div>
                    <div>
                        <dt>{{ t.completedPlural }}</dt>
                        <dd>{{ tournament.completed_tournaments_count }}</dd>
                    </div>
                </dl>
            </div>

            <div v-if="isDetail" class="championship-item-actions" @click.stop>
                <TournamentDeleteButton v-if="tournament.can_delete" :championship-id="championship.id" :tournament="tournament" :t="t" @done="emit('reload')" />
                <button type="button" :title="t.view" @click.stop="emit('open-tournament', tournament)">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3"/></svg>
                    {{ t.view }}
                </button>
                <button v-if="tournament.can_manage" type="button" :title="t.edit" @click.stop="emit('open-tournament-edit', tournament)">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20H4v-4z"/></svg>
                    {{ t.edit }}
                </button>
            </div>
        </article>
    </section>

    <section v-else-if="!isTournamentDetail" class="team-forms-list">
        <article v-if="forms.length === 0" class="tournament-empty">
            {{ t.emptyTeamForms }}
        </article>

        <article v-for="form in forms" :key="form.id" class="championship-item-card team-form-card is-simple-form">
            <div class="championship-item-cover">
                <img v-if="championship?.cover" :src="championship.cover" :alt="form.organization_name">
                <div v-else class="tournament-cover-fallback">
                    <span>{{ initials(form.organization_name) }}</span>
                    <strong>{{ form.organization_name }}</strong>
                </div>
            </div>
            <div class="championship-item-main team-form-main">
                <header>
                    <h2>{{ t.teamForm }} — {{ form.organization_name }}</h2>
                    <span class="tournament-status" :data-status="form.status === 'open' ? 'active' : 'completed'">
                        {{ form.status === 'open' ? t.active : t.closed }}
                    </span>
                </header>
                <p class="team-form-description">{{ t.teamFormDescription }}</p>
                <dl class="team-form-bottom-row">
                    <div class="team-form-counter">
                        <dt>{{ t.invitedTeams }}</dt>
                        <dd>{{ form.participants_count }}</dd>
                    </div>
                    <div class="team-form-counter">
                        <dt>{{ t.submitted }}</dt>
                        <dd>{{ form.participants_count }}</dd>
                    </div>
                    <div class="team-form-counter">
                        <dt>{{ t.confirmed }}</dt>
                        <dd>{{ form.confirmed_count }}</dd>
                    </div>
                    <div class="team-form-clubs" :class="{ expandable: form.clubs.length > 3 }" @click="toggleClubs(form, `form-${form.id}`)">
                        <dt>{{ t.participatingClubs }}</dt>
                        <dd>
                            <span v-for="club in visibleClubs(form, `form-${form.id}`)" :key="club">{{ club }}</span>
                            <button v-if="form.clubs.length > 3" type="button">
                                {{ expandedClubRows.includes(`form-${form.id}`) ? t.hide : `+${form.more_clubs_count}` }}
                            </button>
                            <span v-if="form.clubs.length === 0">-</span>
                        </dd>
                    </div>
                </dl>
                <a class="soft-button team-form-participants-toggle" :href="form.editor_url">{{ t.formOpenEditor }}</a>
            </div>
            <div class="championship-item-actions team-form-actions">
                <a :href="form.url" target="_blank" rel="noreferrer" :title="t.view">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3"/></svg>
                    {{ t.view }}
                </a>
                <button type="button" :title="t.copyLink" @click="copyLink(form.url)">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 1 0-7l1.2-1.2a5 5 0 0 1 7 7L17 13"/><path d="M14 11a5 5 0 0 1 0 7l-1.2 1.2a5 5 0 0 1-7-7L7 11"/></svg>
                    {{ t.copyLink }}
                </button>
                <button type="button" :title="t.editTeamForm" @click="emit('open-form-modal', form)">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20H4v-4z"/></svg>
                    {{ t.edit }}
                </button>
                <button
                    type="button"
                    :title="form.status === 'open' ? t.closeTeamForm : t.openTeamForm"
                    @click="askTeamFormAction(form.status === 'open' ? 'close' : 'open', form)"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="11" width="16" height="9" rx="2"/><path :d="form.status === 'open' ? 'M8 11V7a4 4 0 0 1 7.5-2' : 'M8 11V7a4 4 0 0 1 8 0v4'"/></svg>
                    {{ form.status === 'open' ? t.closeTeamForm : t.openTeamForm }}
                </button>
                <button v-if="form.status === 'closed'" type="button" :title="t.importTeamForm" @click="askTeamFormAction('import', form)">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                    {{ t.importTeamForm }}
                </button>
                <button v-if="championship?.can_delete_forms" type="button" class="danger-link" :title="t.deleteTeamForm" @click="askTeamFormAction('delete', form)">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg>
                    {{ t.delete }}
                </button>
            </div>
        </article>
    </section>

    <div v-if="showFormModal" class="modal-backdrop">
        <form class="template-modal team-member-modal" @submit.prevent="emit('create-form')">
            <header>
                <h2>{{ formModel.id ? t.editTeamForm : t.createTeamForm }}</h2>
                <button type="button" class="panel-icon-button" @click="emit('close-form-modal')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>
            <p v-if="formError" class="form-error">{{ formError }}</p>
            <label class="modal-field">
                <span>{{ t.teamName }}</span>
                <input v-model="formModel.organization_name" required>
            </label>
            <footer>
                <button type="button" class="soft-button" @click="emit('close-form-modal')">{{ t.cancel }}</button>
                <button type="submit" class="save-button">{{ t.save }}</button>
            </footer>
        </form>
    </div>

    <div v-if="showChampionshipModal" class="modal-backdrop">
        <form class="template-modal team-member-modal" @submit.prevent="emit('create-championship')">
            <header>
                <h2>{{ t.createChampionship }}</h2>
                <button type="button" class="panel-icon-button" @click="emit('close-championship-modal')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>
            <p v-if="championshipError" class="form-error">{{ championshipError }}</p>
            <label class="modal-field full">
                <span>{{ t.championshipNameLabel }}</span>
                <input v-model="championshipForm.name" required :placeholder="t.championshipNamePlaceholder">
            </label>
            <FileDropzone
                v-model="championshipForm.banner"
                class="full"
                accept="image/jpeg,image/png,image/webp"
                :hint="t.dropPosterHint"
                :label="t.poster"
                :placeholder="t.dropPoster"
            />
            <footer>
                <button type="button" class="soft-button" @click="emit('close-championship-modal')">{{ t.cancel }}</button>
                <button type="submit" class="save-button">{{ t.save }}</button>
            </footer>
        </form>
    </div>

    <div v-if="showTournamentModal" class="modal-backdrop">
        <form class="template-modal tournament-create-modal" @submit.prevent="emit('create-tournament')">
            <header>
                <h2>{{ t.createTournament }}</h2>
                <button type="button" class="panel-icon-button" @click="emit('close-tournament-modal')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>
            <p v-if="tournamentError" class="form-error">{{ tournamentError }}</p>

            <section class="modal-section full">
                <h3>{{ t.tournamentData }}</h3>
                <label class="modal-field full">
                    <span>{{ t.tournamentName }}</span>
                    <input v-model="tournamentForm.name" required>
                </label>
                <label class="modal-field">
                    <span>{{ t.region }}</span>
                    <select v-model="tournamentForm.region_id" required>
                        <option value="">{{ t.selectRegion }}</option>
                        <option v-for="region in tournamentOptions.regions" :key="region.id" :value="region.id">{{ region.name }}</option>
                    </select>
                </label>
                <label class="modal-field">
                    <span>{{ t.scale }}</span>
                    <select v-model="tournamentForm.scale_id" required>
                        <option value="">{{ t.selectScale }}</option>
                        <option v-for="scale in tournamentOptions.scales" :key="scale.id" :value="scale.id">{{ scale.name }}</option>
                    </select>
                </label>
                <label class="modal-field">
                    <span>{{ t.ageFrom }}</span>
                    <input v-model="tournamentForm.age_from" type="number" min="0" required>
                </label>
                <label class="modal-field">
                    <span>{{ t.ageTo }}</span>
                    <input v-model="tournamentForm.age_to" type="number" min="0" required>
                </label>
                <label class="modal-field">
                    <span>{{ t.tatamiCount }}</span>
                    <input v-model="tournamentForm.tatami" type="number" min="1" required>
                </label>
                <label class="modal-field">
                    <span>{{ t.participationPrice }}</span>
                    <input v-model="tournamentForm.price" type="number" min="0" required>
                </label>
                <label class="modal-field full">
                    <span>{{ t.address }}</span>
                    <input v-model="tournamentForm.address" required>
                </label>
            </section>

            <section class="modal-section full tournament-type-section">
                <h3>{{ t.tournamentType }}</h3>
                <div class="tournament-type-grid">
                    <button type="button" :class="{ active: tournamentForm.tournament_type === '1' }" @click="tournamentForm.tournament_type = '1'">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8h7"/><path d="M5 12h10"/><path d="M9 16h8"/><path d="m16 7 3 3-3 3"/></svg>
                        {{ t.kumite }}
                    </button>
                    <button type="button" :class="{ active: tournamentForm.tournament_type === '2' }" @click="tournamentForm.tournament_type = '2'">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7l8 4 8-4z"/><path d="m4 12 8 4 8-4"/><path d="m4 17 8 4 8-4"/></svg>
                        {{ t.kata }}
                    </button>
                </div>
                <div v-if="tournamentForm.tournament_type === '2'" class="kata-system-grid">
                    <label>
                        <input v-model="tournamentForm.tournament_type_kata" type="radio" value="1">
                        <span>{{ t.flagSystem }}</span>
                    </label>
                    <label>
                        <input v-model="tournamentForm.tournament_type_kata" type="radio" value="2">
                        <span>{{ t.pointSystem }}</span>
                    </label>
                    <label v-if="tournamentForm.tournament_type_kata === '2'">
                        <input v-model="tournamentForm.is_online_kata" type="checkbox">
                        <span>{{ t.onlineKata }}</span>
                    </label>
                </div>
                <div class="modal-choice-row tournament-rule-options">
                    <label>
                        <input v-model="tournamentForm.KY_up_to_8" type="checkbox">
                        <span>{{ t.kyUpTo8 }}</span>
                    </label>
                    <label>
                        <input v-model="tournamentForm.KY_from_8" type="checkbox">
                        <span>{{ t.kyFrom8 }}</span>
                    </label>
                    <label>
                        <input v-model="tournamentForm.fight_for_third_place" type="checkbox">
                        <span>{{ t.fightForThirdPlace }}</span>
                    </label>
                </div>
            </section>

            <section class="modal-section full three-columns">
                <h3>{{ t.dates }}</h3>
                <label class="modal-field">
                    <span>{{ t.commissionDate }}</span>
                    <input v-model="tournamentForm.date_commission" type="datetime-local" required>
                </label>
                <label class="modal-field">
                    <span>{{ t.tournamentStartDate }}</span>
                    <input v-model="tournamentForm.date" type="date" required>
                </label>
                <label class="modal-field">
                    <span>{{ t.finishDate }}</span>
                    <input v-model="tournamentForm.date_finish" type="date" required>
                </label>
            </section>

            <section class="modal-section full three-columns documents-section">
                <h3>{{ t.documents }}</h3>
                <FileDropzone
                    v-model="tournamentForm.regulation_document"
                    :hint="t.fileDropHint"
                    :label="t.regulationDocument"
                    :placeholder="t.dropFile"
                />
                <FileDropzone
                    v-model="tournamentForm.application_document"
                    :hint="t.fileDropHint"
                    :label="t.applicationDocument"
                    :placeholder="t.dropFile"
                />
                <FileDropzone
                    v-model="tournamentForm.logo_report"
                    accept="image/jpeg,image/png,image/webp"
                    :hint="t.dropPosterHint"
                    :label="t.reportLogo"
                    :placeholder="t.dropFile"
                />
            </section>

            <section class="modal-section full">
                <h3>{{ t.reportData }}</h3>
                <label class="modal-field">
                    <span>{{ t.chiefJudge }}</span>
                    <input v-model="tournamentForm.chief_judge">
                </label>
                <label class="modal-field">
                    <span>{{ t.chiefSecretary }}</span>
                    <input v-model="tournamentForm.chief_secretary">
                </label>
            </section>

            <footer>
                <button type="button" class="soft-button" @click="emit('close-tournament-modal')">{{ t.cancel }}</button>
                <button type="submit" class="save-button">{{ t.save }}</button>
            </footer>
        </form>
    </div>

    <div v-if="showParticipantExportModal" class="modal-backdrop">
        <form class="template-modal team-member-modal participant-export-modal" @submit.prevent="downloadParticipants">
            <header>
                <h2>{{ t.downloadParticipantList }}</h2>
                <button type="button" class="panel-icon-button" @click="showParticipantExportModal = false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>

            <div class="participant-export-summary">
                <span>{{ t.selected }}: {{ participantExportCoachIds.length }}</span>
                <button v-if="participantExportCoachIds.length" type="button" class="soft-button" @click="participantExportCoachIds = []">
                    {{ t.clearAll }}
                </button>
            </div>

            <div class="modal-options-list">
                <label v-for="coach in championship?.export_coaches || []" :key="coach.id" class="modal-check-row">
                    <input v-model="participantExportCoachIds" type="checkbox" :value="coach.id">
                    <span>
                        {{ coach.name }}
                        <small>{{ coach.club || '-' }}</small>
                    </span>
                </label>
                <p v-if="!(championship?.export_coaches || []).length" class="empty-cell">{{ t.emptyTeam }}</p>
            </div>

            <footer>
                <button type="button" class="soft-button" @click="showParticipantExportModal = false">{{ t.cancel }}</button>
                <button type="submit" class="save-button">{{ t.download }}</button>
            </footer>
        </form>
    </div>

    <div v-if="showDeleteChampionshipModal" class="modal-backdrop">
        <form
            class="template-modal confirm-modal"
            @submit.prevent="showDeleteChampionshipModal = false; emit('delete-championship')"
        >
            <header>
                <h2>{{ t.deleteChampionship }}</h2>
                <button type="button" class="panel-icon-button" @click="showDeleteChampionshipModal = false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>
            <p>{{ t.deleteChampionshipConfirm }}</p>
            <footer>
                <button type="button" class="soft-button" @click="showDeleteChampionshipModal = false">{{ t.cancel }}</button>
                <button type="submit" class="danger-button">{{ t.delete }}</button>
            </footer>
        </form>
    </div>

    <div v-if="pendingTeamFormAction" class="modal-backdrop">
        <form class="template-modal confirm-modal" @submit.prevent="confirmTeamFormAction">
            <header>
                <h2>
                    {{
                        pendingTeamFormAction.action === 'delete'
                            ? t.deleteTeamForm
                            : pendingTeamFormAction.action === 'import'
                                ? t.importTeamForm
                                : pendingTeamFormAction.action === 'close'
                                    ? t.closeTeamForm
                                    : t.openTeamForm
                    }}
                </h2>
                <button type="button" class="panel-icon-button" @click="closeTeamFormAction">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>
            <p>
                {{
                    pendingTeamFormAction.action === 'delete'
                        ? t.deleteTeamFormConfirm
                        : pendingTeamFormAction.action === 'import'
                            ? t.importTeamFormConfirm
                            : pendingTeamFormAction.action === 'close'
                                ? t.closeTeamForm
                                : t.openTeamForm
                }}
            </p>
            <footer>
                <button type="button" class="soft-button" @click="closeTeamFormAction">{{ t.cancel }}</button>
                <button type="submit" :class="pendingTeamFormAction.action === 'delete' ? 'danger-button' : 'save-button'">
                    {{ pendingTeamFormAction.action === 'delete' ? t.delete : pendingTeamFormAction.action === 'import' ? t.importTeamForm : t.save }}
                </button>
            </footer>
        </form>
    </div>

    <PaginationBar
        v-if="!isTournamentDetail && meta.total > meta.per_page && (!isDetail || activeDetailTab === 'tournaments')"
        class="tournament-pagination"
        :meta="meta"
        :of="t.of"
        :pages="displayedPages"
        :rows-per-page="t.rowsPerPage"
        :rows-shown="t.rowsShown"
        @change-page="emit('change-page', $event)"
        @change-per-page="emit('change-per-page', $event)"
    />
</template>
