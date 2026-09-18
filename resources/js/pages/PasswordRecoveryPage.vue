<script setup>
import { computed, ref } from 'vue';
import { ArrowLeft, Eye, EyeOff } from '@lucide/vue';
import { useAccountRequest } from '../composables/useAccountRequest';
const props = defineProps({ t: Object, locale: String, logoUrl: String, reset: Boolean });
defineEmits(['set-locale']);
const params = new URLSearchParams(window.location.search);
const email = ref(params.get('email') || ''), password = ref(''), confirmation = ref(''), show = ref(false), message = ref('');
const { busy, error, request, run } = useAccountRequest(() => props.t);
const title = computed(() => props.reset ? props.t.accountChangePassword : props.t.accountForgot);
function submit() {
    run(async () => {
        const payload = { email: email.value, locale: props.locale };
        if (props.reset) Object.assign(payload, { token: params.get('token') || '', password: password.value, password_confirmation: confirmation.value });
        const result = await request('/api/auth/' + (props.reset ? 'reset-password' : 'forgot-password'), payload);
        message.value = result.message; password.value = ''; confirmation.value = '';
    });
}
</script>
<template>
    <main class="account-auth">
        <header><a href="/" :title="t.accountLogin" :aria-label="t.accountLogin"><ArrowLeft :size="19"/></a><div class="account-language"><button type="button" :class="{ active: locale === 'ru' }" @click="$emit('set-locale', 'ru')">RU</button><button type="button" :class="{ active: locale === 'en' }" @click="$emit('set-locale', 'en')">EN</button></div></header>
        <section class="account-page"><img class="account-auth-logo" :src="logoUrl" alt="Karate Rating"><h1>{{ title }}</h1>
            <p v-if="error" class="account-error" role="alert">{{ error }}</p>
            <p v-if="message" class="account-success" role="status">{{ message }}</p>
            <form v-if="!message" class="account-profile" @submit.prevent="submit"><fieldset :disabled="busy">
                <label>{{ t.email }}<input v-model="email" type="email" required maxlength="255" autocomplete="email"></label>
                <template v-if="reset"><label>{{ t.accountNewPassword }}<span class="account-password"><input v-model="password" :type="show ? 'text' : 'password'" required minlength="8" maxlength="255" autocomplete="new-password"><button type="button" :title="show ? t.accountHidePassword : t.accountShowPassword" :aria-label="show ? t.accountHidePassword : t.accountShowPassword" @click="show = !show"><EyeOff v-if="show" :size="18"/><Eye v-else :size="18"/></button></span></label><label>{{ t.accountConfirmPassword }}<input v-model="confirmation" :type="show ? 'text' : 'password'" required minlength="8" maxlength="255" autocomplete="new-password"></label></template>
                <button type="submit" class="account-primary">{{ busy ? t.loading : (reset ? t.accountChangePassword : t.accountSendReset) }}</button>
            </fieldset></form>
            <a class="account-continue" href="/">{{ t.accountLogin }}</a><a v-if="reset && error" class="account-continue" href="/forgot-password">{{ t.accountSendReset }}</a>
        </section>
    </main>
</template>
