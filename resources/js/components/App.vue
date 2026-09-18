<script setup>
import { taskLabels } from '../i18n/tasks';
import { defineAsyncComponent } from 'vue';
import { adminLabels } from '../i18n/admin';
import LandingPage from '../pages/LandingPage.vue';
const AdminWorkspace = defineAsyncComponent(() => import('../pages/admin/AdminWorkspace.vue'));
import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue';
import '../styles/account.css';
import OnlineKataReturnPage from '../pages/OnlineKataReturnPage.vue';
import PasswordRecoveryPage from '../pages/PasswordRecoveryPage.vue';
import AccountProfilePage from '../pages/panel/AccountProfilePage.vue';
import StudentAccountPage from '../pages/panel/StudentAccountPage.vue';
import StudentEducationPage from '../pages/panel/StudentEducationPage.vue';
import { studentLabels } from '../i18n/student';
import { rememberAgreementReturn, consumeAgreementReturn } from '../composables/agreementReturn';
import AccountDashboardPage from '../pages/panel/AccountDashboardPage.vue';
import AccountNotificationsPage from '../pages/panel/AccountNotificationsPage.vue';
import AccountAgreementsPage from '../pages/panel/AccountAgreementsPage.vue';
import PanelTaskPage from '../pages/panel/PanelTaskPage.vue';
import PanelLayout from '../layouts/PanelLayout.vue';
import ExternalFormPage from '../pages/ExternalFormPage.vue';
import ExternalFormEditorPage from '../pages/panel/ExternalFormEditorPage.vue';
import LoginPage from '../pages/LoginPage.vue';
import TrainerRegistrationPage from '../pages/TrainerRegistrationPage.vue';
import AboutPage from '../pages/panel/AboutPage.vue';
import ExaminationDetailPage from '../pages/panel/ExaminationDetailPage.vue';
import ExaminationsPage from '../pages/panel/ExaminationsPage.vue';
import OrganizationSettingsPage from '../pages/panel/OrganizationSettingsPage.vue';
import RatingPage from '../pages/panel/RatingPage.vue';
import StudentDetailPage from '../pages/panel/StudentDetailPage.vue';
import TeamPage from '../pages/panel/TeamPage.vue';
import TemplatesPage from '../pages/panel/TemplatesPage.vue';
import TrainerDetailPage from '../pages/panel/TrainerDetailPage.vue';
import TournamentDetailPage from '../pages/panel/TournamentDetailPage.vue';
import OrganizationApplicationsPage from '../pages/panel/OrganizationApplicationsPage.vue';
import TournamentsPage from '../pages/panel/TournamentsPage.vue';
import { translations } from '../i18n/translations';

const requestedLocale = new URLSearchParams(window.location.search).get('locale');
const savedLocale = ['ru', 'en'].includes(requestedLocale) ? requestedLocale : localStorage.getItem('kr-locale');
const savedTheme = localStorage.getItem('kr-panel-theme');
const locale = ref(savedLocale === 'en' ? 'en' : 'ru');
watch(locale, value => { document.cookie = `kr-locale=${value}; Path=/; SameSite=Lax; Max-Age=31536000`; }, { immediate: true });
const theme = ref(savedTheme === 'dark' ? 'dark' : 'light');
const currentPath = ref(window.location.pathname);
const authUser = ref(null);
const isLoadingUser = ref(false);
const isSubmitting = ref(false);
const formError = ref('');
const toast = ref(null);
let toastTimer = null;
const logoUrl = '/assets/auth/kr.jpg';
const fightersUrl = '/assets/auth/login-fighters.png';
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const t = computed(() => translations[locale.value]);
const isKataReturn = computed(() => currentPath.value === '/online-kata/payment/complete');
const isRegistration = computed(() => /^\/(?:panel|student)\/register\/?$/.test(currentPath.value));
const isPasswordRecovery = computed(() => ['/forgot-password', '/reset-password'].includes(currentPath.value));
const isPanel = computed(() => currentPath.value.startsWith('/panel') && !isRegistration.value && currentPath.value !== '/panel/login');
const adminSection = computed(() => currentPath.value.match(/^\/panel\/admin\/(feed|education|agreements|organizations|regions|scales|activity)$/)?.[1] ?? null);
const isAdmin = computed(() => Boolean(authUser.value?.capabilities?.super_admin));
const externalFormToken = computed(() => {
    const match = currentPath.value.match(/^\/external-form\/([^/]+)/);

    return match ? match[1] : null;
});
const isDark = computed(() => theme.value === 'dark');
const panelSection = computed(() => {
    if (adminSection.value) return 'admin-' + adminSection.value;
    if (currentPath.value === '/panel/education') return 'education';
    if (currentPath.value.startsWith('/panel/tasks/')) return 'tasks';
    for (const section of ['dashboard', 'profile', 'notifications', 'documents']) {
        if (currentPath.value === '/panel/' + section || currentPath.value.startsWith('/panel/' + section + '/')) return section;
    }
    if (currentPath.value.startsWith('/panel/agreement-doc/')) return 'documents';
    if (currentPath.value.includes('/panel/about')) return 'about';
    if (currentPath.value.includes('/panel/tournaments')) return 'tournaments';
    if (currentPath.value.includes('/panel/team')) return 'team';
    if (currentPath.value.includes('/panel/exams')) return 'exams';
    if (currentPath.value.includes('/panel/rating')) return 'rating';
    if (currentPath.value.includes('/panel/settings')) return 'settings';

    return 'templates';
});
const panelTitle = computed(() => {
    if (adminSection.value) return adminLabels[locale.value][adminSection.value];
    if (panelSection.value === 'education') return studentLabels[locale.value].education;
    if (panelSection.value === 'tasks') return taskLabels[locale.value].title;
    if (currentExternalFormId.value) return t.value.formEditor;
    const accountTitles = { dashboard: t.value.dashboard, profile: t.value.accountProfile, notifications: t.value.accountNotifications, documents: t.value.accountAgreements };
    if (accountTitles[panelSection.value]) return accountTitles[panelSection.value];
    if (panelSection.value === 'about') return t.value.about;
    if (panelSection.value === 'tournaments') return currentTournament.value?.name ?? currentChampionship.value?.name ?? t.value.tournaments;
    if (panelSection.value === 'team') return currentTeamStudent.value?.full_name ?? currentTeamTrainer.value?.full_name ?? t.value.team;
    if (panelSection.value === 'exams') return currentExam.value?.name ?? t.value.exams;
    if (panelSection.value === 'rating') return t.value.ratingNav;
    if (panelSection.value === 'settings') return t.value.settings;

    return t.value.templates;
});
const authRoleNames = computed(() => authUser.value?.roles ?? []);
const panelCapabilities = computed(() => authUser.value?.capabilities ?? {});
const isStudent = computed(() => hasAnyRole(['Student']));
const canViewAbout = computed(() => hasAnyRole(['Organization', 'Secretary', 'Student']));
const canManageOrganizationSettings = computed(() => hasAnyRole(['Organization', 'Secretary']));
const currentExamId = computed(() => {
    const match = currentPath.value.match(/^\/panel\/exams\/(\d+)/);

    return match ? Number(match[1]) : null;
});
const currentChampionshipId = computed(() => {
    const match = currentPath.value.match(/^\/panel\/tournaments\/(\d+)/);

    return match ? Number(match[1]) : null;
});
const currentTournamentId = computed(() => {
    const match = currentPath.value.match(/^\/panel\/tournaments\/\d+\/items\/(\d+)/);

    return match ? Number(match[1]) : null;
});
const currentExternalFormId = computed(() => Number(currentPath.value.match(/^\/panel\/tournaments\/\d+\/forms\/(\d+)/)?.[1]) || null);
const isTournamentEdit = computed(() => /^\/panel\/tournaments\/\d+\/items\/\d+\/edit$/.test(currentPath.value));
const currentTeamStudentId = computed(() => {
    const match = currentPath.value.match(/^\/panel\/team\/students\/(\d+)/);

    return match ? Number(match[1]) : null;
});
const currentTeamTrainerId = computed(() => {
    const match = currentPath.value.match(/^\/panel\/team\/trainers\/(\d+)/);

    return match ? Number(match[1]) : null;
});

const filters = reactive({
    list_type: '',
    kata_type: '',
    gender: '',
    age_from: '',
    age_to: '',
    weight_from: '',
    weight_to: '',
    rang_from: '',
    rang_to: '',
    search: '',
});

const templateLists = ref([]);
const listMeta = ref({ current_page: 1, last_page: 1, per_page: 6, total: 0, from: 0, to: 0 });
const listStats = ref({ total: 0, active_filters: 0, kata: 0 });
const isLoadingLists = ref(false);
const showTemplateModal = ref(false);
const editingTemplate = ref(null);
const templateError = ref('');
const draggedTemplateId = ref(null);
const showAllTemplates = ref(false);
const aboutContent = ref(null);
const isLoadingAbout = ref(false);
const organizationSettings = ref(null);
const organizationSettingsForm = reactive({ can_edit_students: true, can_edit_coaches: true });
const isLoadingOrganizationSettings = ref(false);
const isSavingOrganizationSettings = ref(false);
const organizationSettingsError = ref('');
const isHydratingOrganizationSettings = ref(false);
const organizationSettingsSavePending = ref(false);
let organizationSettingsSaveTimer = null;
const teamRows = ref([]);
const teamStats = ref({ judges: 0, secretaries: 0, trainers: 0, students: 0, pending: 0 });
const teamMeta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0, from: 0, to: 0 });
const initialTeamSection = new URLSearchParams(window.location.search).get('section');
const teamSection = ref(['trainers', 'students', 'pending', 'judges', 'secretaries'].includes(initialTeamSection) ? initialTeamSection : 'trainers');
const teamSearch = ref('');
const teamSort = ref('name');
const isLoadingTeam = ref(false);
const isLoadingTeamStudent = ref(false);
const isLoadingTeamTrainer = ref(false);
const teamStudentDetail = ref(null);
const currentTeamStudent = computed(() => teamStudentDetail.value?.student ?? null);
const teamTrainerDetail = ref(null);
const currentTeamTrainer = computed(() => teamTrainerDetail.value?.trainer ?? null);
const trainerStudentFilters = reactive({ tournament_id: '', per_page: 10 });
const showTeamMemberModal = ref(false);
const editingTeamMember = ref(null);
const teamMemberError = ref('');
const showTrainerInviteModal = ref(false);
const trainerInviteError = ref('');
const isSendingTrainerInvite = ref(false);
const exams = ref([]);
const examStats = ref({ total: 0, planned: 0, completed: 0, students: 0 });
const examMeta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0, from: 0, to: 0 });
const examCities = ref([]);
const examCoaches = ref([]);
const currentExam = ref(null);
const examStudents = ref([]);
const examStudentsMeta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0, from: 0, to: 0 });
const isLoadingExams = ref(false);
const isLoadingExamStudents = ref(false);
const showExamModal = ref(false);
const editingExam = ref(null);
const examError = ref('');
const examStudentSearch = ref('');
const examSelectedCoach = ref('');
const showAttachStudentsModal = ref(false);
const attachOptions = ref([]);
const selectedAttachIds = ref([]);
const ratingResult = ref(null);
const isLoadingRating = ref(false);
const tournaments = ref([]);
const tournamentForms = ref([]);
const tournamentCreateOptions = ref({ regions: [], scales: [] });
const tournamentStats = ref({ total: 0, active: 0, completed: 0, this_month: 0 });
const tournamentMeta = ref({ current_page: 1, last_page: 1, per_page: 6, total: 0, from: 0, to: 0 });
const tournamentRegions = ref([]);
const currentChampionship = ref(null);
const currentTournament = ref(null);
const tournamentDetail = ref(blankTournamentDetail());
const isLoadingTournaments = ref(false);
const showChampionshipModal = ref(false);
const championshipError = ref('');
const showTournamentFormModal = ref(false);
const showTournamentItemModal = ref(false);
const tournamentFormError = ref('');
const tournamentItemError = ref('');
const championshipForm = reactive({ name: '', banner: null });
const tournamentForm = reactive({ id: null, organization_name: '' });
let filtersTimer;
let teamSearchTimer;
let examFiltersTimer;
let examStudentSearchTimer;
let ratingFiltersTimer;
let tournamentFiltersTimer;

function blankTemplateForm() {
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

const templateForm = reactive(blankTemplateForm());

function blankTeamMemberForm() {
    return {
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        judge_position: 'referee_score',
    };
}

const teamMemberForm = reactive(blankTeamMemberForm());
const trainerInviteForm = reactive({ emails: '' });

const examFilters = reactive({
    search: '',
    city: '',
    coach_id: '',
    date_from: '',
    date_to: '',
});

const ratingFilters = reactive({
    year: String(new Date().getFullYear()),
    discipline: 'kumite',
    view_mode: 'all',
    weight_category: '',
    age_band: '',
    gender: '',
    organization_id: '',
    region_id: '',
});

const tournamentFilters = reactive({
    status: 'active',
    scope: 'mine',
    region_id: '',
    search: '',
});

const tournamentDetailFilters = reactive({
    search: '',
    coach_id: '',
    list_id: '',
    page: 1,
    per_page: 10,
});

function blankExamForm() {
    return {
        name: '',
        city: '',
        date: '',
        receiving: '',
    };
}

const examForm = reactive(blankExamForm());

function blankTournamentItemForm() {
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
        tournament_type_kata: '1',
        is_online_kata: false,
        tatami: '',
        price: '',
        date_commission: '',
        date: '',
        date_finish: '',
        regulation_document: null,
        application_document: null,
        logo_report: null,
        chief_judge: '',
        chief_secretary: '',
        address: '',
    };
}

const tournamentItemForm = reactive(blankTournamentItemForm());

const navItems = computed(() => isAdmin.value ? ['feed', 'education', 'agreements', 'organizations', 'regions', 'scales', 'activity'].map((key, index) => ({ key: 'admin-' + key, label: adminLabels[locale.value][key], path: '/panel/admin/' + key, icon: ['grid', 'book', 'file', 'users', 'info', 'trophy', 'list'][index] })) : [
    ...(isStudent.value ? [{ key: 'profile', label: t.value.accountProfile, icon: 'users', path: '/panel/profile' }] : canViewAbout.value ? [{ key: 'dashboard', label: t.value.dashboard, icon: 'grid', path: '/panel/dashboard' }] : []),
    { key: 'tournaments', label: t.value.tournaments, icon: 'trophy', path: '/panel/tournaments' },
    ...(panelCapabilities.value.view_examinations ? [{ key: 'exams', label: t.value.exams, icon: 'book', path: '/panel/exams' }] : []),
    ...(!isStudent.value ? [{ key: 'team', label: t.value.team, icon: 'users', path: '/panel/team' },
    { key: 'templates', label: t.value.templates, icon: 'list', path: '/panel/templates' }] : []),
    { key: 'rating', label: t.value.ratingNav, icon: 'trophy', path: '/panel/rating' },
    ...(isStudent.value ? [{ key: 'education', label: studentLabels[locale.value].education, icon: 'book', path: '/panel/education' }] : []),
    ...(canViewAbout.value ? [{ key: 'documents', label: t.value.documents, icon: 'file', path: '/panel/documents' }] : []),
    ...(canManageOrganizationSettings.value ? [{ key: 'settings', label: t.value.settings, icon: 'settings', path: '/panel/settings' }] : []),
]);

const bottomNavItems = computed(() => [
    ...(canViewAbout.value ? [{ key: 'about', label: t.value.about, icon: 'info', path: '/panel/about' }] : []),
]);

const visibleFormFields = computed(() => ({
    kataType: templateForm.list_type === 'kata',
    weight: templateForm.list_type === 'kumite',
    rank: templateForm.list_type === 'kumite' || templateForm.kata_type === 'flag',
    gender: templateForm.list_type === 'kumite' || templateForm.kata_type === 'personal' || templateForm.kata_type === 'flag',
}));

const displayedPages = computed(() => {
    const last = listMeta.value.last_page;
    const current = listMeta.value.current_page;

    if (last <= 5) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const start = Math.max(1, Math.min(current - 2, last - 4));

    return Array.from({ length: 5 }, (_, index) => start + index);
});

const displayedTeamPages = computed(() => {
    const last = teamMeta.value.last_page;
    const current = teamMeta.value.current_page;

    if (last <= 5) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const start = Math.max(1, Math.min(current - 2, last - 4));

    return Array.from({ length: 5 }, (_, index) => start + index);
});

const displayedExamPages = computed(() => visiblePages(examMeta.value));
const displayedExamStudentPages = computed(() => visiblePages(examStudentsMeta.value));
const displayedTournamentPages = computed(() => visiblePages(tournamentMeta.value));
const displayedTournamentStudentPages = computed(() => visiblePages(tournamentDetail.value.students.meta));
const trainerStudentsMeta = computed(() => teamTrainerDetail.value?.students?.meta ?? {
    current_page: 1,
    last_page: 1,
    per_page: trainerStudentFilters.per_page,
    total: 0,
    from: 0,
    to: 0,
});
const displayedTrainerStudentPages = computed(() => visiblePages(trainerStudentsMeta.value));

function visiblePages(meta) {
    const last = meta.last_page;
    const current = meta.current_page;

    if (last <= 5) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const start = Math.max(1, Math.min(current - 2, last - 4));

    return Array.from({ length: 5 }, (_, index) => start + index);
}

function setLocale(value) {
    locale.value = value;
}

function toggleTheme() {
    theme.value = theme.value === 'light' ? 'dark' : 'light';
}

function openRoute(path) {
    if (authUser.value?.agreements_required && !path.startsWith('/panel/documents') && !path.startsWith('/panel/agreement-doc')) {
        rememberAgreementReturn(path);
        path = '/panel/documents';
    }
    window.history.pushState({}, '', path);
    currentPath.value = window.location.pathname;
    loadPanelData().catch((error) => console.error(error));
}

function acceptedAgreements(required) {
    authUser.value.agreements_required = required;
    if (!required) {
        const path = consumeAgreementReturn();
        if (path) openRoute(path);
    }
}

function defaultPanelPath() {
    return navItems.value[0]?.path ?? '/panel/tournaments';
}

function redirectBarePanelPath() {
    if (! /^\/panel\/?$/.test(currentPath.value)) return false;

    const path = defaultPanelPath();
    window.history.replaceState({}, '', path);
    currentPath.value = window.location.pathname;

    return true;
}

function hasAnyRole(roles) {
    return roles.some((role) => authRoleNames.value.includes(role));
}

async function submitLogin(payload) {
    if (isSubmitting.value) return;
    isSubmitting.value = true;
    formError.value = '';

    try {
        const response = await fetch('/api/auth/login', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                email: payload.email,
                password: payload.password,
                remember: payload.remember,
            }),
        });

        if (! response.ok) throw new Error('Login failed');
        authUser.value = (await response.json()).user;
        openRoute(defaultPanelPath());
    } catch {
        formError.value = t.value.failed;
    } finally {
        isSubmitting.value = false;
    }
}

async function loadUser() {
    if (! isPanel.value || isLoadingUser.value) return;
    isLoadingUser.value = true;

    try {
        const response = await fetch('/api/auth/user', { headers: { 'Accept': 'application/json' } });
        if (! response.ok) throw new Error('Unauthenticated');
        const payload = await response.json();
        authUser.value = payload.user;
    } catch {
        authUser.value = null;
        window.history.replaceState({}, '', '/login');
        currentPath.value = window.location.pathname;
        return;
    } finally {
        isLoadingUser.value = false;
    }

    redirectBarePanelPath();
    if (authUser.value?.agreements_required && panelSection.value !== 'documents') {
        rememberAgreementReturn();
        window.history.replaceState({}, '', '/panel/documents');
        currentPath.value = '/panel/documents';
    }

    try {
        await loadPanelData();
    } catch (error) {
        console.error(error);
    }
}

async function logout() {
    await fetch('/api/auth/logout', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    });

    authUser.value = null;
    openRoute('/');
}

async function loadTemplateLists(page = 1) {
    if (! isPanel.value || panelSection.value !== 'templates') return;
    isLoadingLists.value = true;
    const params = new URLSearchParams({
        page,
        per_page: listMeta.value.per_page,
        all: showAllTemplates.value ? '1' : '0',
    });

    Object.entries(filters).forEach(([key, value]) => {
        if (value !== '') params.set(key, value);
    });

    try {
        const response = await fetch(`/api/panel/template-student-lists?${params.toString()}`, {
            headers: { 'Accept': 'application/json' },
        });
        if (! response.ok) throw new Error('Failed to load');
        const payload = await response.json();
        templateLists.value = payload.items;
        listMeta.value = payload.meta;
        listStats.value = payload.stats;
    } finally {
        isLoadingLists.value = false;
    }
}

async function loadAbout() {
    if (! isPanel.value || panelSection.value !== 'about' || isLoadingAbout.value) return;
    if (! canViewAbout.value) {
        openRoute('/panel/templates');
        return;
    }

    isLoadingAbout.value = true;

    try {
        const response = await fetch('/api/panel/about', { headers: { 'Accept': 'application/json' } });
        if (response.status === 403) {
            openRoute('/panel/templates');
            return;
        }
        if (! response.ok) throw new Error('Failed to load about');
        aboutContent.value = await response.json();
    } finally {
        isLoadingAbout.value = false;
    }
}

async function loadOrganizationSettings() {
    if (! isPanel.value || panelSection.value !== 'settings' || isLoadingOrganizationSettings.value) return;
    if (! canManageOrganizationSettings.value) {
        openRoute('/panel/templates');
        return;
    }

    isLoadingOrganizationSettings.value = true;
    organizationSettingsError.value = '';

    try {
        const response = await fetch('/api/panel/settings', { headers: { 'Accept': 'application/json' } });
        if (response.status === 403) {
            openRoute('/panel/templates');
            return;
        }
        if (! response.ok) throw new Error('Failed to load settings');
        const payload = await response.json();
        organizationSettings.value = payload.settings;
        isHydratingOrganizationSettings.value = true;
        organizationSettingsForm.can_edit_students = Boolean(payload.settings.can_edit_students);
        organizationSettingsForm.can_edit_coaches = Boolean(payload.settings.can_edit_coaches);
        isHydratingOrganizationSettings.value = false;
    } finally {
        isLoadingOrganizationSettings.value = false;
    }
}

function scheduleOrganizationSettingsSave() {
    if (
        ! isPanel.value
        || panelSection.value !== 'settings'
        || isLoadingOrganizationSettings.value
        || isHydratingOrganizationSettings.value
        || ! organizationSettings.value
    ) {
        return;
    }

    window.clearTimeout(organizationSettingsSaveTimer);
    organizationSettingsSaveTimer = window.setTimeout(() => {
        saveOrganizationSettings();
    }, 350);
}

async function saveOrganizationSettings() {
    if (isSavingOrganizationSettings.value) {
        organizationSettingsSavePending.value = true;
        return;
    }

    isSavingOrganizationSettings.value = true;
    organizationSettingsError.value = '';

    try {
        const response = await fetch('/api/panel/settings', {
            method: 'PUT',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                can_edit_students: organizationSettingsForm.can_edit_students,
                can_edit_coaches: organizationSettingsForm.can_edit_coaches,
            }),
        });

        if (! response.ok) throw new Error('Failed to save settings');
        const payload = await response.json();
        organizationSettings.value = payload.settings;

        if (! organizationSettingsSavePending.value) {
            isHydratingOrganizationSettings.value = true;
            organizationSettingsForm.can_edit_students = Boolean(payload.settings.can_edit_students);
            organizationSettingsForm.can_edit_coaches = Boolean(payload.settings.can_edit_coaches);
            isHydratingOrganizationSettings.value = false;
        }
    } catch {
        organizationSettingsError.value = t.value.validationError;
    } finally {
        isSavingOrganizationSettings.value = false;

        if (organizationSettingsSavePending.value) {
            organizationSettingsSavePending.value = false;
            saveOrganizationSettings();
        }
    }
}

async function loadTeam(page = 1) {
    if (! isPanel.value || panelSection.value !== 'team' || currentTeamStudentId.value) return;
    isLoadingTeam.value = true;
    const params = new URLSearchParams({
        section: teamSection.value,
        page,
        per_page: teamMeta.value.per_page,
        sort: teamSort.value,
    });

    if (teamSearch.value) {
        params.set('search', teamSearch.value);
    }

    try {
        const response = await fetch(`/api/panel/team?${params.toString()}`, {
            headers: { 'Accept': 'application/json' },
        });
        if (! response.ok) throw new Error('Failed to load team');
        const payload = await response.json();
        teamRows.value = payload.items.data;
        teamMeta.value = payload.items.meta;
        teamStats.value = payload.stats;
    } finally {
        isLoadingTeam.value = false;
    }
}

async function loadTeamStudent() {
    if (! isPanel.value || panelSection.value !== 'team' || ! currentTeamStudentId.value) return;

    isLoadingTeamStudent.value = true;

    try {
        const response = await fetch(`/api/panel/team/students/${currentTeamStudentId.value}`, {
            headers: { 'Accept': 'application/json' },
        });
        if (! response.ok) throw new Error('Failed to load student');
        teamStudentDetail.value = await response.json();
    } finally {
        isLoadingTeamStudent.value = false;
    }
}

async function loadTeamTrainer(page = 1) {
    if (! isPanel.value || panelSection.value !== 'team' || ! currentTeamTrainerId.value) return;

    isLoadingTeamTrainer.value = true;
    const params = new URLSearchParams({
        page,
        per_page: trainerStudentFilters.per_page,
    });

    if (trainerStudentFilters.tournament_id) {
        params.set('tournament_id', trainerStudentFilters.tournament_id);
    }

    try {
        const query = params.toString();
        const response = await fetch(`/api/panel/team/trainers/${currentTeamTrainerId.value}${query ? `?${query}` : ''}`, {
            headers: { 'Accept': 'application/json' },
        });
        if (! response.ok) throw new Error('Failed to load trainer');
        teamTrainerDetail.value = await response.json();
    } finally {
        isLoadingTeamTrainer.value = false;
    }
}

function setTrainerStudentTournament(value) {
    trainerStudentFilters.tournament_id = value;
    loadTeamTrainer(1);
}

function setTrainerStudentPerPage(value) {
    trainerStudentFilters.per_page = value;
    loadTeamTrainer(1);
}

async function updateStudentDocument({ document, values }) {
    const response = await fetch(`/api/panel/team/students/${currentTeamStudentId.value}/documents/${document}`, {
        method: 'PUT',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(values),
    });

    if (! response.ok) {
        throw new Error('Failed to update student document');
    }

    const payload = await response.json();
    teamStudentDetail.value = {
        ...teamStudentDetail.value,
        documents: payload.documents,
    };
}

async function detachTrainerStudent(student) {
    if (! currentTeamTrainerId.value || ! student?.id) return;

    const pageAfterDetach = (teamTrainerDetail.value?.students?.data?.length ?? 0) === 1 && trainerStudentsMeta.value.current_page > 1
        ? trainerStudentsMeta.value.current_page - 1
        : trainerStudentsMeta.value.current_page;

    const response = await fetch(`/api/panel/team/trainers/${currentTeamTrainerId.value}/students/${student.id}`, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    });

    if (! response.ok) {
        throw new Error('Failed to detach student');
    }

    await loadTeamTrainer(pageAfterDetach);
}

async function deleteTeamMembers(ids) {
    const response = await fetch(`/api/panel/team/${teamSection.value}/members`, {
        method: 'DELETE', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify({ ids, locale: locale.value }),
    });
    const payload = await response.json();
    if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? t.value.actionFailed);
    closeTeamMemberModal();
    await loadTeam(1);
}

async function deleteTrainer(trainer) {
    if (! trainer?.id) return;

    const pageAfterDelete = teamRows.value.length === 1 && teamMeta.value.current_page > 1
        ? teamMeta.value.current_page - 1
        : teamMeta.value.current_page;

    const response = await fetch(`/api/panel/team/trainers/${trainer.id}`, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    });

    if (! response.ok) {
        throw new Error('Failed to delete trainer');
    }

    const payload = await response.json();
    teamStats.value = payload.stats ?? teamStats.value;
    await loadTeam(pageAfterDelete);
}

async function resendPendingInvitation(invitation) {
    if (! invitation?.id) return;

    const response = await fetch(`/api/panel/team/pending/${invitation.id}/resend`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ locale: locale.value }),
    });

    if (! response.ok) {
        throw new Error('Failed to resend invitation');
    }

    const payload = await response.json();
    teamStats.value = payload.stats ?? teamStats.value;
    await loadTeam(teamMeta.value.current_page);
}

async function deletePendingInvitation(invitation) {
    if (! invitation?.id) return;

    const pageAfterDelete = teamRows.value.length === 1 && teamMeta.value.current_page > 1
        ? teamMeta.value.current_page - 1
        : teamMeta.value.current_page;

    const response = await fetch(`/api/panel/team/pending/${invitation.id}`, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    });

    if (! response.ok) {
        throw new Error('Failed to delete invitation');
    }

    const payload = await response.json();
    teamStats.value = payload.stats ?? teamStats.value;
    await loadTeam(pageAfterDelete);
}


async function loadExams(page = 1) {
    if (! isPanel.value || panelSection.value !== 'exams' || currentExamId.value) return;
    isLoadingExams.value = true;
    const params = new URLSearchParams({
        page,
        per_page: examMeta.value.per_page,
    });

    Object.entries(examFilters).forEach(([key, value]) => {
        if (value !== '') params.set(key, value);
    });

    try {
        const response = await fetch(`/api/panel/examinations?${params.toString()}`, {
            headers: { 'Accept': 'application/json' },
        });
        if (! response.ok) throw new Error('Failed to load examinations');
        const payload = await response.json();
        exams.value = payload.items.data;
        examMeta.value = payload.items.meta;
        examStats.value = payload.stats;
        examCities.value = payload.filters.cities;
        examCoaches.value = payload.filters.coaches;
    } finally {
        isLoadingExams.value = false;
    }
}

async function loadExamDetail() {
    if (! isPanel.value || panelSection.value !== 'exams' || ! currentExamId.value) return;

    const response = await fetch(`/api/panel/examinations/${currentExamId.value}`, {
        headers: { 'Accept': 'application/json' },
    });
    if (! response.ok) throw new Error('Failed to load examination');
    const payload = await response.json();
    currentExam.value = payload.item;
    examCoaches.value = payload.coaches;
    await loadExamStudents();
}

async function loadExamStudents(page = 1) {
    if (! currentExamId.value) return;
    isLoadingExamStudents.value = true;
    const params = new URLSearchParams({
        page,
        per_page: examStudentsMeta.value.per_page,
    });

    if (examStudentSearch.value) params.set('search', examStudentSearch.value);
    if (examSelectedCoach.value) params.set('coach_id', examSelectedCoach.value);

    try {
        const response = await fetch(`/api/panel/examinations/${currentExamId.value}/students?${params.toString()}`, {
            headers: { 'Accept': 'application/json' },
        });
        if (! response.ok) throw new Error('Failed to load students');
        const payload = await response.json();
        examStudents.value = payload.items.data;
        examStudentsMeta.value = payload.items.meta;
    } finally {
        isLoadingExamStudents.value = false;
    }
}

async function loadRating() {
    if (! isPanel.value || panelSection.value !== 'rating') return;
    isLoadingRating.value = true;
    const params = new URLSearchParams();

    Object.entries(ratingFilters).forEach(([key, value]) => {
        if (value !== '') params.set(key, value);
    });

    try {
        const response = await fetch(`/api/panel/rating?${params.toString()}`, {
            headers: { 'Accept': 'application/json' },
        });
        if (! response.ok) throw new Error('Failed to load rating');
        ratingResult.value = await response.json();
    } finally {
        isLoadingRating.value = false;
    }
}

async function loadTournaments(page = 1) {
    if (currentPath.value === '/panel/tournaments/applications') return;
    if (currentExternalFormId.value) return;
    if (! isPanel.value || panelSection.value !== 'tournaments') return;
    isLoadingTournaments.value = true;
    if (! currentChampionshipId.value) {
        currentChampionship.value = null;
        currentTournament.value = null;
        tournamentDetail.value = blankTournamentDetail();
        tournamentForms.value = [];
    }
    if (! currentTournamentId.value) {
        currentTournament.value = null;
        tournamentDetail.value = blankTournamentDetail();
    }
    const endpoint = currentTournamentId.value
        ? `/api/panel/tournaments/${currentChampionshipId.value}/items/${currentTournamentId.value}`
        : currentChampionshipId.value
        ? `/api/panel/tournaments/${currentChampionshipId.value}`
        : '/api/panel/tournaments';
    const params = new URLSearchParams({
        page: currentTournamentId.value ? tournamentDetailFilters.page : page,
        per_page: currentTournamentId.value ? tournamentDetailFilters.per_page : tournamentMeta.value.per_page,
    });

    if (currentTournamentId.value) {
        if (tournamentDetailFilters.search) params.set('search', tournamentDetailFilters.search);
        if (tournamentDetailFilters.coach_id) params.set('coach_id', tournamentDetailFilters.coach_id);
        if (tournamentDetailFilters.list_id) params.set('list_id', tournamentDetailFilters.list_id);
    } else if (! currentChampionshipId.value) {
        Object.entries(tournamentFilters).forEach(([key, value]) => {
            if (value !== '') params.set(key, value);
        });
    }

    try {
        const response = await fetch(`${endpoint}?${params.toString()}`, {
            headers: { 'Accept': 'application/json' },
        });
        if (! response.ok) throw new Error('Failed to load tournaments');
        const payload = await response.json();
        tournaments.value = payload.items?.data ?? [];
        tournamentMeta.value = payload.items?.meta ?? { current_page: 1, last_page: 1, per_page: tournamentMeta.value.per_page, total: 0, from: 0, to: 0 };
        tournamentStats.value = payload.stats ?? { total: 0, active: 0, completed: 0, this_month: 0 };
        tournamentRegions.value = payload.filters?.regions ?? [];
        applyTournamentPayload(payload, true);
        tournamentForms.value = payload.forms ?? [];
        tournamentCreateOptions.value = payload.create_options ?? { regions: [], scales: [] };
    } finally {
        isLoadingTournaments.value = false;
    }
}

async function reloadCurrentTournamentDetail() {
    await loadTournaments(tournamentDetailFilters.page);
}

function blankTournamentDetail(perPage = 10) {
    return {
        students: { data: [], meta: { current_page: 1, last_page: 1, per_page: perPage, total: 0, from: 0, to: 0 } },
        coaches: [],
        lists: [],
        options: { coaches: [], students: [], lists: [] },
    };
}

function normalizeTournamentDetail(detail) {
    const blank = blankTournamentDetail();
    const students = detail?.students ?? blank.students;

    return {
        ...blank,
        ...(detail ?? {}),
        students: {
            ...blank.students,
            ...students,
            data: [...(students.data ?? [])],
            meta: {
                ...blank.students.meta,
                ...(students.meta ?? {}),
            },
        },
        coaches: [...(detail?.coaches ?? [])],
        lists: [...(detail?.lists ?? [])],
        options: {
            ...blank.options,
            ...(detail?.options ?? {}),
            coaches: [...(detail?.options?.coaches ?? [])],
            students: [...(detail?.options?.students ?? [])],
            lists: [...(detail?.options?.lists ?? [])],
        },
    };
}

function applyTournamentPayload(payload, resetMissing = false) {
    if (payload.championship || resetMissing) {
        currentChampionship.value = payload.championship ?? null;
    }

    if (payload.tournament || resetMissing) {
        currentTournament.value = payload.tournament ?? null;
    }

    if (payload.detail || resetMissing) {
        tournamentDetail.value = normalizeTournamentDetail(resetMissing ? payload.detail : { ...tournamentDetail.value, ...payload.detail });
    }
}

function patchTournamentDetail(patch) {
    tournamentDetail.value = normalizeTournamentDetail({
        ...tournamentDetail.value,
        ...patch,
        options: {
            ...(tournamentDetail.value.options ?? {}),
            ...(patch.options ?? {}),
        },
        students: {
            ...(tournamentDetail.value.students ?? {}),
            ...(patch.students ?? {}),
        },
    });
}

function showToast(message, type = 'success') {
    toast.value = { message, type };
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => {
        toast.value = null;
    }, 2600);
}

async function loadPanelData() {
    if (!isPanel.value) return;
    if (isAdmin.value) {
        if (!adminSection.value) openRoute('/panel/admin/feed');
        return;
    }
    if (adminSection.value) { openRoute(defaultPanelPath()); return; }
    if (isStudent.value && ['dashboard', 'templates', 'settings', 'team', 'tasks'].includes(panelSection.value)) {
        openRoute('/panel/profile');
        return;
    }
    if (['dashboard', 'profile', 'notifications', 'documents', 'education'].includes(panelSection.value)) return;
    if (panelSection.value === 'exams' && ! panelCapabilities.value.view_examinations) {
        openRoute('/panel/tournaments');
        return;
    }
    if (panelSection.value === 'about') {
        await loadAbout();
        return;
    }

    if (panelSection.value === 'settings') {
        await loadOrganizationSettings();
        return;
    }

    if (panelSection.value === 'team') {
        if (currentTeamStudentId.value) {
            teamRows.value = [];
            teamTrainerDetail.value = null;
            await loadTeamStudent();
        } else if (currentTeamTrainerId.value) {
            teamRows.value = [];
            teamStudentDetail.value = null;
            await loadTeamTrainer();
        } else {
            teamStudentDetail.value = null;
            teamTrainerDetail.value = null;
            trainerStudentFilters.tournament_id = '';
            await loadTeam();
        }
        return;
    }

    if (panelSection.value === 'tournaments') {
        await loadTournaments();
        return;
    }

    if (panelSection.value === 'exams') {
        if (currentExamId.value) {
            await loadExamDetail();
        } else {
            currentExam.value = null;
            await loadExams();
        }
        return;
    }

    if (panelSection.value === 'rating') {
        await loadRating();
        return;
    }

    await loadTemplateLists();
}

function scheduleLoad() {
    window.clearTimeout(filtersTimer);
    filtersTimer = window.setTimeout(() => loadTemplateLists(1), 250);
}

function resetFilters() {
    Object.keys(filters).forEach((key) => {
        filters[key] = '';
    });
    loadTemplateLists(1);
}

function setTeamSection(section) {
    if (! panelCapabilities.value.team_sections?.includes(section)) return;
    teamSection.value = section;
    teamSort.value = 'name';
    loadTeam(1);
}

function setTeamSearch(value) {
    teamSearch.value = value;
    window.clearTimeout(teamSearchTimer);
    teamSearchTimer = window.setTimeout(() => loadTeam(1), 250);
}

function setTeamSort(value) {
    teamSort.value = value;
    loadTeam(1);
}

function setTeamPerPage(value) {
    teamMeta.value = { ...teamMeta.value, per_page: value };
    loadTeam(1);
}

function exportTeam() {
    const params = new URLSearchParams({
        section: teamSection.value,
        sort: teamSort.value,
    });

    if (teamSearch.value) {
        params.set('search', teamSearch.value);
    }

    window.location.href = `/api/panel/team/export?${params.toString()}`;
}

function openTeamStudent(row) {
    if (! row?.id) return;

    openRoute(`/panel/team/students/${row.id}`);
}

function openTeamTrainer(row) {
    if (! row?.id) return;

    openRoute(`/panel/team/trainers/${row.id}`);
}

function openStudentTournament(tournament) {
    if (! tournament?.can_open || ! tournament?.id || ! tournament?.championship_id) return;

    openRoute(`/panel/tournaments/${tournament.championship_id}/items/${tournament.id}`);
}

function addTrainer() {
    trainerInviteForm.emails = '';
    trainerInviteError.value = '';
    showTrainerInviteModal.value = true;
}

function openExam(exam) {
    openRoute(`/panel/exams/${exam.id}`);
}

function openExamModal(exam = null) {
    editingExam.value = exam;
    examError.value = '';
    Object.assign(examForm, blankExamForm(), exam ?? {});
    showExamModal.value = true;
}

function closeExamModal() {
    showExamModal.value = false;
    editingExam.value = null;
    examError.value = '';
}

async function saveExam() {
    examError.value = '';
    const id = editingExam.value?.id ?? currentExamId.value;

    try {
        const response = await fetch(id ? `/api/panel/examinations/${id}` : '/api/panel/examinations', {
            method: id ? 'PUT' : 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ ...examForm }),
        });

        if (! response.ok) throw new Error('Validation failed');
        const payload = await response.json();
        closeExamModal();

        if (currentExamId.value) {
            currentExam.value = payload.item;
            return;
        }

        await loadExams(examMeta.value.current_page);
    } catch {
        examError.value = t.value.validationError;
    }
}

async function deleteExam(exam) {
    await fetch(`/api/panel/examinations/${exam.id}`, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    });
    await loadExams(examMeta.value.current_page);
}

function setExamPerPage(value) {
    examMeta.value = { ...examMeta.value, per_page: value };
    loadExams(1);
}

function setTournamentStatus(status) {
    tournamentFilters.status = status;
}

function setTournamentPerPage(value) {
    tournamentMeta.value = { ...tournamentMeta.value, per_page: value };
    loadTournaments(1);
}

function openChampionship(championship) {
    openRoute(`/panel/tournaments/${championship.id}`);
}

function openTournamentItem(tournament) {
    const championshipId = tournament?.championship_id ?? currentChampionshipId.value;
    if (! tournament?.id || ! championshipId) return;

    openRoute(`/panel/tournaments/${championshipId}/items/${tournament.id}`);
}

function openTournamentItemEdit(tournament) {
    const championshipId = tournament?.championship_id ?? currentChampionshipId.value;
    if (! tournament?.id || ! championshipId) return;

    openRoute(`/panel/tournaments/${championshipId}/items/${tournament.id}/edit`);
}

function filterTournamentStudents() {
    window.clearTimeout(tournamentFiltersTimer);
    tournamentFiltersTimer = window.setTimeout(() => {
        tournamentDetailFilters.page = 1;
        loadTournaments(1);
    }, 250);
}

function setTournamentStudentPage(page) {
    tournamentDetailFilters.page = page;
    loadTournaments(page);
}

function setTournamentStudentPerPage(perPage) {
    tournamentDetailFilters.per_page = perPage;
    tournamentDetailFilters.page = 1;
    loadTournaments(1);
}

async function tournamentDetailRequest(path, options = {}) {
    const championshipId = currentChampionshipId.value;
    const tournamentId = currentTournamentId.value;
    const parts = path.startsWith('/coaches') ? 'coaches' : path.startsWith('/lists') ? 'lists,students' : 'students,lists';
    const response = await fetch(`/api/panel/tournaments/${championshipId}/items/${tournamentId}${path}?parts=${parts}&metadata=0`, {
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        ...options,
    });
    const payload = await response.json().catch(() => ({}));
    if (! response.ok) throw new Error(firstApiError(payload) || t.value.validationError);

    applyTournamentPayload(payload);

    return payload;
}

async function refreshTournamentDetail(championshipId = currentChampionshipId.value, tournamentId = currentTournamentId.value, parts = 'students,coaches,lists') {
    if (! championshipId || ! tournamentId) return;

    const params = new URLSearchParams({
        page: tournamentDetailFilters.page,
        per_page: tournamentDetailFilters.per_page,
    });

    if (tournamentDetailFilters.search) params.set('search', tournamentDetailFilters.search);
    if (tournamentDetailFilters.coach_id) params.set('coach_id', tournamentDetailFilters.coach_id);
    if (tournamentDetailFilters.list_id) params.set('list_id', tournamentDetailFilters.list_id);

    params.set('parts', parts);
    params.set('metadata', '0');
    const response = await fetch(`/api/panel/tournaments/${championshipId}/items/${tournamentId}?${params.toString()}`, {
        headers: { 'Accept': 'application/json' },
    });
    const payload = await response.json().catch(() => ({}));
    if (! response.ok) throw new Error(firstApiError(payload) || t.value.validationError);

    applyTournamentPayload(payload);
}

async function attachTournamentCoaches(ids) {
    if (! ids.length) return;
    const championshipId = currentChampionshipId.value;
    const tournamentId = currentTournamentId.value;
    const payload = await tournamentDetailRequest('/coaches', {
        method: 'POST',
        body: JSON.stringify({ coach_ids: ids }),
    });
    if (payload.detail) {
        applyTournamentPayload(payload);
    }
    await refreshTournamentDetail(championshipId, tournamentId, 'coaches');
}

async function detachTournamentCoach(id) {
    const championshipId = currentChampionshipId.value;
    const tournamentId = currentTournamentId.value;
    await tournamentDetailRequest(`/coaches/${id}`, { method: 'DELETE' });
    patchTournamentDetail({
        coaches: tournamentDetail.value.coaches.filter((coach) => Number(coach.id) !== Number(id)),
    });
    await refreshTournamentDetail(championshipId, tournamentId, 'coaches');
}

async function updateTournamentStudent(payload) {
    const championshipId = currentChampionshipId.value;
    const tournamentId = currentTournamentId.value;
    const id = payload.student_tournament_id;
    const body = { ...payload };
    delete body.student_tournament_id;

    await tournamentDetailRequest(`/students/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(body),
    });
    await refreshTournamentDetail(championshipId, tournamentId, 'students,lists');
    showToast(t.value.tournamentStudentUpdated ?? t.value.saved);
}

async function attachTournamentLists(ids) {
    if (! ids.length) return;
    const championshipId = currentChampionshipId.value;
    const tournamentId = currentTournamentId.value;
    const payload = await tournamentDetailRequest('/lists', {
        method: 'POST',
        body: JSON.stringify({ template_ids: ids }),
    });
    if (payload.detail) {
        applyTournamentPayload(payload);
    }
    await refreshTournamentDetail(championshipId, tournamentId, 'lists,students');
}

async function detachTournamentList(id) {
    const championshipId = currentChampionshipId.value;
    const tournamentId = currentTournamentId.value;
    await tournamentDetailRequest(`/lists/${id}`, { method: 'DELETE' });
    patchTournamentDetail({
        lists: tournamentDetail.value.lists.filter((list) => Number(list.id) !== Number(id)),
    });
    await refreshTournamentDetail(championshipId, tournamentId, 'lists,students');
}

async function createTournamentList(payload) {
    tournamentItemError.value = '';

    try {
        const response = await fetch(`/api/panel/tournaments/${currentChampionshipId.value}/items/${currentTournamentId.value}/lists/create`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(payload),
        });
        const result = await response.json().catch(() => ({}));
        if (! response.ok) {
            tournamentItemError.value = firstApiError(result) || t.value.validationError;
            return;
        }

        applyTournamentPayload(result);
        await refreshTournamentDetail(currentChampionshipId.value, currentTournamentId.value);
    } catch (error) {
        tournamentItemError.value = error?.message || t.value.validationError;
    }
}

function openChampionshipModal() {
    championshipForm.name = '';
    championshipForm.banner = null;
    championshipError.value = '';
    showChampionshipModal.value = true;
}

function closeChampionshipModal() {
    showChampionshipModal.value = false;
    championshipError.value = '';
}

function openTournamentItemModal() {
    Object.assign(tournamentItemForm, blankTournamentItemForm());
    tournamentItemError.value = '';
    showTournamentItemModal.value = true;
}

function closeTournamentItemModal() {
    showTournamentItemModal.value = false;
    tournamentItemError.value = '';
}

async function createTournamentItem() {
    tournamentItemError.value = '';

    const payload = new FormData();
    Object.entries(tournamentItemForm).forEach(([key, value]) => {
        if (value === null || value === '') return;
        payload.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
    });

    try {
        const response = await fetch(`/api/panel/tournaments/${currentChampionshipId.value}/items`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: payload,
        });

        const result = await response.json().catch(() => ({}));
        if (! response.ok) {
            tournamentItemError.value = firstApiError(result) || t.value.validationError;
            return;
        }

        const championshipId = currentChampionshipId.value;

        closeTournamentItemModal();
        currentTournament.value = result.item;
        openRoute(`/panel/tournaments/${championshipId}/items/${result.item.id}/edit`);
    } catch (error) {
        tournamentItemError.value = error?.message || t.value.validationError;
    }
}

function firstApiError(payload) {
    const firstErrors = Object.values(payload?.errors ?? {})[0];

    return Array.isArray(firstErrors) ? firstErrors[0] : payload?.message;
}

async function createChampionship() {
    championshipError.value = '';

    const payload = new FormData();
    payload.append('name', championshipForm.name);
    if (championshipForm.banner) {
        payload.append('banner', championshipForm.banner);
    }

    try {
        const response = await fetch('/api/panel/tournaments', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: payload,
        });

        if (! response.ok) throw new Error('Validation failed');
        const payloadResponse = await response.json();
        closeChampionshipModal();
        openChampionship(payloadResponse.item);
    } catch {
        championshipError.value = t.value.validationError;
    }
}

async function deleteChampionship() {
    if (! currentChampionshipId.value) return;

    const response = await fetch(`/api/panel/tournaments/${currentChampionshipId.value}`, {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });

    if (! response.ok) return;

    currentChampionship.value = null;
    tournaments.value = [];
    tournamentForms.value = [];
    openRoute('/panel/tournaments');
}

function openTournamentFormModal(form = null) {
    tournamentForm.id = form?.id ?? null;
    tournamentForm.organization_name = form?.organization_name ?? '';
    tournamentFormError.value = '';
    showTournamentFormModal.value = true;
}

function closeTournamentFormModal() {
    showTournamentFormModal.value = false;
    tournamentFormError.value = '';
    tournamentForm.id = null;
}

function upsertTournamentForm(item) {
    if (! item?.id) return;

    const index = tournamentForms.value.findIndex((form) => Number(form.id) === Number(item.id));
    if (index === -1) {
        tournamentForms.value = [item, ...tournamentForms.value];
        return;
    }

    tournamentForms.value = tournamentForms.value.map((form) => (
        Number(form.id) === Number(item.id) ? item : form
    ));
}

async function createTournamentForm() {
    tournamentFormError.value = '';
    const id = tournamentForm.id;

    try {
        const response = await fetch(id
            ? `/api/panel/tournaments/${currentChampionshipId.value}/forms/${id}`
            : `/api/panel/tournaments/${currentChampionshipId.value}/forms`, {
            method: id ? 'PUT' : 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ organization_name: tournamentForm.organization_name }),
        });

        if (! response.ok) throw new Error('Validation failed');
        const result = await response.json().catch(() => ({}));
        upsertTournamentForm(result.item);
        closeTournamentFormModal();
    } catch {
        tournamentFormError.value = t.value.validationError;
    }
}

async function setTournamentFormStatus(payload) {
    try {
        const response = await fetch(`/api/panel/tournaments/${currentChampionshipId.value}/forms/${payload.form.id}/status`, {
            method: 'PATCH',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ status: payload.status }),
        });

        const result = await response.json().catch(() => ({}));
        if (! response.ok) throw new Error(firstApiError(result) || t.value.validationError);
        upsertTournamentForm(result.item);
        showToast(payload.status === 'closed' ? t.value.teamFormClosed : t.value.teamFormOpened);
    } catch (error) {
        showToast(error?.message || t.value.validationError, 'error');
    }
}

async function importTournamentForm(form) {
    try {
        const response = await fetch(`/api/panel/tournaments/${currentChampionshipId.value}/forms/${form.id}/import`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });

        const result = await response.json().catch(() => ({}));
        if (! response.ok) throw new Error(firstApiError(result) || t.value.validationError);
        upsertTournamentForm(result.item);
        openRoute(form.editor_url);
    } catch (error) {
        showToast(error?.message || t.value.validationError, 'error');
    }
}

async function deleteTournamentForm(form) {
    const response = await fetch(`/api/panel/tournaments/${currentChampionshipId.value}/forms/${form.id}`, {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });

    if (response.ok) {
        tournamentForms.value = tournamentForms.value.filter((item) => Number(item.id) !== Number(form.id));
        showToast(t.value.teamFormDeleted);
    }
}

function setExamStudentSearch(value) {
    examStudentSearch.value = value;
    window.clearTimeout(examStudentSearchTimer);
    examStudentSearchTimer = window.setTimeout(() => loadExamStudents(1), 250);
}

function setExamStudentCoach(value) {
    examSelectedCoach.value = value;
    loadExamStudents(1);
}

function setExamStudentPerPage(value) {
    examStudentsMeta.value = { ...examStudentsMeta.value, per_page: value };
    loadExamStudents(1);
}

function exportExamStudents() {
    const params = new URLSearchParams();
    if (examSelectedCoach.value) params.set('coach_id', examSelectedCoach.value);
    window.location.href = `/api/panel/examinations/${currentExamId.value}/students/export?${params.toString()}`;
}

async function openAttachStudentsModal() {
    selectedAttachIds.value = [];
    examError.value = '';
    const response = await fetch(`/api/panel/examinations/${currentExamId.value}/attach-options`, {
        headers: { 'Accept': 'application/json' },
    });
    if (! response.ok) {
        examError.value = t.value.validationError;
        return;
    }
    const payload = await response.json();
    attachOptions.value = payload.items;
    showAttachStudentsModal.value = true;
}

function closeAttachStudentsModal() {
    showAttachStudentsModal.value = false;
    selectedAttachIds.value = [];
}

function toggleAttachStudent(id) {
    selectedAttachIds.value = selectedAttachIds.value.includes(id)
        ? selectedAttachIds.value.filter((item) => item !== id)
        : [...selectedAttachIds.value, id];
}

async function attachStudents() {
    const response = await fetch(`/api/panel/examinations/${currentExamId.value}/students`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ student_ids: selectedAttachIds.value }),
    });

    if (! response.ok) {
        examError.value = t.value.validationError;
        return;
    }

    closeAttachStudentsModal();
    await loadExamStudents(1);
    await loadExamDetail();
}

async function detachExamStudent(student) {
    const response = await fetch(`/api/panel/examinations/${currentExamId.value}/students/${student.id}`, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    });
    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        throw new Error(payload.message || t.value.validationError);
    }
    await loadExamStudents(examStudentsMeta.value.current_page);
    await loadExamDetail();
}

function closeTrainerInviteModal() {
    showTrainerInviteModal.value = false;
    trainerInviteError.value = '';
}

async function sendTrainerInvite() {
    if (isSendingTrainerInvite.value) return;

    isSendingTrainerInvite.value = true;
    trainerInviteError.value = '';

    try {
        const response = await fetch('/api/panel/team/invite-trainers', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                emails: trainerInviteForm.emails,
                locale: locale.value,
            }),
        });

        const payload = await response.json();
        if (! response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? t.value.inviteValidationError);
        closeTrainerInviteModal();
        await loadTeam(teamMeta.value.current_page);
    } catch (error) {
        trainerInviteError.value = error.message || t.value.inviteValidationError;
    } finally {
        isSendingTrainerInvite.value = false;
    }
}

function openTeamMemberModal(row = null) {
    if (! panelCapabilities.value.team_sections?.includes(teamSection.value)) return;
    if (! ['judges', 'secretaries'].includes(teamSection.value)) return;

    editingTeamMember.value = row;
    teamMemberError.value = '';
    Object.assign(teamMemberForm, blankTeamMemberForm(), {
        first_name: row?.first_name ?? '',
        last_name: row?.last_name ?? '',
        email: row?.email ?? '',
        judge_position: row?.judge_position ?? 'referee_score',
        password: '',
    });
    showTeamMemberModal.value = true;
}

function closeTeamMemberModal() {
    showTeamMemberModal.value = false;
    editingTeamMember.value = null;
    teamMemberError.value = '';
}

async function saveTeamMember() {
    teamMemberError.value = '';
    const id = editingTeamMember.value?.id;
    const payload = { ...teamMemberForm };

    if (id && ! payload.password) {
        delete payload.password;
    }

    if (teamSection.value !== 'judges') {
        delete payload.judge_position;
    }

    try {
        const response = await fetch(id ? `/api/panel/team/${teamSection.value}/${id}` : `/api/panel/team/${teamSection.value}`, {
            method: id ? 'PUT' : 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(payload),
        });

        if (! response.ok) throw new Error('Validation failed');
        closeTeamMemberModal();
        await loadTeam(teamMeta.value.current_page);
    } catch {
        teamMemberError.value = t.value.validationError;
    }
}

function toggleShowAllTemplates() {
    const nextValue = ! showAllTemplates.value;
    showAllTemplates.value = nextValue;

    if (! nextValue) {
        listMeta.value = { ...listMeta.value, per_page: 6 };
    }

    loadTemplateLists(1);
}

function openTemplateModal(item = null) {
    editingTemplate.value = item;
    templateError.value = '';
    Object.assign(templateForm, blankTemplateForm(), item ?? {});
    if (templateForm.list_type === 'kata' && ! templateForm.kata_type) templateForm.kata_type = 'personal';
    showTemplateModal.value = true;
}

function duplicateTemplate(item) {
    openTemplateModal({ ...item, id: null, name: `${item.name} copy` });
}

function normalizeTemplateForm() {
    const payload = { ...templateForm };
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

async function saveTemplate() {
    templateError.value = '';
    const payload = normalizeTemplateForm();
    const id = editingTemplate.value?.id;

    try {
        const response = await fetch(id ? `/api/panel/template-student-lists/${id}` : '/api/panel/template-student-lists', {
            method: id ? 'PUT' : 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(payload),
        });

        if (! response.ok) throw new Error('Validation failed');
        showTemplateModal.value = false;
        await loadTemplateLists(listMeta.value.current_page);
    } catch {
        templateError.value = t.value.validationError;
    }
}

async function deleteTemplate(item) {
    await fetch(`/api/panel/template-student-lists/${item.id}`, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    });
    await loadTemplateLists(listMeta.value.current_page);
}

function startTemplateDrag(item) {
    draggedTemplateId.value = item?.id ?? null;
}

async function dropTemplate(targetItem) {
    const sourceId = draggedTemplateId.value;
    draggedTemplateId.value = null;

    if (! sourceId || sourceId === targetItem.id) {
        return;
    }

    const sourceIndex = templateLists.value.findIndex((item) => item.id === sourceId);
    const targetIndex = templateLists.value.findIndex((item) => item.id === targetItem.id);

    if (sourceIndex === -1 || targetIndex === -1) {
        return;
    }

    const nextItems = [...templateLists.value];
    const [movedItem] = nextItems.splice(sourceIndex, 1);
    nextItems.splice(targetIndex, 0, movedItem);
    templateLists.value = nextItems;

    await fetch('/api/panel/template-student-lists/reorder', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            ids: nextItems.map((item) => item.id),
            start_order: listMeta.value.from ?? 1,
        }),
    });

    await loadTemplateLists(listMeta.value.current_page);
}

watch(locale, (value) => {
    localStorage.setItem('kr-locale', value);
    document.documentElement.lang = value;
});

watch(theme, (value) => {
    localStorage.setItem('kr-panel-theme', value);
    document.documentElement.dataset.theme = value;
});

watch(() => templateForm.list_type, (value) => {
    if (value === 'kata' && ! templateForm.kata_type) templateForm.kata_type = 'personal';
});

watch(filters, scheduleLoad);

watch(examFilters, () => {
    window.clearTimeout(examFiltersTimer);
    examFiltersTimer = window.setTimeout(() => loadExams(1), 250);
});

watch(ratingFilters, () => {
    if (ratingFilters.discipline !== 'kumite') {
        ratingFilters.weight_category = '';
        ratingFilters.view_mode = 'all';
    }

    if (ratingFilters.view_mode === 'p4p') {
        ratingFilters.weight_category = '';
    }

    window.clearTimeout(ratingFiltersTimer);
    ratingFiltersTimer = window.setTimeout(() => loadRating(), 250);
});

watch(tournamentFilters, () => {
    window.clearTimeout(tournamentFiltersTimer);
    tournamentFiltersTimer = window.setTimeout(() => loadTournaments(1), 250);
});

watch(
    () => [organizationSettingsForm.can_edit_students, organizationSettingsForm.can_edit_coaches],
    scheduleOrganizationSettingsSave,
    { flush: 'sync' },
);

let accountRefreshTimer;
async function refreshAccount() {
    if (!authUser.value || document.hidden || !isPanel.value) return;
    try {
        const response = await fetch('/api/auth/user', { headers: { Accept: 'application/json' } });
        if (response.ok) {
            authUser.value = (await response.json()).user;
            if (authUser.value.agreements_required && panelSection.value !== 'documents') openRoute(location.pathname + location.search);
        } else if (response.status === 401) window.location.assign('/');
    } catch { /* Keep the current screen available during temporary network failures. */ }
}
onBeforeUnmount(() => { clearInterval(accountRefreshTimer); window.removeEventListener('focus', refreshAccount); });
onMounted(() => {
    document.documentElement.lang = locale.value;
    document.documentElement.dataset.theme = theme.value;
    window.addEventListener('popstate', () => {
        currentPath.value = window.location.pathname;
        if (! externalFormToken.value && !isKataReturn.value) {
            loadUser().catch((error) => console.error(error));
        }
    });
    if (! externalFormToken.value && !isKataReturn.value) {
        loadUser();
    }
    accountRefreshTimer = window.setInterval(refreshAccount, 60000);
    window.addEventListener('focus', refreshAccount);
    if (currentPath.value === '/') {
        fetch('/api/auth/user', { headers: { Accept: 'application/json' } }).then(async (response) => {
            if (response.ok && currentPath.value === '/') { authUser.value = (await response.json()).user; openRoute(defaultPanelPath()); }
        }).catch(() => {});
    }
});
</script>

<template>
    <ExternalFormPage
        v-if="externalFormToken"
        :token="externalFormToken"
        :t="t"
    />

    <PanelLayout
        v-else-if="isPanel"
        :auth-user="authUser"
        :bottom-nav-items="bottomNavItems"
        :is-dark="isDark"
        :locale="locale"
        :logo-url="logoUrl"
        :nav-items="navItems"
        :panel-section="panelSection"
        :panel-title="panelTitle"
        :t="t"
        :theme="theme"
        @logout="logout"
        @navigate="openRoute"
        @set-locale="setLocale"
        @toggle-theme="toggleTheme"
    >
        <AdminWorkspace v-if="adminSection && isAdmin" :section="adminSection" :locale="locale"/>
        <PanelTaskPage :key="currentPath" v-if="panelSection === 'tasks'" :id="currentPath.split('/').pop()" :locale="locale"/>
        <AccountDashboardPage v-if="panelSection === 'dashboard'" :t="t" :locale="locale" @navigate="openRoute"/>
        <StudentAccountPage v-if="panelSection === 'profile' && isStudent" :t="t" :locale="locale" @updated="Object.assign(authUser, $event)" @navigate="openRoute"/>
        <AccountProfilePage v-else-if="panelSection === 'profile'" :t="t" :locale="locale" @updated="Object.assign(authUser, $event)"/>
        <StudentEducationPage v-if="panelSection === 'education' && isStudent" :t="t" :locale="locale"/>
        <AccountNotificationsPage v-if="panelSection === 'notifications'" :t="t" @unread="authUser && (authUser.unread_notifications = $event)"/>
        <AccountAgreementsPage v-if="panelSection === 'documents'" :key="currentPath" :path="currentPath" :t="t" :locale="locale" :required="Boolean(authUser?.agreements_required)" @navigate="openRoute" @accepted="acceptedAgreements"/>
        <TemplatesPage
            v-if="panelSection === 'templates'"
            :displayed-pages="displayedPages"
            :dragged-template-id="draggedTemplateId"
            :editing-template="editingTemplate"
            :filters="filters"
            :is-loading-lists="isLoadingLists"
            :list-meta="listMeta"
            :list-stats="listStats"
            :show-all-templates="showAllTemplates"
            :show-template-modal="showTemplateModal"
            :template-error="templateError"
            :template-form="templateForm"
            :template-lists="templateLists"
            :t="t"
            :visible-form-fields="visibleFormFields"
            @close-template-modal="showTemplateModal = false"
            @delete-template="deleteTemplate"
            @drop-template="dropTemplate"
            @duplicate-template="duplicateTemplate"
            @load-template-lists="loadTemplateLists"
            @open-template-modal="openTemplateModal"
            @reset-filters="resetFilters"
            @save-template="saveTemplate"
            @start-template-drag="startTemplateDrag"
            @toggle-show-all-templates="toggleShowAllTemplates"
        />

        <AboutPage
            v-if="panelSection === 'about'"
            :about-content="aboutContent"
            :logo-url="logoUrl"
            :t="t"
        />

        <OrganizationSettingsPage
            v-else-if="panelSection === 'settings'"
            :error="organizationSettingsError"
            :is-loading="isLoadingOrganizationSettings"
            :is-saving="isSavingOrganizationSettings"
            :settings="organizationSettings"
            :settings-form="organizationSettingsForm"
            :t="t"
        />

        <OrganizationApplicationsPage v-else-if="currentPath === '/panel/tournaments/applications'" :locale="locale" :t="t" />

        <ExternalFormEditorPage
            v-else-if="panelSection === 'tournaments' && currentExternalFormId"
            :key="currentExternalFormId"
            :championship-id="currentChampionshipId"
            :form-id="currentExternalFormId"
            :locale="locale"
            :t="t"
            @navigate="openRoute"
        />

        <TournamentDetailPage
            v-else-if="panelSection === 'tournaments' && currentTournamentId && currentTournament"
            :locale="locale"
            :championship-id="currentChampionshipId"
            :detail="tournamentDetail"
            :displayed-pages="displayedTournamentStudentPages"
            :filters="tournamentDetailFilters"
            :is-dark="isDark"
            :is-editing="isTournamentEdit"
            :is-loading="isLoadingTournaments"
            :on-attach-coaches="attachTournamentCoaches"
            :on-attach-lists="attachTournamentLists"
            :on-create-list="createTournamentList"
            :on-detach-coach="detachTournamentCoach"
            :on-detach-list="detachTournamentList"
            :on-reload-detail="reloadCurrentTournamentDetail"
            :on-update-student="updateTournamentStudent"
            :options="tournamentCreateOptions"
            :t="t"
            :tournament="currentTournament"
            @attach-coaches="attachTournamentCoaches"
            @attach-lists="attachTournamentLists"
            @change-page="setTournamentStudentPage"
            @change-per-page="setTournamentStudentPerPage"
            @detach-coach="detachTournamentCoach"
            @detach-list="detachTournamentList"
            @filter-students="filterTournamentStudents"
            @update-student="updateTournamentStudent"
            @create-list="createTournamentList"
            @reload-detail="loadTournaments(tournamentDetailFilters.page)"
        />

        <TournamentsPage
            v-else-if="panelSection === 'tournaments'"
            :locale="locale"
            @reload="loadTournaments(tournamentFilters.page)"
            :can-use-applications="authUser?.roles?.some(role => ['Organization', 'Secretary'].includes(role))"
            :championship="currentChampionship"
            :displayed-pages="displayedTournamentPages"
            :filters="tournamentFilters"
            :forms="tournamentForms"
            :is-loading="isLoadingTournaments"
            :is-detail="Boolean(currentChampionshipId)"
            :meta="tournamentMeta"
            :regions="tournamentRegions"
            :stats="tournamentStats"
            :t="t"
            :show-championship-modal="showChampionshipModal"
            :show-form-modal="showTournamentFormModal"
            :show-tournament-modal="showTournamentItemModal"
            :championship-error="championshipError"
            :championship-form="championshipForm"
            :form-error="tournamentFormError"
            :form-model="tournamentForm"
            :tournament-error="tournamentItemError"
            :tournament-form="tournamentItemForm"
            :tournament-options="tournamentCreateOptions"
            :tournaments="tournaments"
            @change-page="loadTournaments"
            @change-per-page="setTournamentPerPage"
            @close-championship-modal="closeChampionshipModal"
            @close-form-modal="closeTournamentFormModal"
            @close-tournament-modal="closeTournamentItemModal"
            @create-championship="createChampionship"
            @create-form="createTournamentForm"
            @create-tournament="createTournamentItem"
            @delete-championship="deleteChampionship"
            @delete-form="deleteTournamentForm"
            @import-form="importTournamentForm"
            @open-championship-modal="openChampionshipModal"
            @open-form-modal="openTournamentFormModal"
            @open-tournament-modal="openTournamentItemModal"
            @open-championship="openChampionship"
            @open-tournament-edit="openTournamentItemEdit"
            @open-tournament="openTournamentItem"
            @set-form-status="setTournamentFormStatus"
            @set-status="setTournamentStatus"
        />

        <ExaminationsPage
            v-else-if="panelSection === 'exams' && panelCapabilities.view_examinations && !currentExamId"
            :can-manage="Boolean(panelCapabilities.manage_examinations)"
            :cities="examCities"
            :coaches="examCoaches"
            :displayed-pages="displayedExamPages"
            :editing-exam="editingExam"
            :exam-error="examError"
            :exam-filters="examFilters"
            :exam-form="examForm"
            :exams="exams"
            :is-loading="isLoadingExams"
            :meta="examMeta"
            :show-exam-modal="showExamModal"
            :stats="examStats"
            :t="t"
            @change-page="loadExams"
            @change-per-page="setExamPerPage"
            @close-exam-modal="closeExamModal"
            @delete-exam="deleteExam"
            @open-exam="openExam"
            @open-exam-modal="openExamModal"
            @save-exam="saveExam"
        />

        <ExaminationDetailPage
            v-else-if="panelSection === 'exams' && panelCapabilities.view_examinations"
            :locale="locale"
            @refresh="loadPanelData"
            :can-manage="Boolean(panelCapabilities.manage_examinations)"
            :attach-options="attachOptions"
            :coaches="examCoaches"
            :displayed-pages="displayedExamStudentPages"
            :exam="currentExam"
            :exam-error="examError"
            :exam-form="examForm"
            :is-loading="isLoadingExamStudents"
            :meta="examStudentsMeta"
            :search="examStudentSearch"
            :selected-attach-ids="selectedAttachIds"
            :selected-coach="examSelectedCoach"
            :show-attach-modal="showAttachStudentsModal"
            :show-exam-modal="showExamModal"
            :students="examStudents"
            :t="t"
            @attach-students="attachStudents"
            @change-page="loadExamStudents"
            @change-per-page="setExamStudentPerPage"
            @close-attach-modal="closeAttachStudentsModal"
            @close-exam-modal="closeExamModal"
            :on-detach-student="detachExamStudent"
            @export="exportExamStudents"
            @open-attach-modal="openAttachStudentsModal"
            @open-exam-modal="openExamModal(currentExam)"
            @save-exam="saveExam"
            @set-coach="setExamStudentCoach"
            @set-search="setExamStudentSearch"
            @toggle-attach-student="toggleAttachStudent"
        />

        <RatingPage
            v-else-if="panelSection === 'rating'"
            :filters="ratingFilters"
            :is-loading="isLoadingRating"
            :result="ratingResult"
            :t="t"
        />

        <StudentDetailPage
            v-else-if="panelSection === 'team' && currentTeamStudentId"
            :detail="teamStudentDetail ?? {}"
            :is-loading="isLoadingTeamStudent"
            :t="t"
            @open-tournament="openStudentTournament"
            @update-document="updateStudentDocument"
        />

        <TrainerDetailPage
            v-else-if="panelSection === 'team' && currentTeamTrainerId"
            :displayed-pages="displayedTrainerStudentPages"
            :detail="teamTrainerDetail ?? {}"
            :filters="trainerStudentFilters"
            :is-loading="isLoadingTeamTrainer"
            :meta="trainerStudentsMeta"
            :t="t"
            @change-page="loadTeamTrainer"
            @change-per-page="setTrainerStudentPerPage"
            @detach-student="detachTrainerStudent"
            @open-student="openTeamStudent"
            @set-tournament="setTrainerStudentTournament"
        />

        <TeamPage
            v-else-if="panelSection === 'team'"
            :allowed-sections="panelCapabilities.team_sections ?? []"
            :active-section="teamSection"
            :displayed-pages="displayedTeamPages"
            :is-loading="isLoadingTeam"
            :meta="teamMeta"
            :rows="teamRows"
            :search="teamSearch"
            :sort="teamSort"
            :stats="teamStats"
            :delete-members="deleteTeamMembers"
            :t="t"
            :editing-team-member="editingTeamMember"
            :show-team-member-modal="showTeamMemberModal"
            :team-member-error="teamMemberError"
            :team-member-form="teamMemberForm"
            :show-trainer-invite-modal="showTrainerInviteModal"
            :trainer-invite-error="trainerInviteError"
            :trainer-invite-form="trainerInviteForm"
            :is-sending-trainer-invite="isSendingTrainerInvite"
            @add-trainer="addTrainer"
            @change-page="loadTeam"
            @change-per-page="setTeamPerPage"
            @close-team-member-modal="closeTeamMemberModal"
            @close-trainer-invite-modal="closeTrainerInviteModal"
            @delete-pending-invitation="deletePendingInvitation"
            @delete-trainer="deleteTrainer"
            @export="exportTeam"
            @open-team-member-modal="openTeamMemberModal"
            @open-trainer="openTeamTrainer"
            @open-student="openTeamStudent"
            @resend-pending-invitation="resendPendingInvitation"
            @save-team-member="saveTeamMember"
            @send-trainer-invite="sendTrainerInvite"
            @set-search="setTeamSearch"
            @set-section="setTeamSection"
            @set-sort="setTeamSort"
        />
    </PanelLayout>

    <OnlineKataReturnPage v-else-if="isKataReturn" :t="t" :logo-url="logoUrl" />

    <TrainerRegistrationPage v-else-if="isRegistration" :student-mode="currentPath.startsWith('/student/')" :t="t" :locale="locale" :logo-url="logoUrl" @set-locale="setLocale" />

    <PasswordRecoveryPage v-else-if="isPasswordRecovery" :t="t" :locale="locale" :logo-url="logoUrl" :reset="currentPath === '/reset-password'" @set-locale="setLocale"/>

    <LandingPage v-else-if="currentPath === '/'" :locale="locale" @set-locale="setLocale" @navigate="openRoute"/>

    <LoginPage
        v-else
        :fighters-url="fightersUrl"
        :form-error="formError"
        :is-submitting="isSubmitting"
        :locale="locale"
        :logo-url="logoUrl"
        :t="t"
        @login="submitLogin"
        @set-locale="setLocale"
    />

    <Transition name="app-toast">
        <div
            v-if="toast"
            aria-live="polite"
            class="app-toast"
            :class="`app-toast--${toast.type}`"
            role="status"
        >
            <span class="app-toast__icon" aria-hidden="true"></span>
            <span>{{ toast.message }}</span>
        </div>
    </Transition>
</template>
