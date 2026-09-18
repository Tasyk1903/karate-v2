<script setup>
import { onMounted, onBeforeUnmount, reactive, ref } from 'vue';
import { Camera, UserRound, Trash2, Save } from '@lucide/vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ t: Object, locale: String });
const emit = defineEmits(['updated']);
const { busy, error, request, run } = useAccountRequest(() => props.t);
const profile = ref(null), file = ref(null), preview = ref(''), remove = ref(false), saved = ref(false);
const form = reactive({ name: '', first_name: '', last_name: '' });
function clearPreview() { if (preview.value) URL.revokeObjectURL(preview.value); preview.value = ''; }
function select(event) { clearPreview(); file.value = event.target.files[0] ?? null; if (file.value) preview.value = URL.createObjectURL(file.value); remove.value = false; saved.value = false; }
function removeAvatar() { clearPreview(); file.value = null; remove.value = true; saved.value = false; }
async function load() { await run(async () => { profile.value = await request('/api/panel/account/profile'); Object.assign(form, profile.value); }); }
async function save() {
    await run(async () => {
        const data = new FormData();
        for (const key of profile.value.is_organization ? ['name'] : ['first_name', 'last_name']) data.append(key, form[key]);
        data.append('locale', props.locale); data.append('remove_avatar', remove.value ? '1' : '0');
        if (file.value) data.append('avatar', file.value);
        profile.value = await request('/api/panel/account/profile', data);
        clearPreview(); file.value = null; remove.value = false; saved.value = true;
        emit('updated', profile.value);
    });
}
onMounted(load); onBeforeUnmount(clearPreview);
</script>
<template>
    <section class="account-page">
        <h1>{{ t.accountProfile }}</h1>
        <p v-if="error" class="account-error" role="alert">{{ error }} <button v-if="!profile" @click="load">{{ t.accountRetry }}</button></p>
        <p v-if="busy && !profile">{{ t.loading }}</p>
        <form v-if="profile" class="account-profile" @submit.prevent="save">
            <fieldset :disabled="busy">
                <div class="account-photo-row">
                    <div class="account-photo"><img v-if="preview || (!remove && profile.avatar)" :src="preview || profile.avatar" :alt="t.accountAvatar" @error="$event.target.style.display = 'none'"><UserRound v-else :size="34"/></div>
                    <label class="account-upload"><Camera :size="16"/>{{ t.accountUpload }}<input type="file" accept="image/jpeg,image/png,image/webp" :aria-label="t.accountUpload" @change="select"></label>
                    <button v-if="preview || (!remove && profile.avatar)" type="button" :title="t.accountRemoveAvatar" :aria-label="t.accountRemoveAvatar" @click="removeAvatar"><Trash2 :size="16"/></button>
                </div>
                <label v-if="profile.is_organization">{{ t.organizationName }}<input v-model="form.name" required maxlength="255" autocomplete="organization" @input="saved = false"></label>
                <template v-else><label>{{ t.firstName }}<input v-model="form.first_name" required maxlength="100" autocomplete="given-name" @input="saved = false"></label><label>{{ t.lastName }}<input v-model="form.last_name" required maxlength="100" autocomplete="family-name" @input="saved = false"></label></template>
                <button class="account-primary" type="submit"><Save :size="16"/>{{ busy ? t.loading : t.accountSave }}</button>
                <p v-if="saved" class="account-success" role="status">{{ t.accountSaved }}</p>
            </fieldset>
        </form>
    </section>
</template>
