<script setup>
import { Trash2 } from '@lucide/vue';
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
    section: {
        type: String,
        required: true,
    },
    t: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close', 'save', 'delete']);

const judgePositions = [
    { value: 'referee_score', label: 'referee_score' },
    { value: 'judge1_score', label: 'judge1_score' },
    { value: 'judge2_score', label: 'judge2_score' },
    { value: 'judge3_score', label: 'judge3_score' },
    { value: 'judge4_score', label: 'judge4_score' },
];
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <form class="template-modal team-member-modal" @submit.prevent="emit('save')">
            <header>
                <h2>
                    {{ isEditing
                        ? (section === 'judges' ? t.editJudge : t.editSecretary)
                        : (section === 'judges' ? t.createJudge : t.createSecretary) }}
                </h2>
                <button type="button" class="panel-icon-button" :title="t.cancel" @click="emit('close')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </header>

            <label class="modal-field">
                <span>{{ t.lastName }}</span>
                <input v-model="form.last_name" autocomplete="family-name" required>
            </label>

            <label class="modal-field">
                <span>{{ t.firstName }}</span>
                <input v-model="form.first_name" autocomplete="given-name" required>
            </label>

            <label class="modal-field full">
                <span>{{ t.email }}</span>
                <input v-model="form.email" type="email" autocomplete="email" required>
            </label>

            <label class="modal-field full">
                <span>{{ isEditing ? t.passwordOptional : t.password }}</span>
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    :required="!isEditing"
                >
            </label>

            <label v-if="section === 'judges'" class="modal-field full">
                <span>{{ t.judgePosition }}</span>
                <select v-model="form.judge_position" required>
                    <option v-for="option in judgePositions" :key="option.value" :value="option.value">
                        {{ t[option.label] }}
                    </option>
                </select>
            </label>

            <p v-if="error" class="form-error full">{{ error }}</p>

            <footer>
                <button v-if="isEditing" type="button" class="panel-icon-button danger" :title="t.delete" @click="emit('delete')"><Trash2 :size="18" /></button>
                <button type="button" class="soft-button" @click="emit('close')">{{ t.cancel }}</button>
                <button type="submit" class="create-button">{{ t.save }}</button>
            </footer>
        </form>
    </div>
</template>
