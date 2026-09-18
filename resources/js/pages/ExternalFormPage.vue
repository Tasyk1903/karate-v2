<script setup>
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    token: { type: String, required: true },
    t: { type: Object, required: true },
});

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const form = ref(null);
const categoryOptions = ref({});
const rows = ref([]);
const isLoading = ref(false);
const isSaving = ref(false);
const message = ref('');
const error = ref('');
const logoUrl = '/assets/auth/kr.jpg';

const isClosed = computed(() => form.value?.status === 'closed');
const categoryEntries = computed(() => Object.entries(categoryOptions.value));

function blankRow() {
    return {
        last_name: '',
        first_name: '',
        gender: '',
        birthday: '',
        rank: '',
        weight: '',
        age: '',
        category: [],
        kata_group: '',
        region: '',
        city: '',
        club: '',
        coach_last_name: '',
        coach_first_name: '',
        best_results: '',
        razriad: '',
    };
}

async function loadForm() {
    isLoading.value = true;
    error.value = '';

    try {
        const response = await fetch(`/api/external-form/${props.token}`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(props.t.failed);
        const payload = await response.json();
        form.value = payload.form;
        categoryOptions.value = payload.category_options ?? {};
        rows.value = (payload.form.participants ?? []).map((row) => ({
            ...blankRow(),
            ...row,
            category: Array.isArray(row.category) ? row.category : (row.category ? [row.category] : []),
        }));
        if (!rows.value.length && payload.form.status !== 'closed') rows.value.push(blankRow());
    } catch (loadError) {
        error.value = loadError?.message || props.t.failed;
    } finally {
        isLoading.value = false;
    }
}

function addRow() {
    rows.value.push(blankRow());
}

function removeRow(index) {
    rows.value.splice(index, 1);
}

function toggleCategory(row, code) {
    const current = new Set(row.category ?? []);
    current.has(code) ? current.delete(code) : current.add(code);
    row.category = Array.from(current);
    if (!current.has('kata_group')) row.kata_group = '';
}

async function saveRows() {
    isSaving.value = true;
    message.value = '';
    error.value = '';

    try {
        const response = await fetch(`/api/external-form/${props.token}`, {
            method: 'PUT',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ participants: rows.value, revision: form.value.revision }),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload?.message || props.t.validationError);
        rows.value = payload.participants ?? rows.value;
        form.value.revision = payload.revision;
        message.value = props.t.saved;
    } catch (saveError) {
        error.value = saveError?.message || props.t.validationError;
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadForm);
</script>

<template>
    <main class="external-form-page">
        <section class="external-form-card">
            <header class="external-form-header">
                <img :src="logoUrl" alt="KR">
                <div>
                    <span>{{ t.teamForm }}</span>
                    <h1>{{ form?.organization_name || t.loading }}</h1>
                </div>
                <strong :data-status="isClosed ? 'closed' : 'open'">{{ isClosed ? t.closed : t.open }}</strong>
            </header>

            <p v-if="error" class="form-error">{{ error }}</p>
            <p v-if="message" class="form-success">{{ message }}</p>
            <div v-if="isLoading" class="empty-cell">{{ t.loading }}</div>

            <div v-else class="external-form-table-wrap">
                <table class="external-form-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>{{ t.lastName }}</th>
                            <th>{{ t.firstName }}</th>
                            <th>{{ t.gender }}</th>
                            <th>{{ t.birthDate }}</th>
                            <th>{{ t.kyuDan }}</th>
                            <th>{{ t.weight }}</th>
                            <th>{{ t.age }}</th>
                            <th>{{ t.category }}</th>
                            <th>{{ t.kataGroup }}</th>
                            <th>{{ t.region }}</th>
                            <th>{{ t.city }}</th>
                            <th>{{ t.club }}</th>
                            <th>{{ t.coachLastName }}</th>
                            <th>{{ t.coachFirstName }}</th>
                            <th>{{ t.rankSport }}</th>
                            <th>{{ t.bestResults }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in rows" :key="index">
                            <td><button v-if="!isClosed" type="button" class="row-delete-button" @click="removeRow(index)">×</button></td>
                            <td><input v-model="row.last_name" :disabled="isClosed"></td>
                            <td><input v-model="row.first_name" :disabled="isClosed"></td>
                            <td>
                                <select v-model="row.gender" :disabled="isClosed">
                                    <option value=""></option>
                                    <option value="m">{{ t.male }}</option>
                                    <option value="f">{{ t.female }}</option>
                                </select>
                            </td>
                            <td><input v-model="row.birthday" :placeholder="t.formDatePlaceholder" :disabled="isClosed"></td>
                            <td>
                                <select v-model="row.rank" :disabled="isClosed">
                                    <option value=""></option>
                                    <option v-for="rank in ['0 кю','10 кю','9 кю','8 кю','7 кю','6 кю','5 кю','4 кю','3 кю','2 кю','1 кю']" :key="rank" :value="rank">{{ rank }}</option>
                                </select>
                            </td>
                            <td><input v-model="row.weight" type="number" step="0.1" :disabled="isClosed"></td>
                            <td><input v-model="row.age" type="number" :disabled="isClosed"></td>
                            <td>
                                <div class="category-checks">
                                    <label v-for="[code, label] in categoryEntries" :key="code">
                                        <input type="checkbox" :checked="row.category?.includes(code)" :disabled="isClosed" @change="toggleCategory(row, code)">
                                        <span>{{ code === 'kata_point' ? t.formBothKata : t['teamFormCategory_' + code] }}</span>
                                    </label>
                                </div>
                            </td>
                            <td>
                                <select v-model="row.kata_group" :disabled="isClosed || !row.category?.includes('kata_group')">
                                    <option value=""></option>
                                    <option v-for="number in 15" :key="number" :value="number">{{ number }}</option>
                                </select>
                            </td>
                            <td><input v-model="row.region" :disabled="isClosed"></td>
                            <td><input v-model="row.city" :disabled="isClosed"></td>
                            <td><input v-model="row.club" :disabled="isClosed"></td>
                            <td><input v-model="row.coach_last_name" :disabled="isClosed"></td>
                            <td><input v-model="row.coach_first_name" :disabled="isClosed"></td>
                            <td><input v-model="row.razriad" :disabled="isClosed"></td>
                            <td><textarea v-model="row.best_results" :disabled="isClosed"></textarea></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer v-if="!isClosed" class="external-form-actions">
                <button type="button" class="soft-button" @click="addRow">{{ t.addRow }}</button>
                <button type="button" class="save-button" :disabled="isSaving" @click="saveRows">{{ isSaving ? t.saving : t.save }}</button>
            </footer>
        </section>
    </main>
</template>
