<script setup>
import { taskLabels } from '../../i18n/tasks';
import { ref, watch, onBeforeUnmount } from 'vue';
import { ArrowLeft, ArrowRight, RotateCw } from '@lucide/vue';
const props=defineProps({url:String,t:Object,locale:{type:String,default:'ru'},modelValue:Array});
const emit=defineEmits(['update:modelValue']);
const search=ref(''), rows=ref([]), page=ref(1), last=ref(1), loading=ref(false), error=ref('');
let controller, timer;
function toggle(id,checked) {emit('update:modelValue',checked?[...new Set([...props.modelValue,id])]:props.modelValue.filter(value=>value!==id));}
async function load(next=1) {
 controller?.abort();controller=new AbortController();const current=controller;
 loading.value=true;error.value='';
 try {
  const params=new URLSearchParams({search:search.value,page:next});
  const response=await fetch(props.url+'?'+params,{headers:{Accept:'application/json'},signal:current.signal});
  const data=await response.json();if(!response.ok)throw new Error(data.message||props.t.validationError);
  if(current!==controller)return;
  rows.value=data.data;page.value=data.meta.current_page;last.value=data.meta.last_page;
 }catch(e){if(e.name!=='AbortError'&&current===controller)error.value=e.message;}
 finally{if(current===controller)loading.value=false;}
}
watch(search,()=>{controller?.abort();clearTimeout(timer);timer=setTimeout(()=>load(),250);});
watch(()=>props.url,()=>load(),{immediate:true});
onBeforeUnmount(()=>{controller?.abort();clearTimeout(timer);});
</script>
<template>
<div class="option-picker">
<input v-model="search" class="modal-search-input" :placeholder="t.searchByName">
<div v-if="error" class="option-error" role="alert">{{ error }}<button type="button" class="panel-icon-button" :title="taskLabels[locale].refresh" @click="load(page)"><RotateCw :size="16"/></button></div>
<div class="modal-checkbox-list" :aria-busy="loading">
<label v-for="row in rows" :key="row.id" class="modal-check-row"><input type="checkbox" :checked="modelValue.includes(row.id)" @change="toggle(row.id,$event.target.checked)"><span>{{ row.name }}<small>{{ row.subtitle || '-' }}</small></span></label>
</div>
<nav class="option-pages"><button type="button" class="panel-icon-button" :disabled="loading||page<=1" :title="taskLabels[locale].previousPage" @click="load(page-1)"><ArrowLeft :size="16"/></button><span>{{ page }} / {{ last }}</span><button type="button" class="panel-icon-button" :disabled="loading||page>=last" :title="taskLabels[locale].nextPage" @click="load(page+1)"><ArrowRight :size="16"/></button></nav>
<div class="option-selection">{{ t.selected }}: {{ modelValue.length }}</div>
</div>
</template>
<style scoped>.option-picker{grid-column:1/-1;min-width:0;display:grid;gap:12px}.option-selection{font-size:12px;color:var(--panel-muted)}.option-pages{display:flex;gap:12px;align-items:center;justify-content:flex-end;margin:8px 0}.option-error{color:var(--danger,#b3262e)}</style>
