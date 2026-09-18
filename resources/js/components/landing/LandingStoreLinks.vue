<script setup>
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faApple, faGooglePlay } from '@fortawesome/free-brands-svg-icons';
import { storeLink } from './storeLinks';
defineProps({ t: Object, links: Object, statusOnly: Boolean });
defineEmits(['unavailable']);
</script>
<template><div class="landing-stores"><component v-for="store in ['ios','android']" :key="store" :is="storeLink(links[store]) ? 'a' : 'button'" :href="storeLink(links[store]) || undefined" :target="storeLink(links[store]) ? '_blank' : undefined" :rel="storeLink(links[store]) ? 'noopener noreferrer' : undefined" :type="storeLink(links[store]) ? undefined : 'button'" :disabled="statusOnly && !storeLink(links[store])" class="landing-store" @click="!storeLink(links[store]) && $emit('unavailable')"><FontAwesomeIcon :icon="store === 'ios' ? faApple : faGooglePlay" :class="{ 'landing-play-icon': store === 'android' }"/><span><small>{{ store === 'ios' ? t.appStore : t.googlePlay }}</small><strong>{{ store === 'ios' ? 'App Store' : 'Google Play' }}</strong></span></component></div></template>
<style scoped>
.landing-stores{display:flex;gap:14px;flex-wrap:wrap}.landing-store{display:flex;gap:10px;align-items:center;min-height:55px;min-width:147px;padding:8px 14px;background:#030506;color:#fff;border:1px solid #c5c9c8;border-radius:8px;text-decoration:none;font-family:Arial,sans-serif;text-align:left;cursor:pointer;transition:background .15s,border-color .15s}.landing-store:hover{background:#1a2020;border-color:#fff}.landing-store:disabled{opacity:.55;cursor:default}.landing-store svg{width:27px;height:30px;flex:none}.landing-store .landing-play-icon{color:#43d291}.landing-store span{display:flex;flex-direction:column;gap:1px}.landing-store small{font-size:9px;line-height:1.3}.landing-store strong{font-size:19px;font-weight:500;line-height:1.1;white-space:nowrap}
@media(min-width:801px) and (max-width:1100px){.landing-stores{gap:11px}.landing-store{min-width:123px;min-height:44px;padding:7px 10px;gap:8px}.landing-store strong{font-size:16px}.landing-store small{font-size:8px}.landing-store svg{width:22px;height:25px}}
@media(min-width:601px) and (max-width:800px){.landing-stores{gap:10px}.landing-store{min-width:0;min-height:33px;padding:5px 8px;gap:6px;border-radius:5px}.landing-store strong{font-size:12px}.landing-store small{font-size:6px}.landing-store svg{width:18px;height:20px}}
@media(max-width:600px){.landing-stores{gap:12px}.landing-store{min-width:135px;min-height:48px;padding:8px 12px;gap:8px}.landing-store strong{font-size:17px}.landing-store svg{width:23px;height:26px}}
@media(max-width:380px){.landing-stores{gap:10px}.landing-store{min-width:0;padding:9px 10px;gap:7px}.landing-store strong{font-size:17px}.landing-store svg{width:24px}}
</style>
