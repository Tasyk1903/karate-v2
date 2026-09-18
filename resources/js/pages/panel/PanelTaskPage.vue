<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
import { Download, ArrowLeft, LoaderCircle, CircleAlert, Check } from '@lucide/vue';
const props = defineProps({ id: String, locale: String });
const task = ref(null), error = ref(false);
let timer, disposed = false;
import { taskLabels as labels } from '../../i18n/tasks';

async function load() {
 clearTimeout(timer);
 try {
  const response = await fetch('/api/panel/tasks/' + props.id, { headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error();
  const data = await response.json();
  if (disposed) return;
  task.value=data.task; error.value=false;
  if (['queued','processing'].includes(data.task.status)) timer=setTimeout(load,2000);
 } catch { if (!disposed) error.value=true; }
}
onMounted(load); onBeforeUnmount(()=>{ disposed=true; clearTimeout(timer); });
</script>
<template>
 <section class="panel-task">
  <h1>{{ labels[locale][task?.kind === 'generate' ? 'generate' : 'title'] }}</h1>
  <template v-if="error"><p><CircleAlert :size="20"/> {{ labels[locale].error }}</p><button class="soft-button" @click="load">{{ labels[locale].retry }}</button></template>
  <template v-else-if="task">
   <p role="status" aria-live="polite"><LoaderCircle v-if="['queued','processing'].includes(task.status)" :size="20"/><Check v-else-if="task.status==='ready'" :size="20"/>{{ labels[locale][task.status] }}</p>
   <a v-if="task.download_url" class="save-button" :href="task.download_url"><Download :size="18"/>{{ labels[locale].download }}</a>
   <a class="soft-button" :href="task.return_url"><ArrowLeft :size="18"/>{{ labels[locale].back }}</a>
  </template>
  <p v-else role="status"><LoaderCircle :size="20"/> {{ labels[locale].processing }}</p>
 </section>
</template>
<style scoped>
.panel-task { width:100%; min-height:230px; padding:24px; background:var(--panel-card-solid); border-block:1px solid var(--panel-line); color:var(--panel-text); } h1 { font-size:22px; font-weight:600; } p { display:flex; gap:8px; align-items:center; margin:20px 0; }
a,button { display:inline-flex; gap:8px; align-items:center; margin-right:12px; margin-bottom:8px; }
</style>
