<script setup>
import { computed, onMounted, ref, nextTick } from 'vue';
import { ArrowRight, CornerDownLeft, Trophy, ChartNoAxesColumnIncreasing, UserRound, UsersRound, FileText, Building2, Award, GraduationCap, BookOpen, Bell, Globe2, ChevronDown, X, Menu, Mail } from '@lucide/vue';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faInstagram, faYoutube, faTelegram } from '@fortawesome/free-brands-svg-icons';
import LandingBrand from '../components/landing/LandingBrand.vue';
import LandingStoreLinks from '../components/landing/LandingStoreLinks.vue';
import LandingChecklist from '../components/landing/LandingChecklist.vue';
import LandingBeltIcon from '../components/landing/LandingBeltIcon.vue';
import { storeLink } from '../components/landing/storeLinks';
import { landingLabels } from '../i18n/landing';
import '../styles/landing.css';

const props = defineProps({ locale: String });
const emit = defineEmits(['set-locale', 'navigate']);
const t = computed(() => landingLabels[props.locale] || landingLabels.ru);
const anchors = ['features', 'tournaments', 'examinations', 'learning', 'community', 'about'];
const detailAssets = ['examination-belt-kyokushin', 'learning-phone-kyokushin', 'community-phone-kyokushin', 'notification-phone-kyokushin'];
const calligraphyAsset = '/assets/landing/kyokushinkai-symbol.webp';
const detailIds = ['examinations', 'learning', 'community', 'extras'];
const socials = [{ name: 'Instagram', icon: faInstagram }, { name: 'YouTube', icon: faYoutube }, { name: 'Telegram', icon: faTelegram }];
const links = ref({}), document = ref(null), documentError = ref(''), loading = ref(false), dialog = ref(null), modal = ref(null), mobileMenu = ref(false), selectedFeature = ref(-1);
let documentRequest = 0;
onMounted(async () => {
    try {
        const response = await fetch('/api/public/app-links', { headers: { Accept: 'application/json' } });
        if (response.ok) links.value = await response.json();
    } catch { /* Unconfigured store links open the publication status. */ }
});
const email = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(links.value.contact_email || '') ? links.value.contact_email : null);
const feature = computed(() => selectedFeature.value < 0 ? t.value.tournament : t.value.details[selectedFeature.value]);
const modalTitle = computed(() => modal.value === 'document' ? document.value?.title : modal.value === 'feature' ? feature.value.label : ({ app: t.value.appTitle, faq: t.value.faq, contacts: t.value.contacts })[modal.value]);
async function openModal(kind) {
    mobileMenu.value = false;
    modal.value = kind;
    await nextTick();
    if (!dialog.value.open) dialog.value.showModal();
}
function openFeature(index) { selectedFeature.value = index; openModal('feature'); }
function closeModal() { ++documentRequest; dialog.value.close(); modal.value = null; }
async function openDocument(id) {
    const ticket = ++documentRequest;
    documentError.value = ''; document.value = null; loading.value = true;
    await openModal('document');
    try {
        const response = await fetch(`/api/public/agreements/${id}`, { headers: { Accept: 'application/json', 'Accept-Language': props.locale } });
        if (!response.ok) throw new Error();
        const result = await response.json();
        if (ticket === documentRequest) document.value = result;
    } catch { if (ticket === documentRequest) documentError.value = t.value.error; }
    finally { if (ticket === documentRequest) loading.value = false; }
}
function login() { if (dialog.value?.open) closeModal(); emit('navigate', '/login'); }
function switchLocale() { emit('set-locale', props.locale === 'ru' ? 'en' : 'ru'); }
</script>

<template>
<main class="kr-landing">
    <section class="landing-hero">
        <img class="landing-hero-image" :src="'/assets/landing/hero-unity-kyokushin.webp'" alt="" fetchpriority="high" width="1672" height="941">
        <header class="landing-nav landing-container">
            <LandingBrand/>
            <nav class="landing-desktop-nav" :aria-label="t.menu">
                <a v-for="(label, index) in t.nav" :key="anchors[index]" :href="'#' + anchors[index]">{{ label }}</a>
                <button @click="openModal('faq')">{{ t.faq }}</button>
            </nav>
            <div class="landing-nav-actions">
                <button class="landing-language" :aria-label="t.language" @click="switchLocale"><Globe2/><span>{{ locale.toUpperCase() }}</span><ChevronDown/></button>
                <a class="landing-primary landing-header-download" href="#app">{{ t.download }}</a>
                <button class="landing-menu-button" :aria-label="t.menu" :aria-expanded="mobileMenu" @click="mobileMenu = !mobileMenu"><component :is="mobileMenu ? X : Menu"/></button>
            </div>
            <nav v-if="mobileMenu" class="landing-mobile-nav" :aria-label="t.menu">
                <a v-for="(label, index) in t.nav" :key="anchors[index]" :href="'#' + anchors[index]" @click="mobileMenu = false">{{ label }}</a>
                <button @click="openModal('faq')">{{ t.faq }}</button><a href="/login" @click.prevent="login">{{ t.login }}</a>
            </nav>
        </header>
        <div class="landing-hero-stage landing-container">
            <div class="landing-hero-content">
                <p class="landing-eyebrow">{{ t.eyebrow }}</p>
                <h1>{{ t.hero }}<span>{{ t.heroAccent }}</span></h1>
                <p class="landing-hero-description">{{ t.intro }}</p>
                <LandingStoreLinks :t="t" :links="links" @unavailable="openModal('app')"/>
                <div class="landing-stats"><div v-for="(number, index) in ['3 000+', '50+', '40+', '4+']" :key="number" class="landing-stat"><strong>{{ number }}</strong><span>{{ t.stats[index] }}</span></div></div>
            </div>
            <img class="landing-hero-kanji" :src="calligraphyAsset" alt="" width="88" height="160">
            <aside class="landing-discipline" aria-hidden="true"><img class="landing-kanji" :src="calligraphyAsset" alt="" width="58" height="110"><span>DISCIPLINE</span><span>RESPECT</span><span>PROGRESS</span><span>BETTER YOU</span><i/><span class="landing-script">{{ t.handwritten }}</span></aside>
        </div>
    </section>

    <section id="audience" class="landing-roles">
        <div class="landing-container">
            <div class="landing-section-heading"><p class="landing-eyebrow">{{ t.rolesEyebrow }}</p><h2>{{ t.rolesTitle }}</h2><p>{{ t.rolesText }}</p></div>
            <div class="landing-role-grid">
                <button v-for="(role, index) in t.roles" :key="index" class="landing-role" :class="'landing-tone-' + index" @click="index < 2 ? openModal('app') : login()">
                    <span class="landing-role-icon"><component :is="[UserRound, UsersRound, FileText, Building2][index]"/></span><h3>{{ role[0] }}</h3><p>{{ role[1] }}</p><ArrowRight class="landing-card-arrow"/>
                </button>
            </div>
        </div>
    </section>

    <section id="features" class="landing-platform">
        <div class="landing-container">
            <div class="landing-platform-heading"><div><p class="landing-eyebrow">{{ t.platformEyebrow }}</p><h2>{{ t.platformTitle }}</h2></div><span class="landing-script" aria-hidden="true">{{ t.platformNote }}</span></div>
            <div class="landing-platform-grid">
                <a v-for="(item, index) in t.platform" :key="index" :href="'#' + anchors[index + 1]" class="landing-platform-item" :class="'landing-tone-' + index">
                    <component :is="[Trophy, LandingBeltIcon, GraduationCap, UsersRound][index]" class="landing-platform-icon"/><h3>{{ item[0] }}</h3><p>{{ item[1] }}</p><ArrowRight class="landing-card-arrow"/>
                </a>
            </div>
        </div>
    </section>

    <section id="tournaments" class="landing-tournaments">
        <img class="landing-tournament-art" :src="'/assets/landing/tournament-overview-kyokushin.webp'" :alt="t.tournament.alt" width="1448" height="1086" loading="lazy">
        <div class="landing-container"><div class="landing-tournament-copy"><p class="landing-label"><Trophy/>{{ t.tournament.label }}</p><h2>{{ t.tournament.title }}</h2><LandingChecklist :items="t.tournament.items"/><button class="landing-primary" @click="openFeature(-1)">{{ t.tournament.action }}<ArrowRight/></button></div></div>
    </section>

    <div class="landing-detail-grid">
        <section v-for="(item, index) in t.details" :key="detailIds[index]" :id="detailIds[index]" class="landing-detail" :class="'landing-detail-' + index">
            <img class="landing-detail-art" :src="`/assets/landing/${detailAssets[index]}.webp`" :alt="item.alt" :width="index === 0 ? 1086 : 1024" :height="index === 0 ? 1448 : 1536" loading="lazy">
            <div class="landing-detail-copy"><p class="landing-label"><component :is="[LandingBeltIcon, BookOpen, UsersRound, Bell][index]"/>{{ item.label }}</p><h2>{{ item.title }}</h2><LandingChecklist :items="item.items"/><button v-if="item.action" class="landing-outline" @click="openFeature(index)">{{ item.action }}<ArrowRight/></button></div>
        </section>
    </div>

    <section id="about" class="landing-about">
        <img class="landing-about-background" :src="'/assets/landing/mountain-path.webp'" alt="" width="2172" height="724" loading="lazy">
        <div class="landing-container landing-about-inner">
            <div class="landing-about-copy"><p class="landing-eyebrow">{{ t.aboutEyebrow }}</p><h2>{{ t.aboutTitle }}</h2><p>{{ t.aboutText }}</p><a class="landing-primary" href="#app">{{ t.join }}<ArrowRight/></a></div>
            <div class="landing-values"><div v-for="(value, index) in t.values" :key="index"><component :is="[ChartNoAxesColumnIncreasing, Award, UsersRound, Globe2][index]"/><span>{{ value }}</span></div></div>
            <aside class="landing-about-motto" aria-hidden="true"><img class="landing-kanji" :src="calligraphyAsset" alt="" width="58" height="110" loading="lazy"><span>KARATE</span><span>PEOPLE</span><span>TECHNOLOGY</span><span>FUTURE</span></aside>
        </div>
    </section>

    <section id="app" class="landing-download">
        <div class="landing-container landing-download-inner"><div class="landing-download-copy"><h2>{{ t.downloadTitle }}</h2><p>{{ t.downloadText }}</p><LandingStoreLinks :t="t" :links="links" @unavailable="openModal('app')"/></div><img :src="'/assets/landing/download-phones-moscow-kyokushin.webp'" :alt="t.phonesAlt" width="1134" height="1387" loading="lazy"><span class="landing-script" aria-hidden="true">{{ t.downloadNote }}<CornerDownLeft/></span></div>
    </section>

    <footer class="landing-footer"><div class="landing-container">
        <div class="landing-footer-top"><LandingBrand/><nav :aria-label="t.about"><a href="#about">{{ t.about }}</a><button @click="openModal('contacts')">{{ t.contacts }}</button><button @click="openDocument(2)">{{ t.privacy }}</button><button @click="openDocument(1)">{{ t.terms }}</button></nav><div class="landing-footer-actions"><button v-for="social in socials" :key="social.name" :aria-label="t.socialContact.replace('{network}', social.name)" :title="t.socialContact.replace('{network}', social.name)" @click="openModal('contacts')"><FontAwesomeIcon :icon="social.icon"/></button><button class="landing-language" :aria-label="t.language" @click="switchLocale">{{ locale.toUpperCase() }}<ChevronDown/></button></div></div>
        <div class="landing-footer-bottom"><p>© 2024 Kumite Rating. {{ t.copyright }}</p><span class="landing-script" aria-hidden="true">{{ t.osu }}</span></div>
    </div></footer>

    <dialog ref="dialog" class="landing-dialog" @cancel.prevent="closeModal" @click="event => event.target === dialog && closeModal()"><header><h2>{{ modalTitle }}</h2><button :aria-label="t.close" @click="closeModal"><X/></button></header>
        <template v-if="modal === 'document'"><div v-if="loading" class="landing-loading" role="status" :aria-label="t.loading"/><p v-else-if="documentError" role="alert">{{ documentError }}</p><div v-else class="landing-document-text" v-html="document?.content"/></template>
        <template v-else-if="modal === 'app'"><p>{{ t.appText }}</p><LandingStoreLinks :t="t" :links="links" status-only/><p v-if="!storeLink(links.ios) || !storeLink(links.android)" class="landing-publication-status" role="status">{{ t.soon }}</p><a href="/login" class="landing-panel-link" @click.prevent="login">{{ t.panel }}<ArrowRight/></a></template>
        <template v-else-if="modal === 'feature'"><h3>{{ feature.title }}</h3><LandingChecklist :items="feature.items"/><a class="landing-primary" href="#app" @click="closeModal">{{ t.download }}<ArrowRight/></a><a href="/login" class="landing-panel-link" @click.prevent="login">{{ t.panel }}<ArrowRight/></a></template>
        <template v-else-if="modal === 'contacts'"><a v-if="email" class="landing-contact" :href="'mailto:' + email"><Mail/>{{ email }}</a><p v-else>{{ t.error }}</p></template>
        <div v-else-if="modal === 'faq'" class="landing-faq"><details v-for="item in t.faqItems" :key="item[0]"><summary>{{ item[0] }}</summary><p>{{ item[1] }}</p></details></div>
    </dialog>
</main>
</template>
