<script setup>
import StudentTournamentActions from '../../components/panel/StudentTournamentActions.vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import TournamentOptionPicker from '../../components/panel/TournamentOptionPicker.vue';
import KataScoreCell from '../../components/panel/KataScoreCell.vue';
import BracketResultModal from '../../components/panel/BracketResultModal.vue';
import BracketSwapModal from '../../components/panel/BracketSwapModal.vue';
import PaginationBar from '../../components/panel/PaginationBar.vue';
import TemplateModal from '../../components/panel/TemplateModal.vue';
import TournamentStudentPicker from '../../components/panel/TournamentStudentPicker.vue';
import TournamentApplicationModal from '../../components/panel/TournamentApplicationModal.vue';
import FileDropzone from '../../components/panel/FileDropzone.vue';
import TournamentDeleteButton from '../../components/panel/TournamentDeleteButton.vue';
import TournamentBulkAction from '../../components/panel/TournamentBulkAction.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
import { ArrowRightLeft, Unlink, ChevronLeft, ChevronRight } from '@lucide/vue';

const mobileRound = ref(0);
let touchStart = null;
function changeMobileRound(index) { mobileRound.value = Math.max(0, Math.min(index, bracketRounds.value.length - 1)); }
function endRoundSwipe(event) {
    if (!touchStart) return;
    const point = event.changedTouches[0];
    const dx = point.clientX - touchStart.x, dy = point.clientY - touchStart.y;
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) changeMobileRound(mobileRound.value + (dx < 0 ? 1 : -1));
    touchStart = null;
}

const props = defineProps({
    championshipId: { type: [Number, String], required: true },
    locale: { type: String, default: 'ru' },
    detail: { type: Object, required: true },
    displayedPages: { type: Array, required: true },
    filters: { type: Object, required: true },
    isDark: { type: Boolean, default: false },
    isEditing: { type: Boolean, default: false },
    isLoading: { type: Boolean, default: false },
    onAttachCoaches: { type: Function, default: null },
    onAttachLists: { type: Function, default: null },
    onCreateList: { type: Function, default: null },
    onDetachCoach: { type: Function, default: null },
    onDetachList: { type: Function, default: null },
    onReloadDetail: { type: Function, default: null },
    onUpdateStudent: { type: Function, default: null },
    options: { type: Object, required: true },
    t: { type: Object, required: true },
    tournament: { type: Object, required: true },
});

const emit = defineEmits([
    'attach-coaches',
    'attach-lists',
    'change-page',
    'change-per-page',
    'detach-coach',
    'detach-list',
    'filter-students',
    'update-student',
    'create-list',
    'reload-detail',
]);

const activeTab = ref('overview');
const bracketListId = ref('');
const bracketData = ref(null);
const bracketError = ref('');
const isLoadingBrackets = ref(false);
const isMutating = ref(false);
const kataFinalistsCount = ref(4);
const kataActionError = ref('');
const selectedOnlineKataStudent = ref(null);
const selectedOnlineKataPool = ref(null);
const finalVideoCategoryId = ref('');
const finalVideoFile = ref(null);
const finalVideoError = ref('');
const finalVideoSaving = ref(false);
const finalVideoFileName = computed(() => finalVideoFile.value?.name || props.t.chooseFinalVideoFile || props.t.chooseFile);
const selectedResultPool = ref(null);
const initialResultWinner = ref(null);
const showSwapModal = ref(false);
const roundRobinSaving = ref(false);
const confirmation = ref(null);
const isConfirming = ref(false);
const roundRobinForm = reactive({ winner_id_1rd_robbin: '', winner_id_2rd_robbin: '', winner_id_3rd_robbin: '' });
const showCoachModal = ref(false);
const showStudentModal = ref(false);
const applicationAction = ref(null);
async function applicationDone() {
    applicationAction.value = null;
    showStudentModal.value = false;
    if (props.onReloadDetail) await props.onReloadDetail(); else emit('reload-detail');
}
const showListModal = ref(false);
const showCreateListModal = ref(false);
const showStudentWeightModal = ref(false);
const studentWeightMemberships = ref([]);
const coachTableSearch = ref('');
const coachTablePage = ref(1);
const coachTablePerPage = ref(10);
const listTableSearch = ref('');
const listTablePage = ref(1);
const listTablePerPage = ref(10);
const bracketListSearch = ref('');
const bracketTatamiFilter = ref('');
const bracketListPage = ref(1);
const bracketListPerPage = ref(10);
const selectedList = ref(null);
const coachForm = reactive({ ids: [] });
const listForm = reactive({ ids: [] });
const createListForm = reactive(blankCreateListForm());
const editForm = reactive(blankEditForm());
const { request: overviewRequest, run: runOverview, busy: overviewBusy, error: overviewError } = useAccountRequest(() => props.t);
const overviewSaved = ref(false);
const assetFields = computed(() => [
    { key: 'regulation_document', label: props.t.regulationDocument, accept: '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp' },
    { key: 'application_document', label: props.t.applicationDocument, accept: '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp' },
    { key: 'logo_report', label: props.t.reportLogo, accept: 'image/jpeg,image/png,image/webp' },
]);
function currentAsset(key) { return props.tournament.documents?.find(document => document.key === key)?.url; }
function tournamentDeleted() { window.location.assign(`/panel/tournaments/${props.championshipId}`); }
const studentWeightForm = reactive({ student_tournament_id: null, membership_id: null, name: '', weight: '' });

const canMutate = computed(() => props.isEditing && props.tournament?.can_manage);
const canAttachGroupStudents = computed(() => canMutate.value && Boolean(props.tournament?.can_attach_group_students));
const canGenerateAllBrackets = computed(() => canMutate.value && Boolean(props.tournament?.can_generate_all_brackets));
const canConfirmWeight = computed(() => canMutate.value && Number(props.tournament?.tournament_type) === 1);
const isOnlineKata = computed(() => props.tournament?.is_online_kata);
const isEditOnlineKata = computed(() => editForm.tournament_type === '2' && editForm.tournament_type_kata === '2');
const isPointKataTournament = computed(() => Number(props.tournament?.tournament_type) === 2 && Number(props.tournament?.tournament_type_kata) === 2);
const isKataTournament = computed(() => Number(props.tournament?.tournament_type) === 2);
const bracketTitle = computed(() => isPointKataTournament.value ? props.t.tables : props.t.pools);
const generateAllBracketsLabel = computed(() => isPointKataTournament.value ? props.t.generateAllTables : props.t.generateAllPools);
const generateListBracketsLabel = computed(() => isPointKataTournament.value ? props.t.generateListTable : props.t.generateListPool);
const regenerateListBracketsLabel = computed(() => isPointKataTournament.value ? props.t.regenerateListTable : props.t.regenerateListPool);
const sideArt = computed(() => props.isDark ? '/assets/panel/tournament-side-art-dark.png' : '/assets/panel/tournament-side-art.png');
const selectedBracketList = computed(() => props.detail.lists?.find((list) => Number(list.id) === Number(bracketListId.value)) ?? null);
const generatedBracketLists = computed(() => (props.detail.lists ?? []).filter((list) => list.has_generated || Number(list.generated_count) > 0 || Number(list.fillable_count) > 0));
const hasGeneratedBrackets = computed(() => generatedBracketLists.value.length > 0);
const bracketTatamiOptions = computed(() => Array.from(
    { length: Math.max(0, Number(props.tournament?.tatami) || 0) },
    (_, index) => String.fromCharCode(65 + index),
));
const bracketDownloadUrl = computed(() => (
    hasGeneratedBrackets.value && isPointKataTournament.value
        ? props.tournament?.downloads?.kata_tables
        : (hasGeneratedBrackets.value ? props.tournament?.downloads?.brackets : '')
));
const bracketDownloadLabel = computed(() => (
    isPointKataTournament.value ? props.t.downloadTablesPdf : props.t.downloadPoolsPdf
));
const kataTableDownloadUrl = computed(() => {
    if (! isPointKataTournament.value || ! bracketListId.value) return '';

    return `/api/panel/tournaments/${props.championshipId}/items/${props.tournament.id}/kata/${bracketListId.value}/pdf`;
});
const filteredBracketLists = computed(() => {
    const normalized = bracketListSearch.value.trim().toLowerCase();
    const lists = generatedBracketLists.value
        .filter((list) => ! bracketTatamiFilter.value || list.tatami === bracketTatamiFilter.value)
        .slice()
        .sort((left, right) => {
            const leftTatami = left.tatami || 'ZZZ';
            const rightTatami = right.tatami || 'ZZZ';
            const tatamiCompare = leftTatami.localeCompare(rightTatami, undefined, { numeric: true });

            return tatamiCompare !== 0 ? tatamiCompare : String(left.name || '').localeCompare(String(right.name || ''));
        });

    if (! normalized) return lists;

    return lists.filter((list) => [
        list.name,
        list.tatami,
        list.type,
        list.kata_type,
        list.age,
        list.weight,
        list.rank,
        list.gender,
        ...(list.participant_names || []),
        list.students_count,
        list.generated_count,
        list.completion_percent,
    ].filter((value) => value !== null && value !== undefined).some((value) => String(value).toLowerCase().includes(normalized)));
});
const paginatedBracketLists = computed(() => {
    const start = (bracketListPage.value - 1) * bracketListPerPage.value;

    return filteredBracketLists.value.slice(start, start + bracketListPerPage.value);
});
const bracketListMeta = computed(() => {
    const total = filteredBracketLists.value.length;
    const lastPage = Math.max(1, Math.ceil(total / bracketListPerPage.value));
    const currentPage = Math.min(bracketListPage.value, lastPage);
    const from = total === 0 ? 0 : (currentPage - 1) * bracketListPerPage.value + 1;
    const to = Math.min(total, currentPage * bracketListPerPage.value);

    return {
        current_page: currentPage,
        last_page: lastPage,
        per_page: bracketListPerPage.value,
        total,
        from,
        to,
    };
});
const displayedBracketListPages = computed(() => visiblePages(bracketListMeta.value));
const bracketPools = computed(() => bracketData.value?.tournament?.pools ?? []);
const bracketRounds = computed(() => {
    const grouped = new Map();
    bracketPools.value
        .filter((pool) => ! ['Round Robin', '3rd'].includes(pool.type))
        .forEach((pool) => {
            const key = pool.round || pool.type || 1;
            if (! grouped.has(key)) grouped.set(key, []);
            grouped.get(key).push(pool);
        });

    const rounds = Array.from(grouped.entries())
        .sort(([a], [b]) => Number(a) - Number(b));

    return rounds
        .map(([round, pools], index) => ({
            round: Number(round),
            title: bracketRoundTitle(pools[0], index, rounds.length),
            pools: [...pools].sort((a, b) => Number(a.position_in_round || 0) - Number(b.position_in_round || 0)),
        }));
});
const bracketGridTemplateColumns = computed(() => {
    const count = Math.max(bracketRounds.value.length, 1);

    if (count === 1) {
        return 'minmax(0, 1fr)';
    }

    return Array.from({ length: count }, (_, index) => {
        if (index === count - 2) return 'minmax(0, 1.28fr)';
        if (index === count - 1) return 'minmax(0, 1.18fr)';

        return 'minmax(0, .88fr)';
    }).join(' ');
});
const roundRobinPools = computed(() => bracketPools.value.filter((pool) => pool.type === 'Round Robin'));
const thirdPlacePools = computed(() => bracketPools.value.filter((pool) => pool.type === '3rd'));
const finalPool = computed(() => bracketPools.value.find((pool) => pool.type === 'final') ?? null);
const bracketRowCount = computed(() => {
    const firstRoundCount = bracketRounds.value[0]?.pools?.length ?? 1;

    return Math.max(firstRoundCount * 2, 2);
});
const bracketPodium = computed(() => {
    if (roundRobinPools.value.length) {
        const firstPool = roundRobinPools.value[0];
        const byId = new Map();
        roundRobinPools.value.forEach((pool) => {
            poolParticipants(pool).forEach((participant) => byId.set(Number(participant.id), participant));
        });

        return [
            { place: 'gold', label: props.t.firstPlace, medal: '🥇', participant: byId.get(Number(firstPool?.winner_id_1rd_robbin)) },
            { place: 'silver', label: props.t.secondPlace, medal: '🥈', participant: byId.get(Number(firstPool?.winner_id_2rd_robbin)) },
            { place: 'bronze', label: props.t.thirdPlace, medal: '🥉', participant: byId.get(Number(firstPool?.winner_id_3rd_robbin)) },
        ].filter((item) => item.participant);
    }

    const final = finalPool.value;
    const third = thirdPlacePools.value[0];
    const podium = [];

    if (final?.winner_id) {
        const winner = Number(final.student_id) === Number(final.winner_id) ? final.student : final.opponent;
        const silver = Number(final.student_id) === Number(final.winner_id) ? final.opponent : final.student;
        if (winner) podium.push({ place: 'gold', label: props.t.firstPlace, medal: '🥇', participant: winner });
        if (silver) podium.push({ place: 'silver', label: props.t.secondPlace, medal: '🥈', participant: silver });
    }

    if (third?.winner_id) {
        const bronze = Number(third.student_id) === Number(third.winner_id) ? third.student : third.opponent;
        if (bronze) podium.push({ place: 'bronze', label: props.t.thirdPlace, medal: '🥉', participant: bronze });
    }

    return podium;
});
const roundRobinParticipants = computed(() => {
    const seen = new Map();
    roundRobinPools.value.forEach((pool) => {
        poolParticipants(pool).forEach((participant) => {
            if (! seen.has(participant.id)) seen.set(participant.id, participant);
        });
    });

    return Array.from(seen.values());
});
const kataTables = computed(() => bracketData.value?.pools ?? { pre: [], final: [] });
const kataScoreFields = ['referee_score', 'judge1_score', 'judge2_score', 'judge3_score', 'judge4_score'];
const hasKataFinal = computed(() => (kataTables.value.final ?? []).length > 0);
const kataResults = computed(() => [1, 2, 3].map((place) => {
    const pool = (kataTables.value.final ?? []).find((item) => Boolean(item[`winner_${place}`]));
    if (! pool) return null;

    return {
        place,
        pool,
        name: kataPoolName(pool),
        total: pool.total_score ?? '-',
    };
}).filter(Boolean));

const tabs = computed(() => [
    { key: 'overview', label: props.t.overview },
    { key: 'students', label: props.t.participantsTab },
    { key: 'coaches', label: props.t.trainers },
    { key: 'lists', label: props.t.lists },
    { key: 'brackets', label: bracketTitle.value },
]);
const tournamentTabKeys = ['overview', 'students', 'coaches', 'lists', 'brackets'];

const createListVisibleFields = computed(() => ({
    kataType: createListForm.list_type === 'kata',
    weight: createListForm.list_type === 'kumite',
    rank: createListForm.list_type === 'kumite' || createListForm.kata_type === 'flag',
    gender: createListForm.list_type === 'kumite' || createListForm.kata_type === 'personal' || createListForm.kata_type === 'flag',
}));

const downloads = computed(() => [
    isPointKataTournament.value
        ? { key: 'kata_protocols', label: props.t.kataTablesDownload, tone: 'blue' }
        : {
            key: isKataTournament.value ? 'kata_protocols' : 'kumite_protocols',
            label: isKataTournament.value ? props.t.kataProtocols : props.t.kumiteProtocols,
            tone: isKataTournament.value ? 'blue' : 'red',
        },
    { key: 'results', label: props.t.resultsDownload, tone: 'green' },
    { key: 'certificate', label: props.t.tournamentCertificate, tone: 'gold' },
].filter((item) => props.tournament?.downloads?.[item.key]));

const filteredTournamentCoaches = computed(() => {
    const normalized = coachTableSearch.value.trim().toLowerCase();
    const coaches = props.detail.coaches ?? [];

    if (! normalized) return coaches;

    return coaches.filter((coach) => [coach.name, coach.email, coach.club]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(normalized)));
});
const paginatedTournamentCoaches = computed(() => {
    const start = (coachTablePage.value - 1) * coachTablePerPage.value;

    return filteredTournamentCoaches.value.slice(start, start + coachTablePerPage.value);
});
const coachTableMeta = computed(() => {
    const total = filteredTournamentCoaches.value.length;
    const lastPage = Math.max(1, Math.ceil(total / coachTablePerPage.value));
    const currentPage = Math.min(coachTablePage.value, lastPage);
    const from = total === 0 ? 0 : (currentPage - 1) * coachTablePerPage.value + 1;
    const to = Math.min(total, currentPage * coachTablePerPage.value);

    return {
        current_page: currentPage,
        last_page: lastPage,
        per_page: coachTablePerPage.value,
        total,
        from,
        to,
    };
});
const displayedCoachPages = computed(() => visiblePages(coachTableMeta.value));
const filteredTournamentLists = computed(() => {
    const normalized = listTableSearch.value.trim().toLowerCase();
    const lists = props.detail.lists ?? [];

    if (! normalized) return lists;

    return lists.filter((list) => [
        list.name,
        list.type,
        list.kata_type,
        list.age,
        list.weight,
        list.rank,
        list.gender,
    ].filter(Boolean).some((value) => String(value).toLowerCase().includes(normalized)));
});
const paginatedTournamentLists = computed(() => {
    const start = (listTablePage.value - 1) * listTablePerPage.value;

    return filteredTournamentLists.value.slice(start, start + listTablePerPage.value);
});
const listTableMeta = computed(() => {
    const total = filteredTournamentLists.value.length;
    const lastPage = Math.max(1, Math.ceil(total / listTablePerPage.value));
    const currentPage = Math.min(listTablePage.value, lastPage);
    const from = total === 0 ? 0 : (currentPage - 1) * listTablePerPage.value + 1;
    const to = Math.min(total, currentPage * listTablePerPage.value);

    return {
        current_page: currentPage,
        last_page: lastPage,
        per_page: listTablePerPage.value,
        total,
        from,
        to,
    };
});
const displayedListPages = computed(() => visiblePages(listTableMeta.value));

function tournamentViewStateFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');

    return {
        tab: tournamentTabKeys.includes(tab) ? tab : 'overview',
        listId: params.get('list') || '',
    };
}

function writeTournamentViewStateToUrl(tab, listId = '') {
    const url = new URL(window.location.href);

    if (tab && tab !== 'overview') {
        url.searchParams.set('tab', tab);
    } else {
        url.searchParams.delete('tab');
    }

    if (tab === 'brackets' && listId) {
        url.searchParams.set('list', listId);
    } else {
        url.searchParams.delete('list');
    }

    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
}

watch(
    () => props.tournament.id,
    () => {
        const viewState = tournamentViewStateFromUrl();
        activeTab.value = viewState.tab;
        coachTableSearch.value = '';
        coachTablePage.value = 1;
        listTableSearch.value = '';
        listTablePage.value = 1;
        bracketListSearch.value = '';
        bracketTatamiFilter.value = '';
        bracketListPage.value = 1;
        selectedList.value = null;
        bracketListId.value = viewState.tab === 'brackets' ? viewState.listId : '';
        bracketData.value = null;
        bracketError.value = '';
        props.filters.list_id = '';
        syncEditForm();
        if (viewState.tab === 'brackets' && viewState.listId) {
            loadBracketData();
        }
    },
    { immediate: true },
);

watch(coachTableSearch, () => {
    coachTablePage.value = 1;
});

watch(listTableSearch, () => {
    listTablePage.value = 1;
});

watch(bracketListSearch, () => {
    bracketListPage.value = 1;
});

watch(bracketTatamiFilter, () => {
    bracketListPage.value = 1;
});

watch(activeTab, (tab) => {
    if (tab !== 'lists' && selectedList.value) {
        selectedList.value = null;
        props.filters.list_id = '';
        props.filters.search = '';
        props.filters.page = 1;
        emit('filter-students');
    }

    writeTournamentViewStateToUrl(tab, tab === 'brackets' ? bracketListId.value : '');

    if (tab === 'brackets' && bracketListId.value) return;
});

watch(bracketListId, (listId) => {
    mobileRound.value = 0;
    touchStart = null;
    if (activeTab.value === 'brackets') {
        writeTournamentViewStateToUrl(activeTab.value, listId);
    }
});

watch(filteredTournamentCoaches, () => {
    if (coachTablePage.value > coachTableMeta.value.last_page) {
        coachTablePage.value = coachTableMeta.value.last_page;
    }
});

watch(filteredTournamentLists, () => {
    if (listTablePage.value > listTableMeta.value.last_page) {
        listTablePage.value = listTableMeta.value.last_page;
    }
});

watch(filteredBracketLists, () => {
    if (bracketListPage.value > bracketListMeta.value.last_page) {
        bracketListPage.value = bracketListMeta.value.last_page;
    }
});

watch(
    () => props.isEditing,
    () => {
        activeTab.value = 'overview';
        syncEditForm();
    },
);

watch(
    () => editForm.tournament_type,
    () => {
        if (editForm.tournament_type === '1') {
            editForm.tournament_type_kata = '';
            editForm.is_online_kata = false;
        } else if (! editForm.tournament_type_kata) {
            editForm.tournament_type_kata = '1';
        }
    },
);

watch(
    () => editForm.tournament_type_kata,
    () => {
        if (! isEditOnlineKata.value) {
            editForm.is_online_kata = false;
        }
    },
);

watch(
    () => createListForm.list_type,
    (value) => {
        if (value === 'kata' && ! createListForm.kata_type) {
            createListForm.kata_type = 'personal';
        }
    },
);

function blankEditForm() {
    return {
        name: '',
        region_id: '',
        scale_id: '',
        age_from: '',
        age_to: '',
        KY_up_to_8: false,
        KY_from_8: false,
        fight_for_third_place: false,
        tournament_type: '1',
        tournament_type_kata: '',
        is_online_kata: false,
        tatami: '',
        price: '',
        date_commission: '',
        date: '',
        date_finish: '',
        address: '',
        chief_judge: '',
        chief_secretary: '',
        accepts_organization_applications: false,
        regulation_document: null, application_document: null, logo_report: null,
        remove_regulation_document: false, remove_application_document: false, remove_logo_report: false,
    };
}

function blankCreateListForm() {
    return {
        name: '',
        list_type: 'kumite',
        kata_type: 'personal',
        age_from: '',
        age_to: '',
        weight_from: '',
        weight_to: '',
        rang_from: '',
        rang_to: '',
        gender: 'm',
    };
}

function normalizeCreateListForm() {
    const payload = { ...createListForm };
    if (payload.list_type === 'kumite') payload.kata_type = null;
    if (payload.list_type === 'kata') {
        payload.weight_from = null;
        payload.weight_to = null;
        if (payload.kata_type === 'personal') {
            payload.rang_from = null;
            payload.rang_to = null;
        }
        if (payload.kata_type === 'group') {
            payload.rang_from = null;
            payload.rang_to = null;
            payload.gender = null;
        }
    }

    return payload;
}

function syncEditForm() {
    Object.assign(editForm, blankEditForm(), {
        accepts_organization_applications: Boolean(props.tournament.accepts_organization_applications),
        name: props.tournament.name ?? '',
        region_id: props.tournament.region_id ?? '',
        scale_id: props.tournament.scale_id ?? '',
        age_from: props.tournament.age_from ?? '',
        age_to: props.tournament.age_to ?? '',
        KY_up_to_8: Boolean(props.tournament.KY_up_to_8),
        KY_from_8: Boolean(props.tournament.KY_from_8),
        fight_for_third_place: Boolean(props.tournament.fight_for_third_place),
        tournament_type: String(props.tournament.tournament_type ?? '1'),
        tournament_type_kata: props.tournament.tournament_type_kata ? String(props.tournament.tournament_type_kata) : '',
        is_online_kata: Boolean(props.tournament.is_online_kata),
        tatami: props.tournament.tatami ?? '',
        price: props.tournament.price ?? '',
        date_commission: props.tournament.date_commission_input ?? '',
        date: props.tournament.date_input ?? '',
        date_finish: props.tournament.date_finish_input ?? '',
        address: props.tournament.address ?? '',
        chief_judge: props.tournament.chief_judge ?? '',
        chief_secretary: props.tournament.chief_secretary ?? '',
    });
}

function filterOptions(options, search) {
    const normalized = search.trim().toLowerCase();
    if (! normalized) return options.slice(0, 10);

    return options
        .filter((option) => option.name.toLowerCase().includes(normalized))
        .slice(0, 40);
}

function visiblePages(meta) {
    const current = meta.current_page || 1;
    const last = meta.last_page || 1;
    const start = Math.max(1, current - 2);
    const end = Math.min(last, current + 2);

    return Array.from({ length: end - start + 1 }, (_, index) => start + index);
}

function submitOverview() {
    overviewSaved.value = false;
    runOverview(async () => {
        const data = new FormData();
        data.set('_method', 'PUT'); data.set('locale', props.locale);
        const payload = { ...editForm, tournament_type_kata: editForm.tournament_type === '2' ? editForm.tournament_type_kata : '' };
        for (const [key, value] of Object.entries(payload)) {
            if (assetFields.value.some(field => field.key === key) && !value) continue;
            data.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : (value ?? ''));
        }
        await overviewRequest(`/api/panel/tournaments/${props.championshipId}/items/${props.tournament.id}`, data);
        if (props.onReloadDetail) await props.onReloadDetail(); else emit('reload-detail');
        await nextTick();
        syncEditForm();
        overviewSaved.value = true;
    });
}

async function submitCoaches() {
    if (isMutating.value || coachForm.ids.length === 0) return;
    isMutating.value = true;

    try {
        if (props.onAttachCoaches) {
            await props.onAttachCoaches([...coachForm.ids]);
        } else {
            emit('attach-coaches', [...coachForm.ids]);
        }

        coachForm.ids = [];
        showCoachModal.value = false;
    } finally {
        isMutating.value = false;
    }
}

async function submitLists() {
    if (isMutating.value || listForm.ids.length === 0) return;
    isMutating.value = true;

    try {
        if (props.onAttachLists) {
            await props.onAttachLists([...listForm.ids]);
        } else {
            emit('attach-lists', [...listForm.ids]);
        }

        listForm.ids = [];
        showListModal.value = false;
    } finally {
        isMutating.value = false;
    }
}

function openCreateListModal() {
    Object.assign(createListForm, blankCreateListForm());
    showCreateListModal.value = true;
}

async function submitCreateList() {
    if (isMutating.value) return;
    isMutating.value = true;

    try {
        if (props.onCreateList) {
            await props.onCreateList(normalizeCreateListForm());
        } else {
            emit('create-list', normalizeCreateListForm());
        }

        showCreateListModal.value = false;
    } finally {
        isMutating.value = false;
    }
}

async function detachCoach(id) {
    if (isMutating.value) return;
    isMutating.value = true;

    try {
        if (props.onDetachCoach) {
            await props.onDetachCoach(id);
        } else {
            emit('detach-coach', id);
        }
    } finally {
        isMutating.value = false;
    }
}

async function detachList(id) {
    if (isMutating.value) return;
    isMutating.value = true;

    try {
        if (props.onDetachList) {
            await props.onDetachList(id);
        } else {
            emit('detach-list', id);
        }
    } finally {
        isMutating.value = false;
    }
}

function openListStudents(list) {
    selectedList.value = list;
    props.filters.list_id = list.id;
    props.filters.search = '';
    props.filters.coach_id = '';
    props.filters.page = 1;
    emit('filter-students');
}

function closeListStudents() {
    selectedList.value = null;
    props.filters.list_id = '';
    props.filters.search = '';
    props.filters.page = 1;
    emit('filter-students');
}

function studentProfileUrl(student) {
    return `/panel/team/students/${student.id}`;
}

function documentsStatusTitle(student) {
    const key = student?.documents_status_key || (student?.documents_ok ? 'documentStatusOk' : 'documentIssueUnknown');
    const title = props.t[key] || props.t.documentIssueUnknown || '';
    const context = student?.documents_status_context || {};

    if (key === 'documentIssueInsuranceExpired' && context.insurance_close_date && context.tournament_finish_date) {
        return `${title}: ${context.insurance_close_date} < ${context.tournament_finish_date}`;
    }

    return title;
}

async function updateTournamentStudent(payload) {
    if (isMutating.value) return;
    isMutating.value = true;

    try {
        if (props.onUpdateStudent) {
            await props.onUpdateStudent(payload);
        } else {
            emit('update-student', payload);
        }
    } finally {
        isMutating.value = false;
    }
}

function openStudentWeightModal(student) {
    studentWeightMemberships.value = student.memberships ?? [];
    studentWeightForm.student_tournament_id = student.pivot_id;
    studentWeightForm.name = student.name;
    studentWeightForm.weight = student.weight ?? '';
    studentWeightForm.membership_id = student.memberships?.find(item => Number(item.list_id) === Number(selectedList.value?.id))?.id
        ?? (student.memberships?.length === 1 ? student.memberships[0].id : null);
    showStudentWeightModal.value = true;
}

function closeStudentWeightModal() {
    showStudentWeightModal.value = false;
    studentWeightForm.student_tournament_id = null;
    studentWeightForm.name = '';
    studentWeightForm.weight = '';
    studentWeightForm.membership_id = null;
}

async function submitStudentWeight() {
    if (! studentWeightForm.student_tournament_id) return;

    await updateTournamentStudent({
        student_tournament_id: studentWeightForm.student_tournament_id,
        membership_id: studentWeightForm.membership_id,
        weight: studentWeightForm.weight === '' ? null : Number(studentWeightForm.weight),
    });
    closeStudentWeightModal();
}

function setCoachTablePage(page) {
    coachTablePage.value = page;
}

function setCoachTablePerPage(perPage) {
    coachTablePerPage.value = Number(perPage);
    coachTablePage.value = 1;
}

function setListTablePage(page) {
    listTablePage.value = page;
}

function setListTablePerPage(perPage) {
    listTablePerPage.value = Number(perPage);
    listTablePage.value = 1;
}

function setBracketListPage(page) {
    bracketListPage.value = page;
}

function setBracketListPerPage(perPage) {
    bracketListPerPage.value = Number(perPage);
    bracketListPage.value = 1;
}

function listHasGeneratedBrackets(list) {
    return Boolean(list?.has_generated || Number(list?.generated_count) > 0 || Number(list?.fillable_count) > 0);
}

function listBracketsActionLabel(list) {
    return listHasGeneratedBrackets(list)
        ? regenerateListBracketsLabel.value
        : generateListBracketsLabel.value;
}

function openConfirmation(message, confirmLabel, action, onCancel = null) {
    confirmation.value = {
        title: props.t.confirmRegeneration,
        message,
        confirmLabel,
        action,
        onCancel,
    };
}

function closeConfirmation() {
    if (isConfirming.value) return;
    confirmation.value?.onCancel?.();
    confirmation.value = null;
}

async function confirmPendingAction() {
    if (! confirmation.value || isConfirming.value) return;

    isConfirming.value = true;
    const action = confirmation.value.action;

    try {
        await action();
        confirmation.value = null;
    } finally {
        isConfirming.value = false;
    }
}

function shouldConfirmBracketGeneration(targetListId, options = {}) {
    if (options.confirmed) return false;
    if (options.forceAll) return hasGeneratedBrackets.value;
    if (! targetListId) return hasGeneratedBrackets.value;

    const list = options.sourceList
        || props.detail.lists?.find((item) => Number(item.id) === Number(targetListId))
        || selectedBracketList.value;

    return listHasGeneratedBrackets(list);
}

function bracketRegenerationMessage(targetListId, options = {}) {
    if (options.forceAll || ! targetListId) {
        return isPointKataTournament.value ? props.t.confirmRegenerateAllTables : props.t.confirmRegenerateAllPools;
    }

    return isPointKataTournament.value ? props.t.confirmRegenerateTable : props.t.confirmRegeneratePool;
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function bracketBasePath() {
    return `/api/panel/tournaments/${props.championshipId}/items/${props.tournament.id}`;
}

async function bracketRequest(path, options = {}) {
    const headers = {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
        ...(options.headers ?? {}),
    };

    if (options.method === 'POST' && (/^\/pools\/\d+\/(winner|absences|tatami)$/.test(path) || /^\/brackets\/\d+\/(swap|round-robin\/winners)$/.test(path))) {
        options.body = JSON.stringify({ ...JSON.parse(options.body || '{}'), revision: bracketData.value?.revision, locale: props.locale });
    }
    const response = await fetch(`${bracketBasePath()}${path}${path.includes('?') ? '&' : '?'}locale=${props.locale}`, { ...options, headers });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(Object.values(payload.errors || {}).flat()[0] || payload?.message || props.t.validationError);
        error.code = payload.code; error.status = response.status;
        throw error;
    }
    if (payload.revision && bracketData.value && options.method) bracketData.value.revision = payload.revision;

    return payload;
}

async function loadBracketData() {
    if (! bracketListId.value) return;

    isLoadingBrackets.value = true;
    bracketError.value = '';

    try {
        const endpoint = isPointKataTournament.value
            ? `/kata/${bracketListId.value}`
            : `/brackets/${bracketListId.value}`;
        bracketData.value = await bracketRequest(endpoint);
        if (isPointKataTournament.value) {
            kataFinalistsCount.value = bracketData.value?.finalists_count || 4;
        }
        syncRoundRobinForm();
    } catch (error) {
        bracketError.value = error?.message || props.t.validationError;
    } finally {
        isLoadingBrackets.value = false;
    }
}

function openBracketList(list) {
    bracketListId.value = list.id;
    loadBracketData();
}

function closeBracketList() {
    bracketListId.value = '';
    bracketData.value = null;
    bracketError.value = '';
}

async function generateBrackets(listId = null, options = {}) {
    const targetListId = options.forceAll ? null : listId || bracketListId.value || null;

    if (shouldConfirmBracketGeneration(targetListId, options)) {
        openConfirmation(
            bracketRegenerationMessage(targetListId, options),
            targetListId ? (isPointKataTournament.value ? props.t.regenerateListTable : props.t.regenerateListPool) : props.t.regenerateAll,
            () => generateBrackets(listId, { ...options, confirmed: true }),
        );
        return;
    }

    isLoadingBrackets.value = true;
    bracketError.value = '';

    try {
        const result = await bracketRequest('/brackets/generate', {
            method: 'POST',
            body: JSON.stringify({ list_id: targetListId }),
        });
        if (result.task) {
            window.location.assign(`/panel/tasks/${result.task.id}`);
            return;
        }
        if (props.onReloadDetail) {
            await props.onReloadDetail();
        } else {
            emit('reload-detail');
        }
        if (targetListId && ! options.stayOnLists) {
            bracketListId.value = targetListId;
            await loadBracketData();
        } else {
            bracketData.value = null;
        }
    } catch (error) {
        bracketError.value = error?.message || props.t.validationError;
    } finally {
        isLoadingBrackets.value = false;
    }
}

async function generateListBrackets(list) {
    if (! list?.id) return;

    await generateBrackets(list.id, { stayOnLists: true, sourceList: list });
}

async function saveListTatami(list, value) {
    if (! list?.id || isLoadingBrackets.value) return;

    const previous = list.tatami || '';
    list.tatami = value || '';
    isLoadingBrackets.value = true;
    bracketError.value = '';

    try {
        const response = await bracketRequest(`/lists/${list.id}/tatami`, {
            method: 'POST',
            body: JSON.stringify({ tatami: list.tatami || null }),
        });
        list.tatami = response.tatami || '';
    } catch (error) {
        list.tatami = previous;
        bracketError.value = error?.message || props.t.validationError;
    } finally {
        isLoadingBrackets.value = false;
    }
}

function syncRoundRobinForm() {
    const pool = roundRobinPools.value[0];
    roundRobinForm.winner_id_1rd_robbin = pool?.winner_id_1rd_robbin || '';
    roundRobinForm.winner_id_2rd_robbin = pool?.winner_id_2rd_robbin || '';
    roundRobinForm.winner_id_3rd_robbin = pool?.winner_id_3rd_robbin || '';
}

async function savePoolTatami(pool) {
    try { await bracketRequest(`/pools/${pool.id}/tatami`, {
        method: 'POST',
        body: JSON.stringify({ value: pool.tatami_and_fight_number || null }),
    }); } catch (error) { bracketError.value = error.message; }
}

async function saveRoundRobinWinners() {
    if (!roundRobinPools.value.length || roundRobinSaving.value) return;
    roundRobinSaving.value = true;
    try { await bracketRequest(`/brackets/${bracketListId.value}/round-robin/winners`, {
        method: 'POST',
        body: JSON.stringify({
            pool_ids: roundRobinPools.value.map((pool) => pool.id),
            winner_id_1rd_robbin: roundRobinForm.winner_id_1rd_robbin,
            winner_id_2rd_robbin: roundRobinForm.winner_id_2rd_robbin,
            winner_id_3rd_robbin: roundRobinForm.winner_id_3rd_robbin || null,
        }),
    });
    await loadBracketData();
    } catch (error) { bracketError.value = error.message; }
    finally { roundRobinSaving.value = false; }
}

async function updateKataNumber(pool) {
    const participant_number = pool.participant_number;
    return queueKata(async () => {
        kataActionError.value = '';
        try {
            bracketData.value = await bracketRequest(`/kata-pools/${pool.id}/number`, {
                method: 'POST', body: JSON.stringify({ participant_number }),
            });
        } catch (error) { kataActionError.value = error.message; }
    });
}

let kataQueue = Promise.resolve();
const kataBusy = ref(false);
const kataRefreshVersion = ref(0);
async function refreshKata() { kataActionError.value = ''; await loadBracketData(); kataRefreshVersion.value++; }
function queueKata(action) {
    const task = kataQueue.then(async () => { kataBusy.value = true; try { return await action(); } finally { kataBusy.value = false; } });
    kataQueue = task.catch(() => {});
    return task;
}
function confirmKataChange(message) {
    return new Promise(resolve => {
        openConfirmation(message, props.t.confirm, () => resolve(true), () => resolve(false));
        confirmation.value.title = props.t.kataConfirmChange;
    });
}
async function kataWrite(path, body = {}) {
    const revision = bracketData.value?.revision;
    try { return await bracketRequest(path, { method: 'POST', body: JSON.stringify({ ...body, revision }) }); }
    catch (error) {
        if (error.code !== 'kata_confirmation_required') throw error;
        if (!await confirmKataChange(error.message)) throw new Error(props.t.kataChangeCancelled);
        return await bracketRequest(path, { method: 'POST', body: JSON.stringify({ ...body, revision, confirmed: true }) });
    }
}
function updateKataScore(pool, field, value, original) {
    return queueKata(async () => {
        kataActionError.value = '';
        try {
            bracketData.value = await kataWrite(`/kata-pools/${pool.id}/score`, { field, value, original_value: original });
            await nextTick();
        } catch (error) { kataActionError.value = error.message; throw error; }
    });
}
function saveKataFinalistsCount() {
    queueKata(async () => {
        kataActionError.value = '';
        try { bracketData.value = await kataWrite(`/kata/${bracketListId.value}/finalists-count`, { count: Number(kataFinalistsCount.value) }); }
        catch (error) { kataActionError.value = error.message; kataFinalistsCount.value = bracketData.value.finalists_count; }
    });
}
function generateKataFinal() {
    queueKata(async () => {
        kataActionError.value = '';
        try { bracketData.value = await kataWrite(`/kata/${bracketListId.value}/final`); }
        catch (error) { kataActionError.value = error.message; }
    });
}
function generateKataWinners() {
    queueKata(async () => {
        kataActionError.value = '';
        try { bracketData.value = await kataWrite(`/kata/${bracketListId.value}/winners`); }
        catch (error) { kataActionError.value = error.message; }
    });
}

async function uploadKataFinalVideo(pool, student) {
    if (finalVideoSaving.value || ! finalVideoFile.value || ! finalVideoCategoryId.value) return;
    if (finalVideoFile.value.size > 100 * 1024 * 1024) {
        finalVideoError.value = props.t.videoTooLarge;
        return;
    }

    finalVideoSaving.value = true;
    finalVideoError.value = '';
    const formData = new FormData();
    formData.append('student_id', student.id);
    formData.append('category_id', finalVideoCategoryId.value);
    formData.append('video', finalVideoFile.value);

    try {
        await bracketRequest(`/kata-pools/${pool.id}/final-video`, {
            method: 'POST',
            body: formData,
        });
        closeOnlineKataModal();
        await loadBracketData();
    } catch (error) {
        finalVideoError.value = error?.message || props.t.validationError;
    } finally {
        finalVideoSaving.value = false;
    }
}

function participantName(participant) {
    if (! participant) return props.t.emptySlot;

    return `${participant.last_name || ''} ${participant.first_name || ''}`.trim();
}

function kataPoolName(pool) {
    return kataPoolStudents(pool).map((student) => participantName(student)).filter(Boolean).join(', ') || props.t.emptySlot;
}

function onlineApplicationForRound(student, pool) {
    const application = student?.online_application;
    if (! application) return null;

    return pool?.round === 'FINAL' ? application.second_round : application.first_round;
}

function openOnlineKataModal(student, pool) {
    const application = onlineApplicationForRound(student, pool);
    if (! isOnlineKata.value || (! application?.video_url && ! student?.can_upload_final_video)) return;

    selectedOnlineKataStudent.value = { ...student, active_online_application: application };
    selectedOnlineKataPool.value = pool;
    finalVideoCategoryId.value = application?.category_id || student.online_application?.first_round?.category_id || bracketData.value?.education_categories?.[0]?.id || '';
    finalVideoFile.value = null;
    finalVideoError.value = '';
}

function closeOnlineKataModal() {
    selectedOnlineKataStudent.value = null;
    selectedOnlineKataPool.value = null;
    finalVideoCategoryId.value = '';
    finalVideoFile.value = null;
    finalVideoError.value = '';
}

function canOpenOnlineKataModal(student, pool) {
    return Boolean(isOnlineKata.value && (onlineApplicationForRound(student, pool)?.video_url || student?.can_upload_final_video));
}

function setFinalVideoFile(event) {
    finalVideoFile.value = event.target.files?.[0] || null;
}

function rankLabel(rang) {
    const value = String(rang || '')
        .trim()
        .replace(/\s+/g, ' ')
        .replace(/(кю)\s+кю/ig, '$1')
        .replace(/(дан)\s+дан/ig, '$1')
        .replace(/(kyu)\s+kyu/ig, '$1')
        .replace(/(dan)\s+dan/ig, '$1');
    if (! value) return '';
    if (/(кю|дан|kyu|dan)/i.test(value)) return value;

    return `${value} ${props.t.kyuShort || 'кю'}`;
}

function kataStudentMeta(student) {
    return [
        student?.coach_name ? `${props.t.trainer}: ${student.coach_name}` : null,
        rankLabel(student?.rang),
    ].filter(Boolean).join(' · ');
}

function bracketRoundTitle(pool, index, totalRounds) {
    if (pool?.type === 'final') return props.t.finalStage;
    if (pool?.type === '1/2' || index === totalRounds - 2) return props.t.oneHalfFinal || '1/2 финала';

    const roundsBeforeFinal = Math.max(totalRounds - index - 1, 1);
    const denominator = 2 ** roundsBeforeFinal;

    return `1/${denominator}`;
}

function participantMeta(participant) {
    if (! participant) return '';

    const parts = [
        participant.coach_line,
        participant.is_success_weight === false ? props.t.weightNotConfirmed : null,
    ].filter(Boolean);

    return parts.join(' · ');
}

function openPoolResultModal(pool, winnerId = null) {
    if (!canMutate.value || !pool || (!pool.student && !pool.opponent)) return;
    selectedResultPool.value = pool;
    initialResultWinner.value = winnerId;
}
async function fightMutationDone() {
    selectedResultPool.value = null;
    showSwapModal.value = false;
    await loadBracketData();
}
function roundRobinOptions(field) {
    const others = Object.entries(roundRobinForm).filter(([key]) => key !== field).map(([, id]) => Number(id));
    return roundRobinParticipants.value.filter(person => !others.includes(Number(person.id)));
}

function poolGridRow(pool) {
    if (pool?.type === 'final') {
        return centeredBracketGridRow();
    }

    const round = Math.max(Number(pool?.round) || 1, 1);
    const position = Math.max(Number(pool?.position_in_round) || 1, 1);
    const span = Math.min(2 ** round, bracketRowCount.value);
    const start = Math.min(((position - 1) * span) + 1, bracketRowCount.value);

    return `${start} / span ${span}`;
}

function centeredBracketGridRow() {
    const span = Math.min(4, bracketRowCount.value);
    const start = Math.max(1, Math.floor((bracketRowCount.value - span) / 2) + 1);

    return `${start} / span ${span}`;
}

function shouldDrawPairConnector(pool, roundIndex) {
    if (roundIndex >= bracketRounds.value.length - 1) return false;

    return Math.max(Number(pool?.position_in_round) || 1, 1) % 2 === 1;
}

function participantMedal(pool, participantId) {
    if (! pool?.winner_id || ! participantId) return null;

    const id = Number(participantId);
    if (pool.type === 'final') {
        return Number(pool.winner_id) === id
            ? { class: 'gold', label: '🥇' }
            : { class: 'silver', label: '🥈' };
    }

    if (pool.type === '3rd' && Number(pool.winner_id) === id) {
        return { class: 'bronze', label: '🥉' };
    }

    if (Number(pool.winner_id) === id) {
        return { class: 'winner', label: '🏆' };
    }

    return null;
}

function roundRobinMedal(participantId) {
    if (! participantId) return null;

    const id = Number(participantId);

    if (Number(roundRobinForm.winner_id_1rd_robbin) === id) {
        return { class: 'gold', label: '1 место' };
    }

    if (Number(roundRobinForm.winner_id_2rd_robbin) === id) {
        return { class: 'silver', label: '2 место' };
    }

    if (Number(roundRobinForm.winner_id_3rd_robbin) === id) {
        return { class: 'bronze', label: '3 место' };
    }

    return null;
}

function poolParticipants(pool) {
    return [pool.student, pool.opponent].filter(Boolean);
}

function kataPoolStudents(pool) {
    return pool.group_students?.length ? pool.group_students : [pool.student].filter(Boolean);
}
</script>

<template>
    <section class="tournament-detail-layout" :class="{ 'bracket-view': activeTab === 'brackets' }">
        <div class="tournament-detail-main">
            <section class="tournament-detail-title">
                <div>
                    <h1>{{ tournament.name }}</h1>
                </div>
                <TournamentDeleteButton v-if="tournament.can_delete" :championship-id="championshipId" :tournament="tournament" :t="t" @done="tournamentDeleted" />
            </section>

            <section v-responsive-tabs class="tournament-detail-tabs">
                <button v-for="tab in tabs" :key="tab.key" type="button" :class="{ active: activeTab === tab.key }" @click="activeTab = tab.key">
                    {{ tab.label }}
                </button>
            </section>

            <section v-if="activeTab === 'overview' && !canMutate" class="tournament-info-grid">
                <article class="tournament-info-card wide">
                    <header><span>1</span><h2>{{ t.basicInformation }}</h2></header>
                    <dl>
                        <div><dt>{{ t.tournamentName }}</dt><dd>{{ tournament.name }}</dd></div>
                        <div><dt>{{ t.region }}</dt><dd>{{ tournament.region || '-' }}</dd></div>
                        <div><dt>{{ t.scale }}</dt><dd>{{ tournament.scale || '-' }}</dd></div>
                        <div><dt>{{ t.ageFrom }}</dt><dd>{{ tournament.age_from }}</dd></div>
                        <div><dt>{{ t.ageTo }}</dt><dd>{{ tournament.age_to }}</dd></div>
                        <div><dt>{{ t.address }}</dt><dd>{{ tournament.address || '-' }}</dd></div>
                    </dl>
                </article>

                <article class="tournament-info-card">
                    <header><span>2</span><h2>{{ t.competitionSettings }}</h2></header>
                    <dl>
                        <div><dt>{{ t.tournamentType }}</dt><dd>{{ tournament.type_label }}</dd></div>
                        <div><dt>{{ t.tatamiCount }}</dt><dd>{{ tournament.tatami }}</dd></div>
                        <div><dt>{{ t.kyUpTo8 }}</dt><dd>{{ tournament.KY_up_to_8 ? t.yes : t.no }}</dd></div>
                        <div><dt>{{ t.kyFrom8 }}</dt><dd>{{ tournament.KY_from_8 ? t.yes : t.no }}</dd></div>
                        <div><dt>{{ t.fightForThirdPlace }}</dt><dd>{{ tournament.fight_for_third_place ? t.yes : t.no }}</dd></div>
                        <div v-if="isOnlineKata"><dt>{{ t.onlineKata }}</dt><dd>{{ t.yes }}</dd></div>
                    </dl>
                </article>

                <article class="tournament-info-card">
                    <header><span>3</span><h2>{{ t.datesAndFee }}</h2></header>
                    <dl>
                        <div><dt>{{ t.commissionDate }}</dt><dd>{{ tournament.date_commission_label || '-' }}</dd></div>
                        <div><dt>{{ t.tournamentDate }}</dt><dd>{{ tournament.date_label || '-' }}</dd></div>
                        <div><dt>{{ t.finishDate }}</dt><dd>{{ tournament.date_finish_label || '-' }}</dd></div>
                        <div><dt>{{ t.participationPrice }}</dt><dd>{{ tournament.price_label || '-' }}</dd></div>
                    </dl>
                </article>

                <article class="tournament-info-card wide">
                    <header><span>4</span><h2>{{ t.reportData }}</h2></header>
                    <dl>
                        <div><dt>{{ t.chiefJudge }}</dt><dd>{{ tournament.chief_judge || '-' }}</dd></div>
                        <div><dt>{{ t.chiefSecretary }}</dt><dd>{{ tournament.chief_secretary || '-' }}</dd></div>
                        <div v-for="document in tournament.documents" :key="document.key">
                            <dt>{{ document.label }}</dt>
                            <dd><a v-if="document.url" :href="document.url" target="_blank">{{ t.download }}</a><span v-else>-</span></dd>
                        </div>
                    </dl>
                </article>
            </section>

            <form v-else-if="activeTab === 'overview'" class="tournament-edit-panel" @submit.prevent="submitOverview">
                <section class="modal-section full">
                    <h3>{{ t.tournamentData }}</h3>
                    <label class="modal-field full"><span>{{ t.tournamentName }}</span><input v-model="editForm.name" required></label>
                    <label class="modal-field"><span>{{ t.region }}</span><select v-model="editForm.region_id" required><option value="">{{ t.selectRegion }}</option><option v-for="region in options.regions" :key="region.id" :value="region.id">{{ region.name }}</option></select></label>
                    <label class="modal-field"><span>{{ t.scale }}</span><select v-model="editForm.scale_id" required><option value="">{{ t.selectScale }}</option><option v-for="scale in options.scales" :key="scale.id" :value="scale.id">{{ scale.name }}</option></select></label>
                    <label class="modal-field"><span>{{ t.ageFrom }}</span><input v-model="editForm.age_from" type="number" min="0" required></label>
                    <label class="modal-field"><span>{{ t.ageTo }}</span><input v-model="editForm.age_to" type="number" min="0" required></label>
                    <label class="modal-field"><span>{{ t.tatamiCount }}</span><input v-model="editForm.tatami" type="number" min="1" required></label>
                    <label class="modal-field"><span>{{ t.participationPrice }}</span><input v-model="editForm.price" type="number" min="0" required></label>
                    <label class="modal-field full"><span>{{ t.address }}</span><input v-model="editForm.address" required></label>
                </section>

                <section class="modal-section full tournament-type-section">
                    <h3>{{ t.tournamentType }}</h3>
                    <div class="tournament-type-grid">
                        <button type="button" :class="{ active: editForm.tournament_type === '1' }" @click="editForm.tournament_type = '1'">{{ t.kumite }}</button>
                        <button type="button" :class="{ active: editForm.tournament_type === '2' }" @click="editForm.tournament_type = '2'">{{ t.kata }}</button>
                    </div>
                    <div v-if="editForm.tournament_type === '2'" class="kata-system-grid">
                        <label><input v-model="editForm.tournament_type_kata" type="radio" value="1"><span>{{ t.flagSystem }}</span></label>
                        <label><input v-model="editForm.tournament_type_kata" type="radio" value="2"><span>{{ t.pointSystem }}</span></label>
                    </div>
                    <label v-if="isEditOnlineKata" class="toggle-row"><input v-model="editForm.is_online_kata" type="checkbox"><span>{{ t.onlineKata }}</span></label>
                    <div class="toggle-grid tournament-toggle-grid">
                        <label><input v-model="editForm.KY_up_to_8" type="checkbox"><span>{{ t.kyUpTo8 }}</span></label>
                        <label><input v-model="editForm.KY_from_8" type="checkbox"><span>{{ t.kyFrom8 }}</span></label>
                        <label><input v-model="editForm.fight_for_third_place" type="checkbox"><span>{{ t.fightForThirdPlace }}</span></label>
                    </div>
                </section>

                <section class="modal-section full">
                    <h3>{{ t.datesAndFee }}</h3>
                    <label class="modal-field"><span>{{ t.commissionDate }}</span><input v-model="editForm.date_commission" type="datetime-local" required></label>
                    <label class="modal-field"><span>{{ t.tournamentDate }}</span><input v-model="editForm.date" type="date" required></label>
                    <label class="modal-field"><span>{{ t.finishDate }}</span><input v-model="editForm.date_finish" type="date" required></label>
                    <label class="modal-field"><span>{{ t.chiefJudge }}</span><input v-model="editForm.chief_judge"></label>
                    <label class="modal-field"><span>{{ t.chiefSecretary }}</span><input v-model="editForm.chief_secretary"></label>
                </section>
                <section class="modal-section full">
                    <label class="tour-application-toggle"><input v-model="editForm.accepts_organization_applications" type="checkbox" /><span>{{ t.tourAllowApplications }}</span></label>
                    <div v-for="asset in assetFields" :key="asset.key" class="modal-field full">
                        <FileDropzone v-model="editForm[asset.key]" :label="asset.label" :accept="asset.accept" :placeholder="t.dropFile" @update:model-value="editForm[`remove_${asset.key}`] = false" />
                        <template v-if="currentAsset(asset.key)"><a :href="currentAsset(asset.key)" target="_blank" rel="noopener">{{ t.tourCurrentFile }}</a><label class="tour-file-remove"><input v-model="editForm[`remove_${asset.key}`]" type="checkbox" @change="editForm[asset.key] = null" />{{ t.tourRemoveFile }}</label></template>
                    </div>
                </section>
                <p v-if="overviewError" role="alert" class="form-error">{{ overviewError }}</p>
                <p v-if="overviewSaved" role="status">{{ t.tourSaved }}</p>
                <footer class="tournament-overview-actions">
                    <button type="submit" class="save-button" :disabled="overviewBusy">{{ t.save }}</button>
                </footer>
            </form>

            <section v-else class="tournament-tab-panel">
                <header class="tournament-tab-toolbar">
                    <div v-if="activeTab === 'students'" class="tournament-student-filters">
                        <div class="table-search">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.8-3.8"/></svg>
                            <input v-model="filters.search" :placeholder="t.studentSearch" @input="emit('filter-students')">
                        </div>
                        <select v-model="filters.coach_id" @change="emit('filter-students')">
                            <option value="">{{ t.allTrainers }}</option>
                            <option v-for="coach in detail.coaches" :key="coach.id" :value="coach.id">{{ coach.name }}</option>
                        </select>
                    </div>
                    <div v-else-if="activeTab === 'coaches'" class="tournament-student-filters">
                        <div class="table-search">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.8-3.8"/></svg>
                            <input v-model="coachTableSearch" :placeholder="t.searchTrainer">
                        </div>
                    </div>
                    <div v-else-if="activeTab === 'lists'" class="tournament-student-filters">
                        <button v-if="selectedList" type="button" class="soft-button" @click="closeListStudents">
                            {{ t.backToLists }}
                        </button>
                        <div class="table-search">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.8-3.8"/></svg>
                            <input
                                v-if="selectedList"
                                v-model="filters.search"
                                :placeholder="t.studentSearch"
                                @input="emit('filter-students')"
                            >
                            <input v-else v-model="listTableSearch" :placeholder="t.searchByName">
                        </div>
                    </div>
                    <div v-else></div>
                    <div class="tournament-toolbar-actions">
                        <StudentTournamentActions v-if="activeTab === 'students'" :tournament="tournament" :championship-id="championshipId" :locale="locale" :t="t" @updated="emit('reload-detail')"/>
                        <a v-if="activeTab === 'lists'" class="file-action-button excel-file" :href="tournament.downloads.lists_excel" target="_blank">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="m9 10 6 7"/><path d="m15 10-6 7"/></svg>
                            {{ t.excel }}
                        </a>
                        <a v-if="activeTab === 'lists'" class="file-action-button pdf-file" :href="tournament.downloads.lists_pdf" target="_blank">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M8 16v-4h1.4a1.2 1.2 0 1 1 0 2.4H8"/><path d="M12 16v-4h1a2 2 0 0 1 0 4h-1"/><path d="M16 16v-4h2"/></svg>
                            {{ t.pdf }}
                        </a>
                        <button v-if="activeTab === 'lists' && !selectedList && canGenerateAllBrackets" type="button" class="soft-button generate-list-button" :disabled="isLoadingBrackets" @click="generateBrackets(null, { stayOnLists: true, forceAll: true })">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4v6h6"/><path d="M20 20v-6h-6"/><path d="M5 19A8 8 0 0 0 18.2 9"/><path d="M19 5A8 8 0 0 0 5.8 15"/></svg>
                            {{ generateAllBracketsLabel }}
                        </button>
                        <button v-if="activeTab === 'students' && canAttachGroupStudents" type="button" class="create-button" @click="showStudentModal = true">
                            {{ t.attachGroupStudents }}
                        </button>
                        <TournamentBulkAction v-if="canMutate && (activeTab === 'coaches' || (activeTab === 'lists' && !selectedList))" :key="activeTab" :items="detail[activeTab] || []" :endpoint="`/api/panel/tournaments/${championshipId}/items/${tournament.id}/${activeTab}/bulk-detach`" :label="activeTab === 'coaches' ? t.tourDetachCoaches : t.tourDetachLists" :confirm="activeTab === 'coaches' ? t.tourDetachCoachesConfirm : t.tourDetachListsConfirm" :locale="locale" :t="t" @done="applicationDone" />
                        <button v-if="activeTab === 'coaches' && canMutate" type="button" class="create-button" @click="showCoachModal = true">{{ t.attachTrainer }}</button>
                        <button v-if="activeTab === 'lists' && canMutate" type="button" class="soft-button" @click="openCreateListModal">{{ t.create }}</button>
                        <button v-if="activeTab === 'lists' && canMutate" type="button" class="create-button" @click="showListModal = true">{{ t.attachList }}</button>
                    </div>
                </header>

                <div v-if="activeTab === 'students'" class="tournament-detail-table compact-participants-table" :class="{ loading: isLoading }">
                    <table v-responsive-table>
                        <thead>
                            <tr>
                                <th class="document-status-cell">{{ t.documents }}</th><th v-if="canConfirmWeight" class="weight-confirmation-cell" :title="t.weightConfirmation">{{ t.weightConfirmationShort }}</th><th>{{ t.fio }}</th><th>{{ t.age }}</th><th>{{ t.weight }}</th><th>{{ t.kyuDan }}</th><th>{{ t.trainer }}</th><th>{{ t.list }}</th>
                                <th v-if="isOnlineKata">{{ t.firstRoundCategory }}</th><th v-if="isOnlineKata">{{ t.firstRoundVideo }}</th><th v-if="isOnlineKata">{{ t.secondRoundCategory }}</th><th v-if="isOnlineKata">{{ t.secondRoundVideo }}</th>
                                <th v-if="canMutate">{{ t.actions }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="student in detail.students.data" :key="student.pivot_id">
                                <td class="document-status-cell">
                                    <span class="status-tooltip" :data-tooltip="documentsStatusTitle(student)" tabindex="0" :aria-label="documentsStatusTitle(student)">
                                        <span class="status-dot" :class="{ ok: student.documents_ok }"></span>
                                    </span>
                                </td>
                                <td v-if="canConfirmWeight" class="weight-confirmation-cell">
                                    <label class="table-checkbox">
                                        <input
                                            type="checkbox"
                                            :checked="student.weight_confirmed"
                                            :disabled="isMutating"
                                            @change="updateTournamentStudent({ student_tournament_id: student.pivot_id, is_success_weight: $event.target.checked })"
                                        >
                                    </label>
                                </td>
                                <td><strong>{{ student.name }}</strong></td>
                                <td>{{ student.age ?? '-' }}</td>
                                <td>{{ student.weight ? `${student.weight} ${t.kg}` : '-' }}</td>
                                <td>{{ student.rang || '-' }}</td>
                                <td>{{ student.coach_name }}</td>
                                <td>{{ student.memberships?.map(item => item.name).join(', ') || student.list_name }}</td>
                                <td v-if="isOnlineKata">{{ student.first_round_category }}</td>
                                <td v-if="isOnlineKata">
                                    <a v-if="student.first_round_video" class="video-status-badge uploaded" :href="student.first_round_video" target="_blank" rel="noopener">{{ t.videoUploaded }}</a>
                                    <span v-else-if="student.first_round_video_uploaded" class="video-status-badge uploaded">{{ t.videoUploaded }}</span>
                                    <span v-else class="video-status-badge missing">{{ t.videoMissing }}</span>
                                </td>
                                <td v-if="isOnlineKata">{{ student.second_round_category }}</td>
                                <td v-if="isOnlineKata">
                                    <a v-if="student.second_round_video" class="video-status-badge uploaded" :href="student.second_round_video" target="_blank" rel="noopener">{{ t.videoUploaded }}</a>
                                    <span v-else-if="student.second_round_video_uploaded" class="video-status-badge uploaded">{{ t.videoUploaded }}</span>
                                    <span v-else class="video-status-badge missing">{{ t.videoMissing }}</span>
                                </td>
                                <td v-if="canMutate">
                                    <div class="table-row-actions">
                                        <a class="table-icon-button" :href="studentProfileUrl(student)" target="_blank" rel="noreferrer" :title="t.view">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                        <button type="button" class="table-icon-button" :title="t.editWeight" @click="openStudentWeightModal(student)">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </button>
                                        <button type="button" class="table-icon-button" :title="t.moveToList" @click="applicationAction = { student, action: 'move' }"><ArrowRightLeft :size="16"/></button>
                                        <button type="button" class="danger-icon-button" :title="t.listDetachApplication" @click="applicationAction = { student, action: 'detach' }"><Unlink :size="16"/></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <PaginationBar v-if="detail.students.meta.total > detail.students.meta.per_page" :meta="detail.students.meta" :of="t.of" :pages="displayedPages" :rows-per-page="t.rowsPerPage" :rows-shown="t.rowsShown" @change-page="emit('change-page', $event)" @change-per-page="emit('change-per-page', $event)" />
                </div>

                <div v-if="activeTab === 'coaches'" class="tournament-detail-table">
                    <table v-responsive-table>
                        <thead><tr><th>{{ t.fio }}</th><th>Email</th><th>{{ t.club }}</th><th v-if="canMutate">{{ t.actions }}</th></tr></thead>
                        <tbody>
                            <tr v-for="coach in paginatedTournamentCoaches" :key="coach.id">
                                <td><strong>{{ coach.name }}</strong></td>
                                <td>{{ coach.email || '-' }}</td>
                                <td>{{ coach.club || '-' }}</td>
                                <td v-if="canMutate"><button type="button" class="danger-icon-button" :disabled="isMutating" @click="detachCoach(coach.id)">×</button></td>
                            </tr>
                            <tr v-if="paginatedTournamentCoaches.length === 0">
                                <td :colspan="canMutate ? 4 : 3" class="empty-cell">{{ t.emptyTeam }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <PaginationBar
                        v-if="coachTableMeta.total > coachTableMeta.per_page"
                        :meta="coachTableMeta"
                        :of="t.of"
                        :pages="displayedCoachPages"
                        :rows-per-page="t.rowsPerPage"
                        :rows-shown="t.rowsShown"
                        @change-page="setCoachTablePage"
                        @change-per-page="setCoachTablePerPage"
                    />
                </div>

                <div v-if="activeTab === 'lists' && !selectedList" class="tournament-detail-table">
                    <table v-responsive-table>
                        <thead><tr><th>{{ t.name }}</th><th>{{ t.listType }}</th><th>{{ t.age }}</th><th>{{ t.weight }}</th><th>{{ t.rank }}</th><th>{{ t.gender }}</th><th>{{ t.participants }}</th><th v-if="canMutate">{{ t.actions }}</th></tr></thead>
                        <tbody>
                            <tr v-for="list in paginatedTournamentLists" :key="list.id" class="clickable-row" @click="openListStudents(list)">
                                <td><strong>{{ list.name }}</strong></td>
                                <td>{{ list.type }}</td>
                                <td>{{ list.age }}</td>
                                <td>{{ list.weight }}</td>
                                <td>{{ list.rank }}</td>
                                <td>{{ list.gender || '-' }}</td>
                                <td>{{ list.students_count }}</td>
                                <td v-if="canMutate">
                                    <div class="table-row-actions list-actions-grid">
                                        <button
                                            type="button"
                                            class="table-action-button generate"
                                            :disabled="isLoadingBrackets"
                                            :title="listBracketsActionLabel(list)"
                                            @click.stop="generateListBrackets(list)"
                                        >
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v5H5z"/><path d="M5 16h6v4H5z"/><path d="M13 16h6v4h-6z"/><path d="M12 9v4"/><path d="M8 13h8"/><path d="M8 13v3"/><path d="M16 13v3"/></svg>
                                            <span>{{ listBracketsActionLabel(list) }}</span>
                                        </button>
                                        <button type="button" class="danger-icon-button" :disabled="isMutating" :title="t.delete" @click.stop="detachList(list.id)">×</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="paginatedTournamentLists.length === 0">
                                <td :colspan="canMutate ? 8 : 7" class="empty-cell">{{ t.emptyTournaments }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <PaginationBar
                        v-if="listTableMeta.total > listTableMeta.per_page"
                        :meta="listTableMeta"
                        :of="t.of"
                        :pages="displayedListPages"
                        :rows-per-page="t.rowsPerPage"
                        :rows-shown="t.rowsShown"
                        @change-page="setListTablePage"
                        @change-per-page="setListTablePerPage"
                    />
                </div>

                <div v-if="activeTab === 'lists' && selectedList" class="tournament-detail-table compact-participants-table" :class="{ loading: isLoading }">
                    <header class="inline-table-title">
                        <div>
                            <span>{{ t.listStudents }}</span>
                            <strong>{{ selectedList.name }}</strong>
                        </div>
                    </header>
                    <table v-responsive-table>
                        <thead>
                            <tr>
                                <th class="document-status-cell">{{ t.documents }}</th>
                                <th v-if="canConfirmWeight" class="weight-confirmation-cell" :title="t.weightConfirmation">{{ t.weightConfirmationShort }}</th>
                                <th>{{ t.fio }}</th>
                                <th>{{ t.age }}</th>
                                <th>{{ t.weight }}</th>
                                <th>{{ t.kyuDan }}</th>
                                <th>{{ t.trainer }}</th>
                                <th v-if="canMutate">{{ t.actions }}</th>
                                <th v-if="canMutate">{{ t.moveToList }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="student in detail.students.data" :key="student.pivot_id">
                                <td class="document-status-cell">
                                    <span class="status-tooltip" :data-tooltip="documentsStatusTitle(student)" tabindex="0" :aria-label="documentsStatusTitle(student)">
                                        <span class="status-dot" :class="{ ok: student.documents_ok }"></span>
                                    </span>
                                </td>
                                <td v-if="canConfirmWeight" class="weight-confirmation-cell">
                                    <label class="table-checkbox">
                                        <input
                                            type="checkbox"
                                            :checked="student.weight_confirmed"
                                            :disabled="isMutating"
                                            @change="updateTournamentStudent({ student_tournament_id: student.pivot_id, is_success_weight: $event.target.checked })"
                                        >
                                    </label>
                                </td>
                                <td><strong>{{ student.name }}</strong></td>
                                <td>{{ student.age ?? '-' }}</td>
                                <td>{{ student.weight ? `${student.weight} ${t.kg}` : '-' }}</td>
                                <td>{{ student.rang || '-' }}</td>
                                <td>{{ student.coach_name }}</td>
                                <td v-if="canMutate">
                                    <div class="table-row-actions">
                                        <a class="table-icon-button" :href="studentProfileUrl(student)" target="_blank" rel="noreferrer" :title="t.view">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                        <button type="button" class="table-icon-button" :title="t.editWeight" @click="openStudentWeightModal(student)">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </button>
                                    </div>
                                </td>
                                <td v-if="canMutate">
                                    <button class="table-icon-button" :title="t.moveToList" @click="applicationAction = { student, action: 'move' }"><ArrowRightLeft :size="16"/></button>
                                    <button class="danger-icon-button" :title="t.listDetachApplication" @click="applicationAction = { student, action: 'detach' }"><Unlink :size="16"/></button>
                                </td>
                            </tr>
                            <tr v-if="detail.students.data.length === 0">
                                <td :colspan="canMutate ? (canConfirmWeight ? 9 : 8) : (canConfirmWeight ? 7 : 6)" class="empty-cell">{{ t.emptyTeam }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <PaginationBar
                        v-if="detail.students.meta.total > detail.students.meta.per_page"
                        :meta="detail.students.meta"
                        :of="t.of"
                        :pages="displayedPages"
                        :rows-per-page="t.rowsPerPage"
                        :rows-shown="t.rowsShown"
                        @change-page="emit('change-page', $event)"
                        @change-per-page="emit('change-per-page', $event)"
                    />
                </div>

                <div v-if="activeTab === 'brackets'" class="tournament-brackets-board" :class="{ loading: isLoadingBrackets }">
                    <header class="bracket-toolbar">
                        <div class="bracket-list-picker">
                            <button v-if="bracketListId" type="button" class="soft-button" @click="closeBracketList">{{ t.backToLists }}</button>
                            <div v-if="bracketListId">
                                <span>{{ t.list }}</span>
                                <strong>{{ selectedBracketList?.name }}</strong>
                            </div>
                        </div>
                        <div class="bracket-actions">
                            <button v-if="canMutate && bracketListId && bracketData?.can_swap && !isPointKataTournament" type="button" class="soft-button bracket-swap-button" :disabled="isLoadingBrackets" @click="showSwapModal = true"><ArrowRightLeft :size="16" />{{ t.fightSwap }}</button>
                            <button v-if="bracketError && bracketListId" type="button" class="soft-button" :disabled="isLoadingBrackets" @click="loadBracketData">{{ t.fightRefresh }}</button>
                            <button v-if="canMutate && (bracketListId || (hasGeneratedBrackets && canGenerateAllBrackets))" type="button" class="create-button" :disabled="isLoadingBrackets || kataBusy" @click="generateBrackets(bracketListId)">
                                {{ bracketListId ? t.regenerateSelected : t.regenerateAll }}
                            </button>
                        </div>
                    </header>

                    <p v-if="bracketError" class="form-error">{{ bracketError }}</p>
                    <section v-else-if="!bracketListId" class="generated-pools-panel">
                        <div class="generated-pools-tools">
                            <div class="generated-pools-filter-group">
                                <div class="table-search compact">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35"/><circle cx="11" cy="11" r="7"/></svg>
                                    <input v-model="bracketListSearch" type="search" :placeholder="t.searchByName">
                                </div>
                                <select v-model="bracketTatamiFilter" class="tatami-filter-select">
                                    <option value="">{{ t.allTatami }}</option>
                                    <option v-for="tatami in bracketTatamiOptions" :key="tatami" :value="tatami">{{ t.tatami }} {{ tatami }}</option>
                                </select>
                            </div>
                            <a v-if="bracketDownloadUrl" class="pdf-download-button" :href="bracketDownloadUrl" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>
                                <span>{{ bracketDownloadLabel }}</span>
                            </a>
                        </div>
                        <div class="generated-pools-list">
                            <div v-for="list in paginatedBracketLists" :key="list.id" class="generated-pool-row">
                                <button type="button" class="generated-pool-open" @click="openBracketList(list)">
                                    <div class="generated-pool-main">
                                        <strong>{{ list.name }}</strong>
                                        <span>{{ t.participants }}: {{ list.students_count }}</span>
                                    </div>
                                </button>
                                <label v-if="canMutate" class="generated-pool-tatami" @click.stop>
                                    <span>{{ t.tatami }}</span>
                                    <select :value="list.tatami || ''" :disabled="isLoadingBrackets" @change="saveListTatami(list, $event.target.value)">
                                        <option value="">—</option>
                                        <option v-for="tatami in bracketTatamiOptions" :key="tatami" :value="tatami">{{ tatami }}</option>
                                    </select>
                                </label>
                                <div v-else class="generated-pool-tatami-readonly">
                                    <span>{{ t.tatami }}</span>
                                    <strong>{{ list.tatami || '—' }}</strong>
                                </div>
                                <div class="generated-pool-progress">
                                    <div>
                                        <span>{{ t.completion }}</span>
                                        <strong>{{ list.completion_percent || 0 }}%</strong>
                                    </div>
                                    <div class="pool-progress-track">
                                        <span :style="{ width: `${list.completion_percent || 0}%` }"></span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="paginatedBracketLists.length === 0" class="empty-cell">{{ t.emptyBrackets }}</div>
                        </div>
                        <PaginationBar
                            v-if="bracketListMeta.total > bracketListMeta.per_page"
                            :meta="bracketListMeta"
                            :of="t.of"
                            :pages="displayedBracketListPages"
                            :rows-per-page="t.rowsPerPage"
                            :rows-shown="t.rowsShown"
                            @change-page="setBracketListPage"
                            @change-per-page="setBracketListPerPage"
                        />
                    </section>

                    <section v-else-if="isPointKataTournament" class="kata-table-shell">
                        <div class="kata-table-toolbar">
                            <div v-if="bracketData?.can_edit_numbers" class="kata-final-actions">
                                <label>
                                    <span>{{ t.finalistsCount }}</span>
                                    <select v-model.number="kataFinalistsCount" :disabled="kataBusy" @change="saveKataFinalistsCount">
                                        <option v-for="count in [4, 5, 6, 7, 8]" :key="count" :value="count">{{ count }}</option>
                                    </select>
                                </label>
                                <button type="button" class="soft-button" :disabled="isLoadingBrackets || kataBusy" @click="generateKataFinal">
                                    {{ hasKataFinal ? t.regenerateFinal : t.generateFinal }}
                                </button>
                                <button type="button" class="save-button" :disabled="isLoadingBrackets || !hasKataFinal || kataBusy" @click="generateKataWinners">
                                    {{ t.regenerateWinners }}
                                </button>
                            </div>
                            <a v-if="kataTableDownloadUrl" class="pdf-download-button" :href="kataTableDownloadUrl" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>
                                <span>{{ t.pdf }}</span>
                            </a>
                        </div>
                        <p v-if="kataActionError" class="form-error" role="alert">{{ kataActionError }} <button type="button" class="soft-button" :disabled="kataBusy" @click="refreshKata">{{ t.kataRefresh }}</button></p>
                        <article v-for="table in [{ key: 'pre', title: t.preliminaryStage }, { key: 'final', title: t.finalStage }]" :key="table.key" class="kata-score-table">
                            <header>
                                <h3>{{ table.title }}</h3>
                                <span>{{ selectedBracketList?.name }}</span>
                            </header>
                            <table v-responsive-table>
                                <thead>
                                    <tr>
                                        <th>{{ t.numberShort }}</th>
                                        <th>{{ t.participant }}</th>
                                        <th v-for="field in kataScoreFields" :key="field">{{ t[field] }}</th>
                                        <th>{{ t.total }}</th>
                                        <th>{{ t.place }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="pool in kataTables[table.key]" :key="pool.id">
                                        <tr v-for="student in kataPoolStudents(pool)" :key="`${pool.id}-${student.id}`">
                                            <td>
                                                <input
                                                    v-model="pool.participant_number"
                                                    class="compact-score-input"
                                                    :disabled="!bracketData?.can_edit_numbers"
                                                    @change="updateKataNumber(pool)"
                                                >
                                            </td>
                                            <td>
                                                <button
                                                    type="button"
                                                    class="kata-participant-button"
                                                    :class="{ active: canOpenOnlineKataModal(student, pool) }"
                                                    :disabled="!canOpenOnlineKataModal(student, pool)"
                                                    @click="openOnlineKataModal(student, pool)"
                                                >
                                                    {{ participantName(student) }}
                                                </button>
                                                <small>{{ student.club || student.coach_club || '-' }}</small>
                                                <button
                                                    v-if="table.key === 'final' && student.can_upload_final_video"
                                                    type="button"
                                                    class="kata-final-video-state"
                                                    @click="openOnlineKataModal(student, pool)"
                                                >
                                                    {{ student.final_video_uploaded ? t.changeFinalVideo : t.uploadFinalVideo }}
                                                </button>
                                            </td>
                                            <td v-for="field in kataScoreFields" :key="field">
                                                <KataScoreCell :key="`${pool.id}-${student.id}-${field}-${kataRefreshVersion}`" :value="pool[field]" :disabled="!bracketData?.editable_score_fields?.includes(field)" :label="`${t[field]}: ${student ? participantName(student) : pool.id}`" :save="(value, original) => updateKataScore(pool, field, value, original)" />
                                            </td>
                                            <td><strong>{{ pool.total_score ?? '-' }}</strong></td>
                                            <td>{{ pool.rank ?? '-' }}</td>
                                        </tr>
                                    </template>
                                    <tr v-if="!kataTables[table.key]?.length">
                                        <td :colspan="9" class="empty-cell">{{ t.emptyBrackets }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </article>
                        <article v-if="kataResults.length" class="kata-results-card">
                            <header>
                                <h3>{{ t.results }}</h3>
                                <span>{{ selectedBracketList?.name }}</span>
                            </header>
                            <div class="kata-results-table">
                                <div v-for="result in kataResults" :key="result.place" class="kata-result-row">
                                    <strong>{{ result.place }}.</strong>
                                    <span>{{ result.name }}</span>
                                    <b>{{ result.total }}</b>
                                </div>
                            </div>
                        </article>
                    </section>

                    <section v-else class="kumite-bracket-shell">
                        <article v-if="roundRobinPools.length" class="round-robin-panel">
                            <header>
                                <h3>{{ t.roundRobin }}</h3>
                                <button v-if="canMutate" type="button" class="save-button" :disabled="roundRobinSaving" @click="saveRoundRobinWinners">{{ t.save }}</button>
                            </header>
                            <div class="round-robin-fights">
                                <div v-for="pool in roundRobinPools" :key="pool.id" class="round-robin-fight-row">
                                    <div class="round-robin-fight-meta">
                                        <strong>{{ t.fight }} #{{ pool.position_in_round || pool.id }}</strong>
                                        <input
                                            v-model="pool.tatami_and_fight_number"
                                            class="round-robin-tatami-input"
                                            :placeholder="t.tatami"
                                            :disabled="!canMutate"
                                            @change="savePoolTatami(pool)"
                                        >
                                    </div>
                                    <div class="round-robin-fight-participants">
                                        <div
                                            v-for="side in [{ key: 'student', absent: 'absent_student' }, { key: 'opponent', absent: 'absent_opponent' }]"
                                            :key="side.key"
                                            class="round-robin-participant"
                                            :class="{ editable: canMutate && pool[side.key], winner: Boolean(pool.winner_id) && Number(pool.winner_id) === Number(pool[`${side.key}_id`]), absent: pool[side.absent] }"
                                            :role="canMutate && pool[side.key] ? 'button' : null"
                                            :tabindex="canMutate && pool[side.key] ? 0 : null"
                                            @click="pool[side.key] && openPoolResultModal(pool, pool[`${side.key}_id`])"
                                            @keydown.enter.prevent="pool[side.key] && openPoolResultModal(pool, pool[`${side.key}_id`])"
                                            @keydown.space.prevent="pool[side.key] && openPoolResultModal(pool, pool[`${side.key}_id`])"
                                        >
                                            <span
                                                class="bracket-seed"
                                                :class="{ active: Boolean(pool.winner_id) && Number(pool.winner_id) === Number(pool[`${side.key}_id`]) }"
                                            ></span>
                                            <div>
                                                <strong>{{ participantName(pool[side.key]) }}</strong>
                                                <small>{{ participantMeta(pool[side.key]) || '-' }}</small>
                                            </div>
                                            <span
                                                v-if="participantMedal(pool, pool[`${side.key}_id`])"
                                                class="bracket-medal"
                                                :class="participantMedal(pool, pool[`${side.key}_id`]).class"
                                            >{{ participantMedal(pool, pool[`${side.key}_id`]).label }}</span>
                                            <span
                                                v-if="roundRobinMedal(pool[`${side.key}_id`])"
                                                class="round-robin-place-arrow"
                                                :class="roundRobinMedal(pool[`${side.key}_id`]).class"
                                            >→ {{ roundRobinMedal(pool[`${side.key}_id`]).label }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="round-robin-winners">
                                <label>
                                    <span>{{ t.firstPlace }}</span>
                                    <select v-model="roundRobinForm.winner_id_1rd_robbin" :disabled="!canMutate || roundRobinSaving">
                                        <option value="">{{ t.selectParticipant }}</option>
                                        <option v-for="participant in roundRobinOptions('winner_id_1rd_robbin')" :key="participant.id" :value="participant.id">{{ participantName(participant) }}</option>
                                    </select>
                                </label>
                                <label>
                                    <span>{{ t.secondPlace }}</span>
                                    <select v-model="roundRobinForm.winner_id_2rd_robbin" :disabled="!canMutate || roundRobinSaving">
                                        <option value="">{{ t.selectParticipant }}</option>
                                        <option v-for="participant in roundRobinOptions('winner_id_2rd_robbin')" :key="participant.id" :value="participant.id">{{ participantName(participant) }}</option>
                                    </select>
                                </label>
                                <label>
                                    <span>{{ t.thirdPlace }}</span>
                                    <select v-model="roundRobinForm.winner_id_3rd_robbin" :disabled="!canMutate || roundRobinSaving">
                                        <option value="">{{ t.selectParticipant }}</option>
                                        <option v-for="participant in roundRobinOptions('winner_id_3rd_robbin')" :key="participant.id" :value="participant.id">{{ participantName(participant) }}</option>
                                    </select>
                                </label>
                            </div>
                        </article>

                        <article v-if="bracketRounds.length" class="bracket-tree-card">
                            <nav class="mobile-bracket-controls" :aria-label="t.mobileRound">
                                <button type="button" :aria-label="t.mobilePreviousRound" :disabled="mobileRound === 0" @click="changeMobileRound(mobileRound - 1)"><ChevronLeft :size="20"/></button>
                                <select :value="Math.min(mobileRound, bracketRounds.length - 1)" :aria-label="t.mobileRound" @change="changeMobileRound(Number($event.target.value))"><option v-for="(round, index) in bracketRounds" :key="index" :value="index">{{ round.title }}</option></select>
                                <button type="button" :aria-label="t.mobileNextRound" :disabled="mobileRound >= bracketRounds.length - 1" @click="changeMobileRound(mobileRound + 1)"><ChevronRight :size="20"/></button>
                            </nav>
                            <div
                                class="bracket-tree"
                                @touchstart.passive="touchStart = { x: $event.touches[0].clientX, y: $event.touches[0].clientY }"
                                @touchend.passive="endRoundSwipe"
                                :class="{ 'has-third-place': thirdPlacePools.length }"
                                :style="{
                                    '--round-count': Math.max(bracketRounds.length, 1),
                                    '--bracket-row-count': bracketRowCount,
                                    gridTemplateColumns: bracketGridTemplateColumns,
                                }"
                            >
                                <section
                                    v-for="(round, roundIndex) in bracketRounds"
                                    :key="round.title"
                                    class="bracket-column"
                                    :class="{
                                        'mobile-round-active': roundIndex === Math.min(mobileRound, bracketRounds.length - 1),
                                        'final-column': roundIndex === bracketRounds.length - 1,
                                        'third-place-host': thirdPlacePools.length && roundIndex === Math.max(bracketRounds.length - 2, 0),
                                    }"
                                    :style="{ '--match-count': Math.max(round.pools.length, 1) }"
                                >
                                    <h3>{{ round.title }}</h3>
                                    <div class="bracket-column-matches">
                                        <div
                                            v-for="pool in round.pools"
		                                            :key="pool.id"
		                                            class="bracket-match-slot"
		                                            :class="{
                                                        'connects-prev': roundIndex > 0,
                                                        'connects-next': roundIndex < bracketRounds.length - 1,
                                                        'connects-pair': shouldDrawPairConnector(pool, roundIndex),
                                                    }"
		                                            :style="{ gridRow: poolGridRow(pool) }"
		                                        >
                                            <span v-if="shouldDrawPairConnector(pool, roundIndex)" class="bracket-pair-connector"></span>
                                            <div
                                                class="bracket-match"
                                                :class="{ resolved: pool.winner_id, final: pool.type === 'final' }"
                                            >
	                                            <header>
	                                                <span>{{ t.fight }} #{{ pool.position_in_round || pool.id }}</span>
	                                                <input
                                                    v-model="pool.tatami_and_fight_number"
                                                    :placeholder="t.tatami"
                                                    :disabled="!canMutate"
                                                    @change="savePoolTatami(pool)"
                                                >
                                            </header>
                                            <div class="bracket-match-participants">
                                                <div
                                                    v-for="side in [{ key: 'student', absent: 'absent_student' }, { key: 'opponent', absent: 'absent_opponent' }]"
                                                    :key="side.key"
                                                    class="bracket-participant"
                                                    :class="{ winner: Boolean(pool.winner_id) && Number(pool.winner_id) === Number(pool[`${side.key}_id`]), absent: pool[side.absent] }"
                                                    @click="pool[side.key] && openPoolResultModal(pool, pool[`${side.key}_id`])"
                                                >
                                                    <span class="bracket-seed" :class="{ active: Boolean(pool.winner_id) && Number(pool.winner_id) === Number(pool[`${side.key}_id`]) }"></span>
                                                    <div>
                                                        <strong>{{ participantName(pool[side.key]) }}</strong>
                                                        <small>{{ participantMeta(pool[side.key]) || '-' }}</small>
                                                    </div>
	                                                    <span
	                                                        v-if="participantMedal(pool, pool[`${side.key}_id`])"
	                                                        class="bracket-medal"
	                                                        :class="participantMedal(pool, pool[`${side.key}_id`]).class"
	                                                    >{{ participantMedal(pool, pool[`${side.key}_id`]).label }}</span>
                                                </div>
                                            </div>
                                            </div>
                                        </div>

                                    <div
                                        v-if="thirdPlacePools.length && roundIndex === Math.max(bracketRounds.length - 2, 0)"
                                        class="bracket-third-place-card"
                                    >
                                        <h3 class="bracket-third-label">{{ t.thirdPlace }}</h3>
                                        <div
                                            v-for="pool in thirdPlacePools"
                                            :key="`third-${pool.id}`"
                                            class="bracket-match third-place"
                                            :class="{ resolved: pool.winner_id }"
                                        >
                                            <header>
                                                <span>{{ t.fight }} #{{ pool.position_in_round || pool.id }}</span>
                                                <input v-model="pool.tatami_and_fight_number" :placeholder="t.tatami" :disabled="!canMutate" @change="savePoolTatami(pool)">
                                            </header>
                                            <div class="bracket-match-participants">
                                                <div
                                                    v-for="side in [{ key: 'student', absent: 'absent_student' }, { key: 'opponent', absent: 'absent_opponent' }]"
                                                    :key="side.key"
                                                    class="bracket-participant"
                                                    :class="{ winner: Boolean(pool.winner_id) && Number(pool.winner_id) === Number(pool[`${side.key}_id`]), absent: pool[side.absent] }"
                                                    @click="pool[side.key] && openPoolResultModal(pool, pool[`${side.key}_id`])"
                                                >
                                                    <span class="bracket-seed" :class="{ active: Boolean(pool.winner_id) && Number(pool.winner_id) === Number(pool[`${side.key}_id`]) }"></span>
                                                    <div>
                                                        <strong>{{ participantName(pool[side.key]) }}</strong>
                                                        <small>{{ participantMeta(pool[side.key]) || '-' }}</small>
                                                    </div>
                                                    <span
                                                        v-if="participantMedal(pool, pool[`${side.key}_id`])"
                                                        class="bracket-medal"
                                                        :class="participantMedal(pool, pool[`${side.key}_id`]).class"
                                                    >{{ participantMedal(pool, pool[`${side.key}_id`]).label }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    </div>
                                </section>
                            </div>

                            <div v-if="bracketPodium.length" class="bracket-podium">
                                <div v-for="item in bracketPodium" :key="`${item.place}-${item.participant.id}`" class="bracket-podium-item" :class="item.place">
                                    <span>{{ item.medal }}</span>
                                    <div>
                                        <small>{{ item.label }}</small>
                                        <strong>{{ participantName(item.participant) }}</strong>
                                    </div>
                                </div>
                            </div>
                        </article>

                        <div v-if="!bracketPools.length" class="empty-cell">{{ t.emptyBrackets }}</div>
                    </section>
                </div>
            </section>
        </div>

        <aside v-if="activeTab !== 'brackets'" class="tournament-detail-side">
            <section class="tournament-downloads-panel">
                <div class="downloads-card">
                    <h2>{{ t.downloads }}</h2>
                    <a v-for="item in downloads" :key="item.key" :href="tournament.downloads[item.key]" target="_blank" :data-tone="item.tone">
                        <span>{{ item.label }}</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                    </a>
                </div>
                <img class="tournament-side-art" :src="sideArt" alt="">
            </section>
        </aside>
    </section>

    <div v-if="showCoachModal && canMutate" class="modal-backdrop">
        <form class="template-modal team-member-modal" @submit.prevent="submitCoaches">
            <header><h2>{{ t.attachTrainer }}</h2><button type="button" class="panel-icon-button" @click="showCoachModal = false">×</button></header>
            <TournamentOptionPicker :locale="locale" v-model="coachForm.ids" :url="bracketBasePath() + '/attach-options/coaches'" :t="t"/>
            <footer><button type="button" class="soft-button" :disabled="isMutating" @click="showCoachModal = false">{{ t.cancel }}</button><button type="submit" class="save-button" :disabled="isMutating">{{ t.add }}</button></footer>
        </form>
    </div>

    <TournamentStudentPicker v-if="showStudentModal && canAttachGroupStudents" :championship-id="championshipId" :tournament="tournament" :t="t" :locale="locale" @close="showStudentModal = false" @done="applicationDone"/>
    <TournamentApplicationModal v-if="applicationAction" :student="applicationAction.student" :action="applicationAction.action" :championship-id="championshipId" :tournament="tournament" :lists="detail.lists" :initial-list="selectedList?.id" :t="t" :locale="locale" @close="applicationAction = null" @done="applicationDone"/>

    <div v-if="showListModal && canMutate" class="modal-backdrop">
        <form class="template-modal team-member-modal" @submit.prevent="submitLists">
            <header><h2>{{ t.attachList }}</h2><button type="button" class="panel-icon-button" @click="showListModal = false">×</button></header>
            <TournamentOptionPicker v-model="listForm.ids" :url="bracketBasePath() + '/attach-options/lists'" :t="t"/>
            <footer><button type="button" class="soft-button" :disabled="isMutating" @click="showListModal = false">{{ t.cancel }}</button><button type="submit" class="save-button" :disabled="isMutating">{{ t.add }}</button></footer>
        </form>
    </div>

    <TemplateModal
        v-if="showCreateListModal && canMutate"
        :editing-template="null"
        :submit-label="t.create"
        :template-error="''"
        :template-form="createListForm"
        :t="t"
        :is-saving="isMutating"
        :visible-form-fields="createListVisibleFields"
        @close="showCreateListModal = false"
        @save="submitCreateList"
    />

    <div v-if="showStudentWeightModal && canMutate" class="modal-backdrop">
        <form class="template-modal confirm-modal" @submit.prevent="submitStudentWeight">
            <header>
                <h2>{{ t.editWeight }}</h2>
                <button type="button" class="panel-icon-button" @click="closeStudentWeightModal">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>
            <p>{{ studentWeightForm.name }}</p>
            <template v-if="canConfirmWeight && studentWeightMemberships.length > 1">
                <label v-for="membership in studentWeightMemberships" :key="membership.id" class="application-option">
                    <input v-model="studentWeightForm.membership_id" type="radio" :value="membership.id" required>
                    <span>{{ membership.name }}</span>
                </label>
            </template>
            <label class="modal-field">
                <span>{{ t.weight }}</span>
                <input v-model="studentWeightForm.weight" type="number" min="0" max="300" step="0.1" required>
            </label>
            <footer>
                <button type="button" class="soft-button" @click="closeStudentWeightModal">{{ t.cancel }}</button>
                <button type="submit" class="save-button" :disabled="isMutating || (canConfirmWeight && studentWeightMemberships.length > 1 && !studentWeightForm.membership_id)">{{ t.save }}</button>
            </footer>
        </form>
    </div>

    <div v-if="confirmation" class="modal-backdrop">
        <form class="template-modal confirm-modal" @submit.prevent="confirmPendingAction">
            <header>
                <h2>{{ confirmation.title }}</h2>
                <button type="button" class="panel-icon-button" :disabled="isConfirming" @click="closeConfirmation">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>
            <p>{{ confirmation.message }}</p>
            <footer>
                <button type="button" class="soft-button" :disabled="isConfirming" @click="closeConfirmation">{{ t.cancel }}</button>
                <button type="submit" class="danger-button" :disabled="isConfirming">{{ confirmation.confirmLabel }}</button>
            </footer>
        </form>
    </div>

    <BracketResultModal v-if="selectedResultPool" :pool="selectedResultPool" :initial-winner="initialResultWinner" :revision="bracketData.revision" :base-path="bracketBasePath()" :t="t" :locale="locale" @close="selectedResultPool = null" @done="fightMutationDone" />
    <BracketSwapModal v-if="showSwapModal" :bracket="bracketData" :list-id="bracketListId" :base-path="bracketBasePath()" :t="t" :locale="locale" @close="showSwapModal = false" @done="fightMutationDone" />

    <div v-if="selectedOnlineKataStudent" class="modal-backdrop">
        <form class="template-modal online-kata-video-modal" @submit.prevent="uploadKataFinalVideo(selectedOnlineKataPool, selectedOnlineKataStudent)">
            <header>
                <h2>{{ participantName(selectedOnlineKataStudent) }}</h2>
                <button type="button" class="panel-icon-button" @click="closeOnlineKataModal">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </header>
            <div class="online-kata-student-meta">
                <span>{{ selectedOnlineKataStudent.club || selectedOnlineKataStudent.coach_club || '-' }}</span>
                <span>{{ kataStudentMeta(selectedOnlineKataStudent) }}</span>
                <span>{{ selectedOnlineKataStudent.active_online_application?.category_name || t.category }}</span>
            </div>
            <video
                v-if="selectedOnlineKataStudent.active_online_application?.video_url"
                controls
                playsinline
                class="online-kata-video"
                :src="selectedOnlineKataStudent.active_online_application.video_url"
            ></video>
            <p v-else class="empty-cell">{{ t.videoMissing }}</p>
            <div v-if="selectedOnlineKataStudent.can_upload_final_video" class="online-kata-upload-fields">
                <label class="modal-field">
                    <span>{{ t.category }}</span>
                    <select v-model="finalVideoCategoryId" required>
                        <option v-for="category in bracketData?.education_categories || []" :key="category.id" :value="category.id">{{ category.name }}</option>
                    </select>
                </label>
                <label class="video-upload-control">
                    <input type="file" accept="video/*" required @change="setFinalVideoFile">
                    <span class="video-upload-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                    </span>
                    <span class="video-upload-text">
                        <strong>{{ selectedOnlineKataStudent.final_video_uploaded ? t.changeFinalVideo : t.uploadFinalVideo }}</strong>
                        <small>{{ finalVideoFileName }}</small>
                    </span>
                </label>
                <p v-if="finalVideoError" class="form-error">{{ finalVideoError }}</p>
            </div>
            <footer>
                <button type="button" class="soft-button" @click="closeOnlineKataModal">{{ t.cancel }}</button>
                <button v-if="selectedOnlineKataStudent.can_upload_final_video" type="submit" class="save-button" :disabled="finalVideoSaving || !finalVideoFile">
                    {{ selectedOnlineKataStudent.final_video_uploaded ? t.changeFinalVideo : t.uploadFinalVideo }}
                </button>
            </footer>
        </form>
    </div>
</template>
<style>
.tour-application-toggle { grid-column:1 / -1; }
.tour-application-toggle, .modal-field .tour-file-remove { display:flex; align-items:center; gap:8px; font-size:13px; }
.tour-application-toggle input[type=checkbox], .modal-field .tour-file-remove input[type=checkbox] { width:16px; height:16px; min-height:16px; flex:0 0 16px; margin:0; padding:0; accent-color:#b4242b; }
</style>
