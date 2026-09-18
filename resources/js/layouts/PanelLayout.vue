<script setup>
import NavIcon from '../components/panel/NavIcon.vue';
import { Bell, Menu, X } from '@lucide/vue';
import { computed, ref, onBeforeUnmount } from 'vue';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    bottomNavItems: {
        type: Array,
        required: true,
    },
    isDark: {
        type: Boolean,
        required: true,
    },
    locale: {
        type: String,
        required: true,
    },
    logoUrl: {
        type: String,
        required: true,
    },
    navItems: {
        type: Array,
        required: true,
    },
    panelSection: {
        type: String,
        required: true,
    },
    panelTitle: {
        type: String,
        required: true,
    },
    t: {
        type: Object,
        required: true,
    },
    theme: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['logout', 'navigate', 'set-locale', 'toggle-theme']);
const mobileMenu = ref(null);
const menuOpen = ref(false);
const primaryItems = computed(() => props.navItems.filter(item => (props.authUser?.roles?.includes('Student')
    ? ['profile', 'tournaments', 'rating'] : props.authUser?.capabilities?.super_admin ? ['admin-feed', 'admin-organizations', 'admin-activity'] : ['dashboard', 'tournaments', 'team']).includes(item.key)));
function openMenu() {
    mobileMenu.value.showModal();
    menuOpen.value = true;
    document.documentElement.classList.add('panel-menu-open');
}
function closeMenu() {
    mobileMenu.value?.close();
    menuOpen.value = false;
    document.documentElement.classList.remove('panel-menu-open');
}
function navigateMobile(path) { closeMenu(); emit('navigate', path); }
onBeforeUnmount(closeMenu);
</script>

<template>
    <main class="panel-app" :class="{ 'is-dark': isDark }">
        <aside class="panel-sidebar">
            <div class="sidebar-logo">
                <img :src="logoUrl" alt="Karate Rating">
            </div>

            <nav class="sidebar-nav" aria-label="Panel">
                <button
                    v-for="item in navItems"
                    :key="item.key"
                    type="button"
                    :class="{ active: panelSection === item.key }"
                    @click="item.path && emit('navigate', item.path)"
                >
                    <NavIcon :name="item.icon"/>
                    {{ item.label }}
                </button>
            </nav>

            <nav class="sidebar-nav sidebar-nav-bottom" aria-label="Secondary">
                <button
                    v-for="item in bottomNavItems"
                    :key="item.key"
                    type="button"
                    :class="{ active: panelSection === item.key }"
                    @click="emit('navigate', item.path)"
                >
                    <NavIcon :name="item.icon"/>
                    {{ item.label }}
                </button>
            </nav>

            <div class="sidebar-user">
                <div class="avatar">{{ (authUser?.name ?? 'KR').slice(0, 2).toUpperCase() }}</div>
                <div>
                    <strong>{{ authUser?.name ?? 'Karate Rating' }}</strong>
                    <span>{{ (authUser?.roles ?? []).map(role => t.accountRoles[role] ?? role).join(', ') }}</span>
                </div>
            </div>
        </aside>

        <section class="panel-main">
            <header class="panel-topbar">
                <div class="breadcrumbs">
                    <span class="home-dot">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M3 11l9-8 9 8"/>
                            <path d="M5 10v10h14V10"/>
                        </svg>
                    </span>
                    <span>{{ t.home }}</span>
                    <span>›</span>
                    <strong>{{ panelTitle }}</strong>
                </div>

                <div class="top-actions">
                    <button v-if="authUser?.roles?.some(role => ['Organization', 'Secretary', 'Student'].includes(role))" type="button" class="panel-icon-button account-bell" :title="t.accountNotifications" :aria-label="t.accountNotifications" @click="emit('navigate', '/panel/notifications')"><Bell :size="19"/><span v-if="authUser.unread_notifications" class="account-bell-count">{{ authUser.unread_notifications > 99 ? '99+' : authUser.unread_notifications }}</span></button>
                    <button type="button" class="theme-switch" :aria-label="theme" @click="emit('toggle-theme')">
                        <span>
                            <svg v-if="!isDark" viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="12" cy="12" r="4"/>
                                <path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>
                            </svg>
                            <svg v-else viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M20 14.5A8 8 0 0 1 9.5 4a7 7 0 1 0 10.5 10.5z"/>
                            </svg>
                        </span>
                    </button>
                    <div class="panel-locale">
                        <button type="button" :class="{ active: locale === 'ru' }" @click="emit('set-locale', 'ru')">RU</button>
                        <button type="button" :class="{ active: locale === 'en' }" @click="emit('set-locale', 'en')">EN</button>
                    </div>
                    <button v-if="authUser?.roles?.some(role => ['Organization', 'Secretary', 'Student'].includes(role))" type="button" class="profile-pill" :title="t.accountProfile" :aria-label="t.accountProfile" @click="emit('navigate', '/panel/profile')"><img v-if="authUser.avatar" :src="authUser.avatar" alt="" @error="$event.target.style.display = 'none'"><span v-else>{{ (authUser?.name ?? 'KR').slice(0, 2).toUpperCase() }}</span></button>
                    <button type="button" class="panel-icon-button top-logout" :aria-label="t.logout" @click="emit('logout')">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M10 17l5-5-5-5"/>
                            <path d="M15 12H3"/>
                            <path d="M21 3v18"/>
                        </svg>
                    </button>
                </div>
            </header>

            <slot/>
        </section>

        <nav class="panel-mobile-nav" :aria-label="t.mobileNavigation">
            <button v-for="item in primaryItems" :key="item.key" type="button" :class="{ active: panelSection === item.key }" :aria-current="panelSection === item.key ? 'page' : undefined" @click="emit('navigate', item.path)"><NavIcon :name="item.icon"/><span>{{ item.label }}</span></button>
            <button type="button" :class="{ active: menuOpen || !primaryItems.some(item => item.key === panelSection) }" :aria-expanded="menuOpen" aria-controls="panel-mobile-menu" @click="openMenu"><Menu :size="21"/><span>{{ t.mobileMenu }}</span></button>
        </nav>
        <dialog id="panel-mobile-menu" ref="mobileMenu" class="panel-mobile-menu" :aria-label="t.mobileMenu" @cancel.prevent="closeMenu" @close="closeMenu">
            <header><img :src="logoUrl" alt="Karate Rating"><strong>{{ t.mobileMenu }}</strong><button type="button" :aria-label="t.mobileClose" @click="closeMenu"><X :size="22"/></button></header>
            <div class="panel-mobile-menu-content">
                <div class="mobile-menu-user"><strong>{{ authUser?.name }}</strong><small>{{ (authUser?.roles ?? []).map(role => t.accountRoles[role] ?? role).join(', ') }}</small></div>
                <nav :aria-label="t.mobileNavigation"><button v-for="item in [...navItems, ...bottomNavItems]" :key="item.key" type="button" :class="{ active: panelSection === item.key }" @click="navigateMobile(item.path)"><NavIcon :name="item.icon"/><span>{{ item.label }}</span></button></nav>
            </div>
            <button class="mobile-menu-logout" type="button" @click="closeMenu(); emit('logout')">{{ t.logout }}</button>
        </dialog>
    </main>
</template>

<style scoped>
.account-bell { position: relative; }
.account-bell-count { position: absolute; top: -4px; right: -5px; background: var(--panel-red); color: #fff; border-radius: 9px; font-size: 10px; line-height: 16px; min-width: 16px; padding: 0 3px; }
.profile-pill { overflow: hidden; padding: 0; }
.profile-pill img { width: 100%; height: 100%; object-fit: cover; }
</style>
