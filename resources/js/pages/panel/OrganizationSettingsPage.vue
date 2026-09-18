<script setup>
defineProps({
    error: {
        type: String,
        default: '',
    },
    isLoading: {
        type: Boolean,
        required: true,
    },
    isSaving: {
        type: Boolean,
        required: true,
    },
    settings: {
        type: Object,
        default: null,
    },
    settingsForm: {
        type: Object,
        required: true,
    },
    t: {
        type: Object,
        required: true,
    },
});

</script>

<template>
    <section class="organization-settings-page">
        <article class="settings-card">
            <header class="settings-card-header">
                <div>
                    <h1>{{ t.organizationSettings }}</h1>
                    <span>{{ settings?.organization?.name ?? t.organization }}</span>
                </div>
                <small v-if="isSaving" class="settings-save-status">{{ t.saving }}</small>
            </header>

            <p v-if="error" class="form-error">{{ error }}</p>

            <div v-if="isLoading" class="loading-state">{{ t.loading }}</div>

            <div v-else class="organization-settings-grid">
                <label class="settings-toggle-card">
                    <input v-model="settingsForm.can_edit_students" type="checkbox">
                    <span class="settings-toggle-visual" aria-hidden="true"></span>
                    <span>
                        <strong>{{ t.canEditStudents }}</strong>
                        <small>{{ t.canEditStudentsHint }}</small>
                    </span>
                </label>

                <label class="settings-toggle-card">
                    <input v-model="settingsForm.can_edit_coaches" type="checkbox">
                    <span class="settings-toggle-visual" aria-hidden="true"></span>
                    <span>
                        <strong>{{ t.canEditCoaches }}</strong>
                        <small>{{ t.canEditCoachesHint }}</small>
                    </span>
                </label>
            </div>
        </article>
    </section>
</template>
