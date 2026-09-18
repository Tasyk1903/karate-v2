<script setup>
import { computed, onMounted, ref } from 'vue';
import { ArrowLeft, ChevronRight, Play, X } from '@lucide/vue';
import AccountPager from '../../components/panel/AccountPager.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
import { studentLabels } from '../../i18n/student';

const props = defineProps({ t: Object, locale: String });
const labels = computed(() => studentLabels[props.locale]);
const { request } = useAccountRequest(() => props.t);
const sections = ref([]), section = ref(''), category = ref(null), result = ref(null), error = ref(''), busy = ref(false), search = ref(''), playing = ref(null);
let revision = 0;
async function load(page = 1) {
    const current = ++revision;
    busy.value = true; error.value = '';
    try {
        const params = new URLSearchParams({ page, search: search.value });
        const data = await request(`/api/panel/student/education/catalog/${section.value}${category.value ? '/' + category.value.id : ''}?${params}`);
        if (current === revision) result.value = data;
    } catch (failure) { if (current === revision) error.value = failure.message; }
    finally { if (current === revision) busy.value = false; }
}
function select(value) { section.value = value; category.value = null; result.value = null; search.value = ''; playing.value = null; load(); }
function open(value) { category.value = value; result.value = null; load(); }
async function initialize() {
    busy.value = true; error.value = '';
    try {
        sections.value = (await request('/api/panel/student/education')).data;
        if (sections.value.length) select(sections.value[0]); else busy.value = false;
    } catch (failure) { error.value = failure.message; busy.value = false; }
}
onMounted(initialize);
</script>

<template>
    <section class="account-page education-page">
        <h1>{{ labels.education }}</h1>
        <nav class="education-tabs" aria-label="Education"><button v-for="item in sections" :key="item" type="button" :aria-pressed="section === item" @click="select(item)">{{ labels[item] }}</button></nav>
        <header v-if="category"><button type="button" :title="t.accountPrevious" @click="category = null; result = null; load()"><ArrowLeft :size="18"/></button><h2>{{ category.name }}</h2></header>
        <form v-else-if="section" class="education-search" @submit.prevent="load()"><input v-model="search" :placeholder="t.search" :aria-label="t.search" maxlength="100"><button type="submit" :disabled="busy">{{ t.search }}</button></form>
        <p v-if="error" class="account-error" role="alert">{{ error }} <button type="button" @click="section ? load(result?.current_page || 1) : initialize()">{{ t.accountRetry }}</button></p>
        <p v-if="busy">{{ t.loading }}</p>
        <p v-else-if="!result?.data?.length">{{ labels.empty }}</p>
        <div v-if="!category" class="education-categories"><button v-for="item in result?.data ?? []" :key="item.id" type="button" @click="open(item)"><span>{{ item.name }}</span><ChevronRight :size="18"/></button></div>
        <div v-else class="education-videos"><button v-for="item in result?.data ?? []" :key="item.id" type="button" :title="labels.play" @click="playing = item"><div class="education-poster"><img v-if="item.poster_url" :src="item.poster_url" alt="" loading="lazy" @error="$event.target.hidden = true"><Play :size="28"/></div><span>{{ item.title }}</span></button></div>
        <AccountPager :page="result?.current_page || 1" :last="result?.last_page || 1" :busy="busy" :t="t" @change="load"/>
        <div v-if="playing" class="modal-backdrop" @click.self="playing = null" @keydown.esc="playing = null"><section class="template-modal education-player" role="dialog" aria-modal="true" :aria-label="playing.title"><header><h2>{{ playing.title }}</h2><button type="button" :title="t.cancel" @click="playing = null"><X :size="20"/></button></header><video :key="playing.id" controls playsinline preload="metadata" :src="playing.video_url" :poster="playing.poster_url"/></section></div>
    </section>
</template>

<style scoped>
.education-tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:20px; }
.education-tabs button[aria-pressed=true] { background:var(--panel-red); color:white; border-color:var(--panel-red); }
.education-search, header { display:flex; align-items:center; gap:12px; margin-bottom:16px; }
.education-search input { min-width:0; flex:1; border:1px solid var(--panel-line); background:var(--panel-card-solid); color:inherit; border-radius:6px; padding:10px; }
h2 { font-size:17px; margin:0; overflow-wrap:anywhere; }
.education-categories { display:grid; }
.education-categories button { justify-content:space-between; text-align:left; padding:16px 12px; border-radius:0; border-width:0 0 1px; }
.education-categories span { overflow-wrap:anywhere; }
.education-videos { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; }
.education-videos button { display:flex; flex-direction:column; align-items:stretch; padding:0; overflow:hidden; text-align:left; }
.education-videos button > span { padding:12px; overflow-wrap:anywhere; }
.education-poster { position:relative; display:grid; place-items:center; aspect-ratio:16/9; background:var(--panel-soft); width:100%; }
.education-poster img { position:absolute; width:100%; height:100%; object-fit:cover; }
.education-poster svg { z-index:1; color:var(--panel-red); }
.education-player { width:min(860px,calc(100vw - 24px)); }
.education-player header { justify-content:space-between; }
.education-player video { width:100%; max-height:70dvh; background:#111; }
@media(max-width:600px) { .education-search input { font-size:16px; } .education-videos { grid-template-columns:minmax(0,1fr); } }
</style>
