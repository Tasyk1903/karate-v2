<script setup>
import PaginationBar from '../../components/panel/PaginationBar.vue';
import { computed, ref, watch } from 'vue';
import { Check, X } from '@lucide/vue';
import DocumentLightbox from '../../components/panel/DocumentLightbox.vue';

const props = defineProps({
    detail: {
        type: Object,
        required: true,
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
    t: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['update-document', 'open-tournament']);

const activeTab = ref('rating');
const tournamentRecordFilter = ref('');
const kumiteIconUrl = '/assets/panel/icons/kumite_tile.png';
const kataIconUrl = '/assets/panel/icons/kata_tile.png';
const recordIconUrl = '/assets/panel/icons/rating_feature.png';
const kyuDanIconUrl = '/assets/panel/icons/kyu_dan.png';
const genderIconUrl = '/assets/panel/icons/gender.png';
const heightIconUrl = '/assets/panel/icons/height.png';
const birthdayIconUrl = '/assets/panel/icons/birthday.png';
const weightIconUrl = '/assets/panel/icons/weight.png';
const clubIconUrl = '/assets/panel/icons/club.png';
const trainerIconUrl = '/assets/panel/icons/trainer.png';
const ageIconUrl = '/assets/panel/icons/age.png';
const medalIcons = {
    gold: '/assets/panel/icons/gold_medal.png',
    silver: '/assets/panel/icons/silver_medal.png',
    bronze: '/assets/panel/icons/bronze_medal.png',
};
const documentModal = ref(null);
const previewDocument = ref(null);
const documentForm = ref({});
const documentError = ref('');
const isSavingDocument = ref(false);
const avatarFailed = ref(false);
const student = computed(() => props.detail?.student ?? {});
const rating = computed(() => props.detail?.rating ?? {});
const documentPayload = computed(() => props.detail?.documents ?? {});
const documents = computed(() => Array.isArray(documentPayload.value) ? documentPayload.value : (documentPayload.value.documents ?? []));
const documentRows = computed(() => Array.isArray(documentPayload.value) ? [] : (documentPayload.value.rows ?? []));
const documentFields = {
    insurance: ['insuranceCloseDate'],
    ikoCard: ['ikoNumber'],
    certificate: ['certificateNumber'],
    brand: ['brandNumber'],
};
const documentCards = computed(() => documents.value.map((document, index) => ({
    ...document,
    fields: documentRows.value.flat().filter((cell) => cell?.label && documentFields[document.key]?.includes(cell.label))
        // The legacy rows contract identifies check-inclusion fields by document column.
        .concat(documentRows.value.map((row) => row[index]).filter((cell) => cell?.label === 'includedInDocumentCheck')),
})));
const examinationFields = computed(() => documentRows.value.flat().filter((cell) =>
    ['lastExamDate', 'lastExamCity', 'lastReceiving'].includes(cell?.label)));
const historyData = ref(null);
const historyError = ref('');
const historyLoading = ref(false);
const historyPage = ref(1);
const historyPerPage = ref(20);
let historyRequest = 0;
const tournaments = computed(() => historyData.value?.data ?? props.detail?.tournaments ?? []);
const fightRecords = computed(() => props.detail?.fight_records ?? { wins: [], losses: [] });
const visibleFightRecords = computed(() => historyData.value?.data ?? (fightRecords.value[tournamentRecordFilter.value] ?? []));
const historyMeta = computed(() => { const m=historyData.value?.meta ?? {current_page:1,last_page:1,total:0}; return {...m,per_page:historyPerPage.value,from:m.total?(m.current_page-1)*historyPerPage.value+1:0,to:Math.min(m.total,m.current_page*historyPerPage.value)}; });
async function loadHistory(page=1) {
 const revision=++historyRequest;
 historyLoading.value=true;historyError.value='';
 try {
  const params=new URLSearchParams({kind:tournamentRecordFilter.value||'tournaments',page,per_page:historyPerPage.value});
  const response=await fetch(`/api/panel/team/students/${student.value.id}/history?${params}`,{headers:{Accept:'application/json'}});
  const data=await response.json();if(!response.ok)throw new Error(data.message||props.t.validationError);
  if(revision===historyRequest){historyData.value=data;historyPage.value=page;}
 }catch(e){if(revision===historyRequest)historyError.value=e.message;}
 finally{if(revision===historyRequest)historyLoading.value=false;}
}
watch([()=>student.value.id, tournamentRecordFilter, activeTab],()=>{historyData.value=null;++historyRequest;if(activeTab.value==='tournaments'&&student.value.id)loadHistory();});
const tabs = computed(() => [
    { key: 'rating', label: props.t.ratingNav, icon: 'rating' },
    { key: 'documents', label: props.t.documents, icon: 'documents' },
    { key: 'tournaments', label: props.t.tournaments, icon: 'trophy' },
]);

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

watch(student, () => {
    avatarFailed.value = false;
});

function openDocumentAction(document) {
    if (props.detail.can_confirm_documents === false) return;
    if (document.action_type === 'toggle') {
        const [field, value] = Object.entries(document.form ?? {})[0] ?? [];
        if (! field) return;
        emit('update-document', {
            document: document.key,
            values: { [field]: !value },
        });
        return;
    }

    documentError.value = '';
    documentModal.value = document;
    documentForm.value = { ...(document.form ?? {}) };
}

function closeDocumentModal() {
    documentModal.value = null;
    documentForm.value = {};
    documentError.value = '';
}

function openDocumentPreview(document) {
    if (! document?.file) return;

    previewDocument.value = document;
}

function closeDocumentPreview() {
    previewDocument.value = null;
}

function openTournamentRecord(type) {
    tournamentRecordFilter.value = type;
    activeTab.value = 'tournaments';
}

function showAllTournaments() {
    tournamentRecordFilter.value = '';
}

function openTournament(tournament) {
    if (! tournament?.can_open || ! tournament?.id || ! tournament?.championship_id) return;

    emit('open-tournament', tournament);
}

function openFightTournament(record) {
    openTournament(record?.tournament);
}

function saveDocument() {
    if (! documentModal.value) return;

    isSavingDocument.value = true;
    documentError.value = '';

    emit('update-document', {
        document: documentModal.value.key,
        values: documentForm.value,
    });
    isSavingDocument.value = false;
    closeDocumentModal();
}
</script>

<template>
    <section v-if="isLoading" class="student-detail-loading">{{ t.loading }}</section>

    <section v-else class="student-detail">
        <article class="student-hero-card">
            <div class="student-photo-panel">
                <img
                    v-if="student.avatar && !avatarFailed"
                    :src="student.avatar"
                    :alt="student.full_name"
                    @error="avatarFailed = true"
                >
                <span v-else>{{ initials(student) }}</span>
            </div>

            <div class="student-profile-main">
                <h1>{{ student.full_name }}</h1>

                <div class="student-profile-lines">
                    <span>
                        <img class="student-line-icon" :src="clubIconUrl" :alt="t.club">
                        {{ t.club }}:
                        <b>{{ student.club ?? '-' }}</b>
                    </span>
                    <span>
                        <img class="student-line-icon" :src="trainerIconUrl" :alt="t.trainer">
                        {{ t.trainer }}:
                        <b>{{ student.coach_name ?? '-' }}</b>
                    </span>
                </div>

                <div class="student-belt" :style="{ '--belt-main': student.belt?.color, '--belt-accent': student.belt?.accent }">
                    <i></i>
                    <div>
                        <strong>{{ student.rang ?? '-' }}</strong>
                        <span>{{ t[student.belt?.label_key] ?? t.beltNotSet }}</span>
                    </div>
                </div>
            </div>

            <div class="student-profile-stats">
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="ageIconUrl" :alt="t.age">
                    <b>{{ student.age_label ?? '-' }}</b>
                    <span>{{ t.age }}</span>
                </div>
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="genderIconUrl" :alt="t.gender">
                    <b>{{ genderLabel(student.gender) }}</b>
                    <span>{{ t.gender }}</span>
                </div>
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="birthdayIconUrl" :alt="t.birthday">
                    <b>{{ student.birthday ?? '-' }}</b>
                    <span>{{ t.birthday }}</span>
                </div>
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="heightIconUrl" :alt="t.height">
                    <b>{{ formatWithUnit(student.height, t.cm) }}</b>
                    <span>{{ t.height }}</span>
                </div>
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="weightIconUrl" :alt="t.weight">
                    <b>{{ formatWithUnit(student.weight, t.kg) }}</b>
                    <span>{{ t.weight }}</span>
                </div>
                <div class="student-info-chip">
                    <img class="student-info-icon" :src="kyuDanIconUrl" :alt="t.kyuDan">
                    <b>{{ student.rang ?? '-' }}</b>
                    <span>{{ t.kyuDan }}</span>
                </div>
            </div>
        </article>

        <article class="student-section-card">
            <nav v-responsive-tabs class="student-detail-tabs">
                        <button
                            v-for="tab in tabs"
                            :key="tab.key"
                            type="button"
                            :class="{ active: activeTab === tab.key }"
                            @click="activeTab = tab.key; if (tab.key !== 'tournaments') showAllTournaments()"
                        >
                    <svg v-if="tab.icon === 'rating'" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-3"/><path d="M8 8l4-4 4 4"/></svg>
                    <svg v-else-if="tab.icon === 'documents'" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v6h5"/><path d="M9 14h8"/><path d="M9 18h6"/></svg>
                    <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v4a5 5 0 0 1-10 0V4z"/><path d="M7 6H4c0 3 1.5 5 4 5"/><path d="M17 6h3c0 3-1.5 5-4 5"/></svg>
                    {{ tab.label }}
                </button>
            </nav>

            <div v-if="activeTab === 'rating'" class="student-rating-grid">
                <div class="student-rating-card kumite">
                    <div class="student-rating-title">
                        <img class="student-rating-icon" :src="kumiteIconUrl" :alt="t.kumite">
                        <div><span>{{ t.kumite }}</span><small>{{ t.ratingNav }} {{ rating.kumite?.year }}</small></div>
                    </div>
                    <strong>{{ rating.kumite?.label ?? '-' }}</strong>
                    <b>{{ rating.kumite?.points ?? 0 }} {{ t.points }}</b>
                    <p>{{ rating.kumite?.subtitle ?? rating.kumite?.group ?? '-' }}</p>
                    <div class="student-medals-row">
                        <span class="gold"><img :src="medalIcons.gold" :alt="t.gold">{{ t.gold }} <b>{{ rating.kumite?.medals?.gold ?? 0 }}</b></span>
                        <span class="silver"><img :src="medalIcons.silver" :alt="t.silver">{{ t.silver }} <b>{{ rating.kumite?.medals?.silver ?? 0 }}</b></span>
                        <span class="bronze"><img :src="medalIcons.bronze" :alt="t.bronze">{{ t.bronze }} <b>{{ rating.kumite?.medals?.bronze ?? 0 }}</b></span>
                    </div>
                </div>

                <div class="student-rating-card kata">
                    <div class="student-rating-title">
                        <img class="student-rating-icon" :src="kataIconUrl" :alt="t.kata">
                        <div><span>{{ t.kata }}</span><small>{{ t.ratingNav }} {{ rating.kata?.year }}</small></div>
                    </div>
                    <strong>{{ rating.kata?.label ?? '-' }}</strong>
                    <b>{{ rating.kata?.points ?? 0 }} {{ t.points }}</b>
                    <p>{{ rating.kata?.subtitle ?? rating.kata?.group ?? '-' }}</p>
                    <div class="student-medals-row">
                        <span class="gold"><img :src="medalIcons.gold" :alt="t.gold">{{ t.gold }} <b>{{ rating.kata?.medals?.gold ?? 0 }}</b></span>
                        <span class="silver"><img :src="medalIcons.silver" :alt="t.silver">{{ t.silver }} <b>{{ rating.kata?.medals?.silver ?? 0 }}</b></span>
                        <span class="bronze"><img :src="medalIcons.bronze" :alt="t.bronze">{{ t.bronze }} <b>{{ rating.kata?.medals?.bronze ?? 0 }}</b></span>
                    </div>
                </div>

                <div class="student-record-card">
                    <div class="student-rating-title">
                        <img class="student-rating-icon" :src="recordIconUrl" :alt="t.record">
                        <span>{{ t.record }}</span>
                    </div>
                    <div class="record-score">
                        <button type="button" @click="openTournamentRecord('wins')">
                            <span>{{ t.wins }}</span>
                            <strong>{{ rating.record?.wins ?? 0 }}</strong>
                        </button>
                        <i>/</i>
                        <button type="button" @click="openTournamentRecord('losses')">
                            <span>{{ t.losses }}</span>
                            <strong>{{ rating.record?.losses ?? 0 }}</strong>
                        </button>
                    </div>
                    <small>{{ t.totalFights }}</small>
                    <b>{{ rating.record?.total ?? 0 }}</b>
                </div>
            </div>

            <div v-else-if="activeTab === 'documents'" class="student-documents-table">
                <slot name="documents">
                <div
                    v-for="document in documentCards"
                    :key="document.key"
                    class="student-document-card"
                    :data-document="document.key"
                >
                    <h3>{{ t[document.key] }}</h3>
                    <button
                        type="button"
                        class="student-document-preview"
                        :aria-label="`${t.view}: ${t[document.key]}`"
                        :disabled="!document.file"
                        @click="openDocumentPreview(document)"
                    >
                        <img v-if="document.file" :src="document.file" :alt="t[document.key]">
                        <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v6h5"/></svg>
                    </button>
                    <dl v-if="document.fields.length" class="student-document-fields">
                        <div v-for="field in document.fields" :key="field.label">
                            <dt>{{ t[field.label] ?? field.label }}</dt>
                            <dd>{{ t[field.value] ?? field.value }}</dd>
                        </div>
                    </dl>
                    <div class="document-action-cell">
                        <button
                            type="button"
                            class="document-status"
                            :disabled="detail.can_confirm_documents === false"
                            :aria-label="`${t[document.key]}: ${document.confirmed ? t.confirmed : t.notConfirmed}`"
                            :class="{ confirmed: document.confirmed }"
                            @click="openDocumentAction(document)"
                        >
                            <Check v-if="document.confirmed" :size="16" aria-hidden="true" />
                            <X v-else :size="16" aria-hidden="true" />
                            {{ document.confirmed ? t.confirmed : t.notConfirmed }}
                        </button>
                    </div>
                </div>
                <section v-if="examinationFields.length" class="student-examination-fields">
                    <h3>{{ t.lastExamination }}</h3>
                    <dl class="student-document-fields">
                        <div v-for="field in examinationFields" :key="field.label">
                            <dt>{{ t[field.label] ?? field.label }}</dt>
                            <dd>{{ t[field.value] ?? field.value }}</dd>
                        </div>
                    </dl>
                </section>
                </slot>
            </div>

            <div v-else class="student-tournaments-card">
                <div v-if="tournamentRecordFilter" class="student-tournament-filter">
                    <span>{{ tournamentRecordFilter === 'wins' ? t.winsTableTitle : t.lossesTableTitle }}</span>
                    <button type="button" @click="showAllTournaments">{{ t.all }}</button>
                </div>
                <p v-if="historyError" class="form-error" role="alert">{{ historyError }}</p>
                <table v-responsive-table>
                    <thead>
                        <tr v-if="tournamentRecordFilter">
                            <th>{{ t.photograph }}</th>
                            <th>{{ t.lastName }}</th>
                            <th>{{ t.firstName }}</th>
                            <th>{{ t.fightDate }}</th>
                            <th>{{ t.ageAtFight }}</th>
                            <th>{{ t.tournamentSingular }}</th>
                            <th>{{ t.pool }}</th>
                            <th>{{ t.opponentTrainer }}</th>
                        </tr>
                        <tr v-else>
                            <th>{{ t.tournamentSingular }}</th>
                            <th>{{ t.date }}</th>
                            <th>{{ t.tournamentType }}</th>
                            <th>{{ t.record }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-if="tournamentRecordFilter">
                            <tr v-if="visibleFightRecords.length === 0">
                                <td colspan="8" class="empty-cell">{{ t.emptyTournaments }}</td>
                            </tr>
                            <tr
                                v-for="record in visibleFightRecords"
                                :key="record.id"
                                :class="{ 'student-tournament-row': record.tournament?.can_open }"
                                :role="record.tournament?.can_open ? 'link' : undefined"
                                :tabindex="record.tournament?.can_open ? 0 : undefined"
                                @keydown.enter="openFightTournament(record)"
                                @click="openFightTournament(record)"
                            >
                                <td>
                                    <span class="student-fight-avatar">
                                        <img v-if="record.opponent?.avatar" :src="record.opponent.avatar" :alt="record.opponent.full_name">
                                        <b v-else>{{ initials(record.opponent ?? {}) }}</b>
                                    </span>
                                </td>
                                <td><strong>{{ record.opponent?.last_name ?? '-' }}</strong></td>
                                <td>{{ record.opponent?.first_name ?? '-' }}</td>
                                <td>{{ record.fight_date ?? '-' }}</td>
                                <td>{{ record.opponent?.age_at_fight ?? '-' }}</td>
                                <td>{{ record.tournament?.name ?? '-' }}</td>
                                <td>{{ record.pool ?? '-' }}</td>
                                <td>{{ record.opponent?.coach_name ?? '-' }}</td>
                            </tr>
                        </template>
                        <template v-else>
                            <tr v-if="tournaments.length === 0">
                                <td colspan="4" class="empty-cell">{{ t.emptyTournaments }}</td>
                            </tr>
                            <tr
                                v-for="tournament in tournaments"
                                :key="tournament.id"
                                :class="{ 'student-tournament-row': tournament.can_open }"
                                :role="tournament.can_open ? 'link' : undefined"
                                :tabindex="tournament.can_open ? 0 : undefined"
                                @keydown.enter="openTournament(tournament)"
                                @click="openTournament(tournament)"
                            >
                                <td><strong>{{ tournament.name }}</strong></td>
                                <td>{{ tournament.date ?? '-' }}</td>
                                <td>{{ tournament.type === 'kata' ? t.kata : t.kumite }}</td>
                                <td>{{ tournament.wins }} / {{ tournament.losses }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <PaginationBar v-if="historyData" :meta="historyMeta" :pages="[]" :rows-shown="t.rowsShown" :of="t.of" :rows-per-page="t.rowsPerPage" @change-page="loadHistory" @change-per-page="historyPerPage=$event;loadHistory()"/>
            </div>
        </article>

        <div v-if="documentModal" class="modal-backdrop" @click.self="closeDocumentModal">
            <form class="template-modal student-document-modal" @submit.prevent="saveDocument">
                <header>
                    <h2>{{ t[documentModal.key] }}</h2>
                    <button type="button" class="panel-icon-button" @click="closeDocumentModal">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </header>

                <p v-if="documentError" class="form-error">{{ documentError }}</p>

                <label v-if="documentModal.action_type === 'insurance'" class="modal-field full">
                    <span>{{ t.insuranceCloseDate }}</span>
                    <input v-model="documentForm.insurance_close_date" type="date">
                </label>

                <label v-if="documentModal.action_type === 'check'" class="modal-check-row document-check-row">
                    <input v-model="documentForm[documentModal.check_field]" type="checkbox">
                    <span>{{ t.needsTournamentDocumentCheck }}</span>
                </label>

                <label class="modal-check-row document-check-row">
                    <input
                        v-if="documentModal.key === 'insurance'"
                        v-model="documentForm.is_success_insurance"
                        type="checkbox"
                    >
                    <input
                        v-else-if="documentModal.key === 'ikoCard'"
                        v-model="documentForm.is_success_iko_card"
                        type="checkbox"
                    >
                    <input
                        v-else-if="documentModal.key === 'certificate'"
                        v-model="documentForm.is_success_certificate"
                        type="checkbox"
                    >
                    <span>{{ t.documentConfirmation }}</span>
                </label>

                <footer>
                    <button type="button" class="soft-button" @click="closeDocumentModal">{{ t.cancel }}</button>
                    <button type="submit" class="save-button" :disabled="isSavingDocument">{{ t.save }}</button>
                </footer>
            </form>
        </div>

        <DocumentLightbox v-if="previewDocument" :document="previewDocument" :t="t" @close="closeDocumentPreview" />
    </section>
</template>
