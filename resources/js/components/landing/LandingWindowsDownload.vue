<script setup>
import { Download } from '@lucide/vue';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faWindows } from '@fortawesome/free-brands-svg-icons';

defineProps({ t: { type: Object, required: true } });
</script>

<template>
    <a class="landing-windows-download" href="/downloads/KumiteRating-Offline-Setup.exe" download="KumiteRating-Offline-Setup.exe" :aria-label="t.windowsDownloadLabel">
        <FontAwesomeIcon :icon="faWindows" class="landing-windows-logo" aria-hidden="true"/>
        <span class="landing-windows-copy">
            <strong>{{ t.windowsDownload }}</strong>
            <small>KumiteRating Offline <span aria-hidden="true">·</span> Windows 10/11, x64</small>
        </span>
        <Download class="landing-windows-arrow" aria-hidden="true"/>
    </a>
</template>

<style scoped>
.landing-windows-download{display:flex;align-items:center;gap:13px;width:fit-content;max-width:100%;min-height:62px;margin-top:18px;padding:12px 15px;border:1px solid #d8dddf;border-radius:8px;background:#fff;color:#182025!important;text-decoration:none;transition:border-color .15s,background .15s}
.landing-windows-download:hover{border-color:#ed2631;background:#fff7f7}
.landing-windows-download .landing-windows-logo{width:23px;height:23px;flex:none}
.landing-windows-copy{display:flex;flex-direction:column;gap:4px;min-width:0}
.landing-windows-copy strong{font-size:14px;line-height:1.3;font-weight:600}
.landing-windows-copy small{font-size:10px;line-height:1.4;color:#677079;overflow-wrap:anywhere}
.landing-windows-download .landing-windows-arrow{width:18px;height:18px;margin-left:7px;color:#db202b;flex:none}
@media(min-width:601px) and (max-width:800px){.landing-windows-download{gap:9px;padding:9px 10px;min-height:49px;margin-top:12px}.landing-windows-copy strong{font-size:12px}.landing-windows-copy small{font-size:9px}.landing-windows-download .landing-windows-logo{width:20px;height:20px}.landing-windows-download .landing-windows-arrow{margin-left:0;width:16px;height:16px}}
@media(max-width:380px){.landing-windows-download{gap:10px;padding:11px 12px}.landing-windows-download .landing-windows-arrow{margin-left:0}}
</style>
