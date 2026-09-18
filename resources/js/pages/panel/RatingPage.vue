<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    filters: {
        type: Object,
        required: true,
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
    result: {
        type: Object,
        default: null,
    },
    t: {
        type: Object,
        required: true,
    },
});

const openKey = ref(null);

const yearOptions = computed(() => {
    const current = new Date().getFullYear();

    return Array.from({ length: 5 }, (_, index) => String(current - index));
});

const showTrainerRanking = computed(() => {
    if (! props.result?.trainerRanking?.items?.length) return false;
    if (props.filters.discipline === 'kumite' && props.filters.view_mode !== 'all') return false;

    return ! props.filters.age_band
        && ! props.filters.gender
        && ! props.filters.organization_id
        && ! props.filters.region_id
        && (props.filters.discipline !== 'kumite' || ! props.filters.weight_category);
});

const cards = computed(() => {
    const groups = props.result?.groups ?? [];
    const trainer = showTrainerRanking.value ? [props.result.trainerRanking] : [];

    return [...trainer, ...groups];
});

function toggle(key) {
    openKey.value = openKey.value === key ? null : key;
}

function initials(name) {
    return name?.slice(0, 1).toUpperCase() || 'KR';
}
</script>

<template>
    <section class="rating-heading">
        <div>
            <h1>{{ t.ratingNav }}</h1>
        </div>
        <select v-model="filters.year" class="rating-year">
            <option v-for="year in yearOptions" :key="year" :value="year">{{ year }}</option>
        </select>
    </section>

    <section class="rating-mode-switch">
        <button
            type="button"
            :class="{ active: filters.discipline === 'kumite' }"
            @click="filters.discipline = 'kumite'"
        >
            {{ t.kumite }}
        </button>
        <button
            type="button"
            :class="{ active: filters.discipline === 'kata' }"
            @click="filters.discipline = 'kata'"
        >
            {{ t.kata }}
        </button>
    </section>

    <section class="rating-filters-card">
        <label>
            <span>{{ t.discipline }}</span>
            <select v-model="filters.discipline">
                <option value="kumite">{{ t.kumite }}</option>
                <option value="kata">{{ t.kata }}</option>
            </select>
        </label>

        <label v-if="filters.discipline === 'kumite'">
            <span>{{ t.ratingType }}</span>
            <select v-model="filters.view_mode">
                <option value="all">{{ t.allCategories }}</option>
                <option value="p4p">P4P</option>
            </select>
        </label>

        <label v-if="filters.discipline === 'kumite' && filters.view_mode !== 'p4p'">
            <span>{{ t.weightCategory }}</span>
            <select v-model="filters.weight_category">
                <option value="">{{ t.allCategories }}</option>
                <option v-for="(label, value) in result?.weightOptions ?? {}" :key="value" :value="value">{{ label }}</option>
            </select>
        </label>

        <label>
            <span>{{ t.age }}</span>
            <select v-model="filters.age_band">
                <option value="">{{ t.allAges }}</option>
                <option v-for="(label, value) in result?.ageBandOptions ?? {}" :key="value" :value="value">{{ label }}</option>
            </select>
        </label>

        <label>
            <span>{{ t.gender }}</span>
            <select v-model="filters.gender">
                <option value="">{{ t.bothGenders }}</option>
                <option value="m">{{ t.maleShort }}</option>
                <option value="f">{{ t.femaleShort }}</option>
            </select>
        </label>

        <label>
            <span>{{ t.organization }}</span>
            <select v-model="filters.organization_id">
                <option value="">{{ t.allOrganizations }}</option>
                <option v-for="(label, value) in result?.organizationOptions ?? {}" :key="value" :value="value">{{ label }}</option>
            </select>
        </label>

        <label>
            <span>{{ t.region }}</span>
            <select v-model="filters.region_id">
                <option value="">{{ t.allRegions }}</option>
                <option v-for="(label, value) in result?.regionOptions ?? {}" :key="value" :value="value">{{ label }}</option>
            </select>
        </label>
    </section>

    <section v-if="isLoading" class="rating-empty-card">{{ t.loading }}</section>

    <section v-else-if="cards.length === 0" class="rating-empty-card">
        <h2>{{ t.ratingEmpty }}</h2>
    </section>

    <section v-else class="rating-grid-v2">
        <article
            v-for="card in cards"
            :key="card.key"
            class="rating-card-v2"
            :class="{ featured: openKey === card.key, trainers: card.key === 'coach-rating' }"
        >
            <header>
                <div>
                    <h2>{{ card.name }}</h2>
                    <p>{{ card.subtitle }}</p>
                </div>
                <button type="button" class="rating-card-toggle" @click="toggle(card.key)">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
            </header>

            <div v-if="card.leader" class="rating-leader-v2">
                <span class="rating-avatar">
                    <img v-if="card.leader.avatar_url" :src="card.leader.avatar_url" alt="">
                    <b v-else>{{ initials(card.leader.full_name) }}</b>
                </span>
                <div>
                    <strong>{{ card.leader.full_name }}</strong>
                    <small>{{ card.leader.coach_label }}</small>
                </div>
                <span class="rating-cup">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8v3a4 4 0 0 1-8 0z"/><path d="M8 5H4v2a4 4 0 0 0 4 4"/><path d="M16 5h4v2a4 4 0 0 1-4 4"/><path d="M12 11v5"/><path d="M9 20h6"/><path d="M10 16h4"/></svg>
                </span>
                <span class="rating-score">{{ card.leader.rating_points }}</span>
            </div>

            <ol v-if="openKey === card.key" class="rating-list-v2">
                <li v-for="(item, index) in card.items" :key="item.student_id ?? item.coach_id">
                    <span>{{ index + 1 }}</span>
                    <div>
                        <strong>{{ item.full_name }}</strong>
                        <small>{{ item.coach_label }}<template v-if="item.summary"> · {{ item.summary }}</template></small>
                    </div>
                    <b>{{ item.rating_points }}</b>
                </li>
            </ol>

            <button v-if="openKey !== card.key" type="button" class="rating-expand" @click="toggle(card.key)">
                {{ t.showFullRating }}
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
            </button>
        </article>
    </section>
</template>
