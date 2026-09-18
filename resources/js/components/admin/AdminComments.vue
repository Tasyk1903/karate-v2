<script setup>
import { ref } from 'vue';
import { Pencil, Trash2 } from '@lucide/vue';
import { useAdminList } from '../../composables/useAdminList';
import AdminPager from './AdminPager.vue';
import AdminDialog from './AdminDialog.vue';
const props = defineProps({ post: Number, t: Object });
const emit = defineEmits(['changed']);
const { rows, page, last, total, loading, error, busy, request, mutate, load } = useAdminList(() => `/api/admin/feed/${props.post}/comments`, () => props.t);
const editing = ref(null), deleting = ref(null);
async function save() { await mutate(async () => { await request(`/api/admin/feed/${props.post}/comments/${editing.value.id}`, { text: editing.value.text }, 'PUT'); editing.value = null; }); }
async function remove() { await mutate(async () => { const response = await request(`/api/admin/feed/${props.post}/comments/${deleting.value}`, { confirmed: true }, 'DELETE'); deleting.value = null; emit('changed', response.comments_count); }); }
</script>
<template><section class="admin-comments"><p v-if="error && !editing && !deleting" class="admin-error" role="alert">{{ error }}</p><p v-if="loading">{{ t.loading }}</p><article v-for="row in rows" :key="row.id" :class="{ reply: row.parent_id }"><header><strong>{{ row.author }} <small v-if="row.parent_id">↳ #{{ row.parent_id }}</small></strong><div class="admin-actions"><button :title="t.edit" @click="editing = { ...row }"><Pencil :size="16"/></button><button :title="t.remove" @click="deleting = row.id"><Trash2 :size="16"/></button></div></header><p>{{ row.text }}</p></article><p v-if="!loading && !rows.length">{{ t.empty }}</p><AdminPager :page="page" :last="last" :total="total" :busy="loading" :t="t" @page="load"/></section>
<AdminDialog v-if="editing" :title="t.edit" :t="t" :busy="busy" @close="editing = null"><form @submit.prevent="save"><label>{{ t.text }}<textarea v-model="editing.text" required maxlength="5000"/></label><p v-if="error" class="admin-error" role="alert">{{ error }}</p><footer><button type="button" :disabled="busy" @click="editing = null">{{ t.cancel }}</button><button class="admin-primary" :disabled="busy">{{ t.save }}</button></footer></form></AdminDialog>
<AdminDialog v-if="deleting" :title="t.deleteTitle" :t="t" :busy="busy" @close="deleting = null"><p>{{ t.deleteMessage }}</p><p v-if="error" class="admin-error">{{ error }}</p><footer><button :disabled="busy" @click="deleting = null">{{ t.cancel }}</button><button class="admin-primary" :disabled="busy" @click="remove">{{ t.remove }}</button></footer></AdminDialog></template>
<style scoped>.admin-comments{border-top:1px solid var(--admin-border);margin-top:16px;padding-top:8px;font-size:13px}.admin-comments article{padding:12px 0;border-bottom:1px solid var(--admin-border)}.admin-comments .reply{padding-left:20px}.admin-comments p{white-space:pre-wrap}.admin-comments header{display:flex;align-items:center;justify-content:space-between}</style>
