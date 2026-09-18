<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ArrowLeft, Eye, EyeOff } from '@lucide/vue';

const props = defineProps({ t: Object, locale: String, logoUrl: String, studentMode: Boolean });
const emit = defineEmits(['set-locale']);
const form = reactive({ email: '', organization_code: '', first_name: '', last_name: '', password: '', password_confirmation: '', existing_account: false });
const verification = ref(false);
const completed = ref(false);
const code = ref('');
const busy = ref(false);
const error = ref('');
const reveal = ref(false);
const signedIn = ref(null);
onMounted(async () => {
    const response = await fetch('/api/auth/user', { headers: { Accept: 'application/json' } }).catch(() => null);
    if (response?.ok) signedIn.value = (await response.json()).user;
});
async function signOut() {
    const response = await fetch('/api/auth/logout', { method: 'POST', headers: { Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' } });
    if (response.ok) window.location.reload();
    else error.value = props.t.actionFailed;
}

async function submit() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    const data = verification.value ? { code: code.value } : { ...form };
    if (props.studentMode && !verification.value) { data.coach_code = data.organization_code; delete data.organization_code; }
    if (form.existing_account && !verification.value) {
        delete data.first_name;
        delete data.last_name;
        delete data.password_confirmation;
    }
    try {
        const response = await fetch(`/api/auth/${props.studentMode ? 'student' : 'trainer'}-registration${verification.value ? '/confirm' : ''}`, {
            method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            body: JSON.stringify({ ...data, locale: props.locale }),
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? props.t.actionFailed);
        if (payload.registered) completed.value = true;
        else if (payload.redirect) window.location.assign(payload.redirect);
        else verification.value = true;
    } catch (failure) {
        error.value = failure.message || props.t.actionFailed;
    } finally { busy.value = false; }
}
</script>

<template>
    <main class="trainer-registration">
        <header>
            <a href="/" class="panel-icon-button" :aria-label="t.loginTitle"><ArrowLeft :size="20" /></a>
            <img :src="logoUrl" alt="Karate Rating">
            <div class="locale-switch">
                <button v-for="language in ['ru', 'en']" :key="language" :class="{ active: locale === language }" @click="emit('set-locale', language)">{{ language.toUpperCase() }}</button>
            </div>
        </header>
        <section v-if="completed"><h1>{{ t.studentRegistrationDone }}</h1><p>{{ t.studentRegistrationAttached }}</p></section>
        <section v-else-if="signedIn">
            <h1>{{ t.loginTitle }}: {{ signedIn.email }}</h1>
            <a href="/panel">{{ t.home }}</a>
            <button type="button" class="soft-button" @click="signOut">{{ t.logout }}</button>
        </section>
        <form v-else @submit.prevent="submit">
            <h1>{{ verification ? t.verifyEmail : studentMode ? t.studentRegistration : t.coachRegistration }}</h1>
            <template v-if="!verification">
                <div class="locale-switch registration-mode">
                    <button type="button" :class="{ active: !form.existing_account }" @click="form.existing_account = false">{{ t.newAccount }}</button>
                    <button type="button" :class="{ active: form.existing_account }" @click="form.existing_account = true">{{ t.existingAccount }}</button>
                </div>
                <label class="modal-field"><span>{{ studentMode ? t.coachCode : t.organizationCode }}</span><input v-model.trim="form.organization_code" required maxlength="20" autocomplete="off"></label>
                <label class="modal-field"><span>{{ t.email }}</span><input v-model.trim="form.email" type="email" required maxlength="255" autocomplete="email"></label>
                <template v-if="!form.existing_account">
                    <label class="modal-field"><span>{{ t.lastName }}</span><input v-model.trim="form.last_name" required maxlength="255" autocomplete="family-name"></label>
                    <label class="modal-field"><span>{{ t.firstName }}</span><input v-model.trim="form.first_name" required maxlength="255" autocomplete="given-name"></label>
                </template>
                <label class="modal-field"><span>{{ t.password }}</span>
                    <div class="registration-password"><input v-model="form.password" :type="reveal ? 'text' : 'password'" required :minlength="form.existing_account ? 1 : 8" maxlength="255" :autocomplete="form.existing_account ? 'current-password' : 'new-password'">
                        <button type="button" class="panel-icon-button" :title="reveal ? t.hidePassword : t.showPassword" @click="reveal = !reveal"><component :is="reveal ? EyeOff : Eye" :size="18" /></button>
                    </div>
                </label>
                <label v-if="!form.existing_account" class="modal-field"><span>{{ t.confirmPassword }}</span><input v-model="form.password_confirmation" :type="reveal ? 'text' : 'password'" required autocomplete="new-password"></label>
            </template>
            <template v-else>
                <p>{{ form.email }}</p>
                <label class="modal-field"><span>{{ t.emailVerificationCode }}</span><input v-model="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"></label>
            </template>
            <p v-if="error" role="alert" class="form-error">{{ error }}</p>
            <button class="create-button" :disabled="busy">{{ busy ? t.sending : verification ? t.confirm : t.continueRegistration }}</button>
            <button v-if="verification" type="button" class="soft-button" :disabled="busy" @click="verification = false; code = ''; error = ''">{{ t.changeRegistrationData }}</button>
        </form>
    </main>
</template>

<style scoped>
.trainer-registration { --panel-card-solid: #fff; --panel-text: #17191e; --panel-line: #d9dce2; --panel-red: #ad2b2d; --panel-muted: #656b76; --panel-soft: #f3f4f6; --input: #fff; --line: #d9dce2; --muted: #656b76; padding: 24px 20px; min-height: 100vh; box-sizing: border-box; background: var(--panel-card-solid); color: var(--panel-text); }
:global(html[data-theme="dark"]) .trainer-registration { --panel-card-solid: #15171c; --panel-text: #f5f5f6; --panel-line: #41454e; --panel-muted: #b9bdc6; --panel-soft: #24262c; --input: #24262c; --line: #41454e; --muted: #b9bdc6; }
.trainer-registration header { display: flex; align-items: center; justify-content: space-between; gap: 16px; max-width: 420px; margin: 0 auto 28px; }
.trainer-registration header img { width: 64px; height: 64px; object-fit: contain; }
.trainer-registration form, .trainer-registration > section { display: grid; gap: 16px; max-width: 420px; margin: 0 auto; }
.trainer-registration h1 { font-size: 22px; margin: 0; overflow-wrap: anywhere; }
.registration-mode { display: flex; width: 100%; height: 40px; border-radius: 8px; box-sizing: border-box; box-shadow: none; background: var(--panel-soft); }
.registration-mode button { flex: 1; white-space: normal; border-radius: 6px; font-size: 13px; font-weight: 600; }
.registration-mode button.active { background: var(--panel-red); }
.registration-password { display: flex; align-items: center; gap: 8px; }
.registration-password input { min-width: 0; flex: 1; }
.trainer-registration input { width: 100%; box-sizing: border-box; height: 40px; border-radius: 8px; font-size: 14px; }
.trainer-registration input:focus-visible { outline: 2px solid var(--panel-red); outline-offset: 2px; }
.trainer-registration .modal-field span { font-weight: 600; }
.trainer-registration .create-button { height: 40px; border-radius: 8px; }
</style>
