<script setup>
defineProps({
    organizationCode: { type: String, default: '' },
    error: {
        type: String,
        default: '',
    },
    form: {
        type: Object,
        required: true,
    },
    isSubmitting: {
        type: Boolean,
        default: false,
    },
    t: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close', 'send']);
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <form class="template-modal trainer-invite-modal" @submit.prevent="emit('send')">
            <header>
                <h2>{{ t.addTrainer }}</h2>
                <button type="button" class="panel-icon-button" :title="t.cancel" @click="emit('close')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </header>

            <p class="full">{{ t.organizationCode }}: <strong>{{ organizationCode }}</strong></p>
            <label class="modal-field full">
                <span>{{ t.trainerEmails }}</span>
                <textarea
                    v-model="form.emails"
                    :placeholder="t.trainerEmailsPlaceholder"
                    required
                />
            </label>

            <p v-if="error" class="form-error full">{{ error }}</p>

            <footer>
                <button type="button" class="soft-button" @click="emit('close')">{{ t.cancel }}</button>
                <button type="submit" class="create-button" :disabled="isSubmitting">
                    {{ isSubmitting ? t.sending : t.sendInvitation }}
                </button>
            </footer>
        </form>
    </div>
</template>
