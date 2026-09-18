<script setup>
defineProps({
    error: {
        type: String,
        default: '',
    },
    form: {
        type: Object,
        required: true,
    },
    isEditing: {
        type: Boolean,
        default: false,
    },
    t: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close', 'save']);
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <form class="template-modal exam-modal" @submit.prevent="emit('save')">
            <header>
                <h2>{{ isEditing ? t.editExam : t.createExam }}</h2>
                <button type="button" class="panel-icon-button" :title="t.cancel" @click="emit('close')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </header>

            <label class="modal-field full">
                <span>{{ t.examName }}</span>
                <input v-model="form.name" :placeholder="t.examNamePlaceholder" required>
            </label>

            <label class="modal-field">
                <span>{{ t.examDate }}</span>
                <input v-model="form.date" type="date" required>
            </label>

            <label class="modal-field">
                <span>{{ t.examCity }}</span>
                <input v-model="form.city" required>
            </label>

            <label class="modal-field full">
                <span>{{ t.examReceiving }}</span>
                <input v-model="form.receiving" required>
            </label>

            <p v-if="error" class="form-error full">{{ error }}</p>

            <footer>
                <button type="button" class="soft-button" @click="emit('close')">{{ t.cancel }}</button>
                <button type="submit" class="create-button">{{ isEditing ? t.save : t.createExam }}</button>
            </footer>
        </form>
    </div>
</template>
