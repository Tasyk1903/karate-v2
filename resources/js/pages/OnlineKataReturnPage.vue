<script setup>
defineProps({ t: Object, logoUrl: String });
const id = new URLSearchParams(window.location.search).get('application') ?? '';
const work = new URLSearchParams(window.location.search).get('work') ?? '';
const link = /^[1-9]\d{0,12}$/.test(work) ? 'karaterating://education/' + work : /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(id) ? 'karaterating://payment/' + id : 'karaterating://payment';
</script>

<template>
    <main class="kata-return">
        <img :src="logoUrl" alt="Karate Rating" width="72" height="72">
        <h1>{{ t.kataPaymentReturn }}</h1>
        <a :href="link">{{ t.kataOpenApplication }}</a>
    </main>
</template>

<style scoped>
.kata-return { min-height: 100dvh; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 20px; padding: 24px; background: #fff; color: #191919; }
h1 { font-size: 22px; font-weight: 600; text-align: center; }
a { padding: 12px 20px; border-radius: 8px; background: #ae262b; color: #fff; text-decoration: none; font-size: 15px; }
</style>
