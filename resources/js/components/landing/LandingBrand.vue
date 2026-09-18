<script setup>
defineProps({ dark: Boolean });
</script>
<template><a class="landing-brand" :class="{ 'landing-brand-dark': dark }" href="/" aria-label="Kumite Rating"><img :src="'/assets/auth/kr.jpg'" alt="Karate Rating" width="38" height="38"><span>Kumite<span class="landing-brand-red"> Rating</span></span></a></template>
<style scoped>
.landing-brand{display:inline-flex;align-items:center;gap:8px;color:#fff;text-decoration:none;font:700 22px/1 Arial,sans-serif;white-space:nowrap;letter-spacing:0}.landing-brand-dark{color:#101214}.landing-brand-red{color:#ec2028}.landing-brand img{width:38px;height:38px;object-fit:contain;border-radius:50%;flex:none}
@media(max-width:1100px){.landing-brand{font-size:17px;gap:5px}.landing-brand img{width:29px;height:29px}}
@media(min-width:601px) and (max-width:800px){.landing-brand{font-size:13px;gap:4px}.landing-brand img{width:24px;height:24px}}
</style>
