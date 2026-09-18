<script setup>
defineProps({ row: Object, options: Object, t: Object });
const fields = [ ['last_name', 'lastName'], ['first_name', 'firstName'], ['birthday', 'birthDate'], ['rank', 'kyuDan'],
    ['age', 'age', 'number'], ['weight', 'weight', 'number'], ['region', 'region'], ['city', 'city'], ['club', 'club'],
    ['coach_last_name', 'coachLastName'], ['coach_first_name', 'coachFirstName'], ['razriad', 'rankSport'] ];
</script>
<template>
    <div class="form-participant-fields">
        <label v-for="[field, label, type] in fields" :key="field" class="modal-field"><span>{{ t[label] }}</span><input v-model="row[field]" :type="type || 'text'" :step="field === 'weight' ? '0.1' : '1'" :min="type === 'number' ? 0 : undefined" :max="field === 'age' ? 120 : field === 'weight' ? 400 : undefined" :maxlength="255" :required="['first_name', 'last_name'].includes(field)" :placeholder="field === 'birthday' ? t.formDatePlaceholder : undefined"></label>
        <label class="modal-field"><span>{{ t.gender }}</span><select v-model="row.gender"><option value=""></option><option value="m">{{ t.male }}</option><option value="f">{{ t.female }}</option></select></label>
        <label class="modal-field"><span>{{ t.kataGroup }}</span><select v-model="row.kata_group" :disabled="!row.category.includes('kata_group')"><option value=""></option><option v-for="number in 15" :key="number" :value="number">{{ number }}</option></select></label>
        <fieldset class="form-category-options"><legend>{{ t.category }}</legend><label v-for="(_, code) in options" :key="code"><input v-model="row.category" type="checkbox" :value="code" @change="!row.category.includes('kata_group') && (row.kata_group = '')">{{ code === 'kata_point' ? t.formBothKata : (t['teamFormCategory_' + code] || code) }}</label></fieldset>
        <label class="modal-field form-field-wide"><span>{{ t.bestResults }}</span><textarea v-model="row.best_results" maxlength="4000" rows="3"></textarea></label>
    </div>
</template>
