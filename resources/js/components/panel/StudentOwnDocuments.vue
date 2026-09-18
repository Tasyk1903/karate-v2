<script setup>
import { ref } from 'vue';
import { FileText, Check, AlertCircle, ExternalLink } from '@lucide/vue';
import DocumentLightbox from './DocumentLightbox.vue';
defineProps({ detail: Object, t: Object });
const preview = ref(null);
</script>
<template>
    <div class="own-documents">
        <div v-for="item in detail.document_status.items" :key="item.field" class="own-document">
            <FileText :size="20"/>
            <div><strong>{{ t[item.key] }}</strong><span :class="item.ok ? 'document-ok' : 'document-issue'"><Check v-if="item.ok" :size="14"/><AlertCircle v-else :size="14"/>{{ t[item.issue_key] || (item.ok ? t.documentStatusOk : t.notConfirmed) }}</span><small v-if="item.expires_at">{{ t.insuranceCloseDate }}: {{ item.expires_at }}</small></div>
            <button v-if="detail.documents.documents.find(doc => doc.key === item.key)?.file" class="panel-icon-button" type="button" :title="t.view" @click="preview = detail.documents.documents.find(doc => doc.key === item.key)"><ExternalLink :size="18"/></button>
        </div>
        <dl><template v-for="row in detail.documents.rows" :key="JSON.stringify(row)"><template v-for="(field, index) in row" :key="index"><div v-if="field?.label"><dt>{{ t[field.label] }}</dt><dd>{{ t[field.value] || field.value }}</dd></div></template></template></dl>
        <DocumentLightbox v-if="preview" :document="preview" :t="t" @close="preview = null"/>
    </div>
</template>
<style scoped>
.own-documents { width:100%; grid-column:1 / -1; }
.own-document { display:flex; align-items:center; gap:12px; padding:14px 0; border-bottom:1px solid var(--panel-line); }
.own-document > div { min-width:0; flex:1; display:grid; gap:5px; }
.own-document strong { font-size:14px; font-weight:600; }
.own-document span { display:flex; align-items:center; gap:6px; font-size:12px; overflow-wrap:anywhere; }
.own-document svg { flex-shrink:0; }
.document-ok { color:#15805a; } .document-issue { color:var(--panel-red); }
dl { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin:20px 0 0; }
dt { font-size:12px; color:var(--panel-muted); } dd { margin:4px 0 0; font-size:14px; overflow-wrap:anywhere; }
</style>
