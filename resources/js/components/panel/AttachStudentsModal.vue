<script setup>
defineProps({
    error: {
        type: String,
        default: '',
    },
    selectedIds: {
        type: Array,
        required: true,
    },
    students: {
        type: Array,
        required: true,
    },
    t: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close', 'save', 'toggle-student']);
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <form class="template-modal attach-students-modal" @submit.prevent="emit('save')">
            <header>
                <h2>{{ t.attachStudents }}</h2>
                <button type="button" class="panel-icon-button" :title="t.cancel" @click="emit('close')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </header>

            <div class="attach-list full">
                <label v-for="student in students" :key="student.id" class="attach-row">
                    <input
                        type="checkbox"
                        :checked="selectedIds.includes(student.id)"
                        @change="emit('toggle-student', student.id)"
                    >
                    <span>
                        <strong>{{ student.full_name }}</strong>
                        <small>{{ student.age ?? '-' }} {{ t.yearsShort }} · {{ student.rang ?? '-' }}</small>
                    </span>
                </label>
                <div v-if="students.length === 0" class="attach-empty">{{ t.emptyTeam }}</div>
            </div>

            <p v-if="error" class="form-error full">{{ error }}</p>

            <footer>
                <button type="button" class="soft-button" @click="emit('close')">{{ t.cancel }}</button>
                <button type="submit" class="create-button">{{ t.attachStudents }}</button>
            </footer>
        </form>
    </div>
</template>
