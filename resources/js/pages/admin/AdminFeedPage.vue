<script setup>
import AdminSearch from '../../components/admin/AdminSearch.vue';
import { computed, ref } from 'vue';
import { Pencil, Trash2, MessageCircle } from '@lucide/vue';
import AdminComments from '../../components/admin/AdminComments.vue';
import { adminLabels } from '../../i18n/admin';
import { useAdminList } from '../../composables/useAdminList';
import AdminPager from '../../components/admin/AdminPager.vue';
import AdminDialog from '../../components/admin/AdminDialog.vue';
const props = defineProps({ locale: String });
const t = computed(() => adminLabels[props.locale]);
const { rows, page, last, total, range, loading, search, message, error, busy, request, load, mutate } = useAdminList(() => '/api/admin/feed', () => t.value);
const editing = ref(null), deleting = ref(null);
const discussion = ref(null);
async function save() { await mutate(async () => { await request('/api/admin/feed/' + editing.value.id, { text: editing.value.text, remove_media: Boolean(editing.value.remove_media) }, 'PUT'); editing.value = null; }); }
async function remove() { await mutate(async () => { await request('/api/admin/feed/' + deleting.value.id, { confirmed: true }, 'DELETE'); deleting.value = null; }); }
const date = value => new Date(value).toLocaleString(props.locale);
</script>
<template><div class="admin-feed"><div class="admin-toolbar"><AdminSearch v-model="search" :t="t"/></div><p v-if="message" class="admin-notice" role="status">{{ message }}</p><p v-if="error && !editing && !deleting" role="alert" class="admin-error">{{ error }} <button @click="load(page)">{{ t.retry }}</button></p><p v-if="loading" class="admin-empty">{{ t.loading }}</p><template v-else><article v-for="post in rows" :key="post.id" class="admin-post"><header><div><strong>{{ post.author || ('#' + post.user_id) }}</strong><br><small>{{ date(post.created_at) }}</small></div><div class="admin-actions"><button :title="t.edit" @click="editing = { ...post }; error = ''"><Pencil :size="18"/></button><button :title="t.remove" @click="deleting = post; error = ''"><Trash2 :size="18"/></button></div></header><p>{{ post.text }}</p><video v-if="post.image && /\.(mp4|mov|webm|mpeg|avi)(\?|$)/i.test(post.image)" :src="post.image" controls playsinline preload="metadata"/><img v-else-if="post.image" :src="post.image" alt="" loading="lazy"><button style="margin-top:12px" :title="t.comments" @click="discussion = discussion === post.id ? null : post.id"><MessageCircle :size="17"/>{{ post.comments_count }}</button><AdminComments v-if="discussion === post.id" :post="post.id" :t="t" @changed="post.comments_count = $event"/></article><p v-if="!rows.length" class="admin-empty">{{ t.empty }}</p></template><AdminPager :page="page" :last="last" :total="total" :range="range" :busy="loading" :t="t" @page="load"/></div>
<AdminDialog v-if="editing" :title="t.edit" :t="t" :busy="busy" @close="editing = null"><form @submit.prevent="save"><label>{{ t.text }}<textarea v-model="editing.text" maxlength="10000"/></label><label v-if="editing.image"><input v-model="editing.remove_media" type="checkbox">{{ t.removeMedia }}</label><p v-if="error" class="admin-error" role="alert">{{ error }}</p><footer><button type="button" :disabled="busy" @click="editing = null">{{ t.cancel }}</button><button class="admin-primary" :disabled="busy">{{ t.save }}</button></footer></form></AdminDialog>
<AdminDialog v-if="deleting" :title="t.deleteTitle" :t="t" :busy="busy" @close="deleting = null"><p>{{ t.deleteMessage }}</p><p v-if="error" class="admin-error" role="alert">{{ error }}</p><footer><button :disabled="busy" @click="deleting = null">{{ t.cancel }}</button><button class="admin-primary" :disabled="busy" @click="remove">{{ t.remove }}</button></footer></AdminDialog></template>
