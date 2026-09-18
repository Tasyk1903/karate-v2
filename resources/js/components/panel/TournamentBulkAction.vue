<script setup>
import { computed, ref, watch } from 'vue';
import { ListChecks, X } from '@lucide/vue';
import AccountPager from './AccountPager.vue';
import { useAccountRequest } from '../../composables/useAccountRequest';
const props = defineProps({ items: Array, endpoint: String, label: String, confirm: String, t: Object, locale: String });
const emit = defineEmits(['done']);
const open = ref(false), selected = ref([]), search = ref(''), page = ref(1), confirming = ref(false);
const { request, run, busy, error } = useAccountRequest(() => props.t);
const matches = computed(() => (props.items || []).filter(item => item.name.toLowerCase().includes(search.value.toLowerCase())));
const pages = computed(() => Math.max(1, Math.ceil(matches.value.length / 20)));
const visible = computed(() => matches.value.slice((page.value - 1) * 20, page.value * 20));
const chosen = computed(() => (props.items || []).filter(item => selected.value.includes(item.id)));
watch(search, () => page.value = 1);
function start() { selected.value = []; search.value = ''; page.value = 1; confirming.value = false; error.value = ''; open.value = true; }
function submit() {
    if (!confirming.value) { confirming.value = true; return; }
    run(async () => { await request(props.endpoint, { ids: selected.value, locale: props.locale }); open.value = false; emit('done'); });
}
</script>
<template>
    <button type="button" class="soft-button tour-bulk-button" :disabled="!items?.length" @click="start"><ListChecks :size="16" />{{ label }}</button>
        <div v-if="open" class="modal-backdrop application-modal-backdrop" @click.self="!busy && (open = false)">
            <form class="template-modal application-modal" role="dialog" aria-modal="true" :aria-label="label" @submit.prevent="submit">
                <header><h2>{{ label }}</h2><button type="button" class="panel-icon-button" :disabled="busy" :aria-label="t.cancel" @click="open = false"><X :size="18" /></button></header>
                <p v-if="error" role="alert" class="form-error">{{ error }}</p>
                <template v-if="!confirming">
                    <input v-model="search" :aria-label="t.search" :placeholder="t.search" class="modal-search-input" />
                    <div class="modal-options-list"><label v-for="item in visible" :key="item.id" class="modal-check-row"><input v-model="selected" type="checkbox" :value="item.id" :disabled="selected.length >= 200 && !selected.includes(item.id)" /><span>{{ item.name }}</span></label></div>
                    <AccountPager :page="page" :last="pages" :busy="busy" :t="t" @change="page = $event" />
                </template>
                <template v-else><p>{{ confirm }}</p><ul class="tour-confirm-names"><li v-for="item in chosen" :key="item.id">{{ item.name }}</li></ul></template>
                <p>{{ t.selected }}: {{ selected.length }}</p>
                <footer><button type="button" class="soft-button" :disabled="busy" @click="confirming ? confirming = false : open = false">{{ confirming ? t.tourBack : t.cancel }}</button><button type="submit" class="save-button" :disabled="busy || !selected.length">{{ confirming ? t.tourConfirm : t.tourContinue }}</button></footer>
            </form>
        </div>
</template>
<style>
.tour-bulk-button { display:inline-flex; align-items:center; gap:6px; }
.tour-bulk-button svg { flex:0 0 16px; }
.tour-confirm-names { max-height:260px; overflow:auto; font-size:13px; }
</style>
