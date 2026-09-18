<script setup>
import ListRankSelect from './ListRankSelect.vue';
defineProps({
    editingTemplate: {
        type: Object,
        default: null,
    },
    templateError: {
        type: String,
        default: '',
    },
    templateForm: {
        type: Object,
        required: true,
    },
    t: {
        type: Object,
        required: true,
    },
    submitLabel: {
        type: String,
        default: '',
    },
    isSaving: {
        type: Boolean,
        default: false,
    },
    visibleFormFields: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close', 'save']);
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <form class="template-modal" @submit.prevent="emit('save')">
            <header>
                <h2>{{ editingTemplate?.id ? t.editing : t.creating }}</h2>
                <button type="button" class="panel-icon-button" @click="emit('close')">×</button>
            </header>
            <label class="modal-field full">
                <span>{{ t.listType }}</span>
                <select v-model="templateForm.list_type">
                    <option value="kumite">{{ t.kumite }}</option>
                    <option value="kata">{{ t.kata }}</option>
                </select>
            </label>
            <label v-if="visibleFormFields.kataType" class="modal-field full">
                <span>{{ t.kataType }}</span>
                <div class="segmented">
                    <button type="button" :class="{ active: templateForm.kata_type === 'personal' }" @click="templateForm.kata_type = 'personal'">{{ t.personal }}</button>
                    <button type="button" :class="{ active: templateForm.kata_type === 'group' }" @click="templateForm.kata_type = 'group'">{{ t.group }}</button>
                    <button type="button" :class="{ active: templateForm.kata_type === 'flag' }" @click="templateForm.kata_type = 'flag'">{{ t.flag }}</button>
                </div>
            </label>
            <label class="modal-field full"><span>{{ t.name }}</span><input v-model="templateForm.name" :placeholder="t.listNamePlaceholder"></label>
            <label class="modal-field"><span>{{ t.ageFrom }}</span><input v-model="templateForm.age_from" type="number"></label>
            <label class="modal-field"><span>{{ t.ageTo }}</span><input v-model="templateForm.age_to" type="number"></label>
            <label v-if="visibleFormFields.weight" class="modal-field"><span>{{ t.weightFrom }}</span><input v-model="templateForm.weight_from" type="number"></label>
            <label v-if="visibleFormFields.weight" class="modal-field"><span>{{ t.weightTo }}</span><input v-model="templateForm.weight_to" type="number"></label>
            <label v-if="visibleFormFields.rank" class="modal-field"><span>{{ t.listRankFrom }}</span><ListRankSelect v-model="templateForm.rang_from" :t="t" /></label>
            <label v-if="visibleFormFields.rank" class="modal-field"><span>{{ t.listRankTo }}</span><ListRankSelect v-model="templateForm.rang_to" :t="t" /></label>
            <label v-if="visibleFormFields.gender" class="modal-field full">
                <span>{{ t.gender }}</span>
                <select v-model="templateForm.gender">
                    <option value="m">{{ t.male }}</option>
                    <option value="f">{{ t.female }}</option>
                    <option value="all">{{ t.bothGenders }}</option>
                </select>
            </label>
            <p v-if="templateError" class="form-error">{{ templateError }}</p>
            <footer>
                <button type="button" class="soft-button" :disabled="isSaving" @click="emit('close')">{{ t.cancel }}</button>
                <button type="submit" class="apply-button" :disabled="isSaving">{{ submitLabel || t.save }}</button>
            </footer>
        </form>
    </div>
</template>
