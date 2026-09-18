<script setup>
import { ref } from 'vue';

defineProps({
    fightersUrl: {
        type: String,
        required: true,
    },
    formError: {
        type: String,
        default: '',
    },
    isSubmitting: {
        type: Boolean,
        default: false,
    },
    locale: {
        type: String,
        required: true,
    },
    logoUrl: {
        type: String,
        required: true,
    },
    t: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['login', 'set-locale']);
const showPassword = ref(false);
const remember = ref(true);
const loginForm = ref({ email: '', password: '' });

function submit() {
    emit('login', {
        ...loginForm.value,
        remember: remember.value,
    });
}
</script>

<template>
    <main class="auth-page">
        <section class="auth-card" aria-labelledby="login-title">
            <aside class="auth-hero">
                <div class="brand-lockup">
                    <img :src="logoUrl" alt="Karate Rating" class="brand-mark">
                </div>
                <div class="hero-copy">
                    <h1>{{ t.headline }}</h1>
                    <p>{{ t.description }}</p>
                </div>
                <div class="hero-image-wrap" aria-hidden="true">
                    <img :src="fightersUrl" alt="" class="hero-image">
                </div>
                <div class="hero-points">
                    <div>
                        <span class="point-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l6.5 2.8v5.1c0 4.3-2.7 8-6.5 9.1-3.8-1.1-6.5-4.8-6.5-9.1V6.3L12 3.5z"/><path d="M9.2 12l1.9 1.9 3.9-4.5"/></svg></span>
                        <strong>{{ t.trust }}</strong>
                        <small>{{ t.trustText }}</small>
                    </div>
                    <div>
                        <span class="point-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.3"/><path d="M3.8 19.5c.7-3.2 2.8-5.1 5.2-5.1s4.5 1.9 5.2 5.1"/><circle cx="17.2" cy="9.7" r="2.4"/><path d="M14.8 16.1c.7-.8 1.5-1.2 2.5-1.2 1.6 0 2.9 1.1 3.4 3"/></svg></span>
                        <strong>{{ t.control }}</strong>
                        <small>{{ t.controlText }}</small>
                    </div>
                    <div>
                        <span class="point-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16"/><path d="M7 16v-4"/><path d="M12 16V9"/><path d="M17 16V6"/><path d="M6 10.5l4-3.2 3.2 2.5L19 4"/><path d="M15.6 4H19v3.4"/></svg></span>
                        <strong>{{ t.rating }}</strong>
                        <small>{{ t.ratingText }}</small>
                    </div>
                </div>
            </aside>
            <section class="auth-form-panel">
                <div class="toolbar">
                    <div class="locale-switch" aria-label="Language">
                        <button type="button" :class="{ active: locale === 'ru' }" @click="emit('set-locale', 'ru')">RU</button>
                        <button type="button" :class="{ active: locale === 'en' }" @click="emit('set-locale', 'en')">EN</button>
                    </div>
                </div>
                <form class="login-form" @submit.prevent="submit">
                    <div class="form-heading">
                        <h2 id="login-title">{{ t.loginTitle }}</h2>
                        <p>{{ t.loginSubtitle }}</p>
                    </div>
                    <label class="field">
                        <span>{{ t.email }}</span>
                        <span class="input-shell">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>
                            <input v-model="loginForm.email" type="email" autocomplete="email" :placeholder="t.emailPlaceholder">
                        </span>
                    </label>
                    <label class="field">
                        <span>{{ t.password }}</span>
                        <span class="input-shell">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11V8a5 5 0 0 1 10 0v3"/><path d="M6 11h12v9H6z"/></svg>
                            <input v-model="loginForm.password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" :placeholder="t.passwordPlaceholder">
                            <button type="button" class="ghost-icon" :aria-label="showPassword ? t.hidePassword : t.showPassword" @click="showPassword = !showPassword">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </span>
                    </label>
                    <div class="form-row">
                        <label class="check"><input v-model="remember" type="checkbox"><span></span>{{ t.remember }}</label>
                        <a href="/forgot-password">{{ t.forgot }}</a>
                    </div>
                    <button type="submit" class="submit-button">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11V8a5 5 0 0 1 10 0v3"/><path d="M6 11h12v9H6z"/></svg>
                        {{ isSubmitting ? '...' : t.submit }}
                    </button>
                    <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>
                    <div class="divider"><span></span><i></i><span></span></div>
                    <p class="register-link">{{ t.noAccount }} <a href="/panel/register">{{ t.register }}</a></p>
                </form>
            </section>
        </section>
    </main>
</template>
