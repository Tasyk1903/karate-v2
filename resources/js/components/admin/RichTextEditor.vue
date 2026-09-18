<script setup>
import { watch, onBeforeUnmount, ref } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { Bold, Italic, Underline, Heading2, List, ListOrdered, Undo2, Redo2, Link, Unlink } from '@lucide/vue';
const props = defineProps({ modelValue: String, t: Object });
const emit = defineEmits(['update:modelValue']);
const linkOpen = ref(false), href = ref('');
const editor = useEditor({ content: props.modelValue, extensions: [StarterKit.configure({ heading: { levels: [2, 3, 4] }, link: { openOnClick: false, protocols: ['https'] }, codeBlock: false, code: false, horizontalRule: false, strike: false })], onUpdate: ({ editor }) => emit('update:modelValue', editor.getHTML()) });
watch(() => props.modelValue, value => { if (editor.value && editor.value.getHTML() !== value) editor.value.commands.setContent(value ?? '', { emitUpdate: false }); });
onBeforeUnmount(() => editor.value?.destroy());
const commands = [
    ['bold', Bold, e => e.toggleBold()], ['italic', Italic, e => e.toggleItalic()], ['underline', Underline, e => e.toggleUnderline()],
    ['heading', Heading2, e => e.toggleHeading({ level: 2 })], ['list', List, e => e.toggleBulletList()], ['numbered', ListOrdered, e => e.toggleOrderedList()],
    ['undo', Undo2, e => e.undo()], ['redo', Redo2, e => e.redo()],
];
function saveLink() { if (/^https:\/\//i.test(href.value)) editor.value.chain().focus().setLink({ href: href.value }).run(); linkOpen.value = false; }
</script>
<template><div class="admin-editor"><div class="admin-editor-tools"><button v-for="[key, icon, command] in commands" :key="key" type="button" :title="t[key]" :aria-label="t[key]" @click="command(editor.chain().focus()).run()"><component :is="icon" :size="17"/></button><button type="button" :title="t.link" @click="linkOpen = !linkOpen"><Link :size="17"/></button><button type="button" :title="t.unlink" @click="editor.chain().focus().unsetLink().run()"><Unlink :size="17"/></button></div><div v-if="linkOpen" class="admin-toolbar"><input v-model="href" type="url" placeholder="https://" :aria-label="t.link"><button type="button" @click="saveLink">{{ t.save }}</button></div><EditorContent :editor="editor" /></div></template>
