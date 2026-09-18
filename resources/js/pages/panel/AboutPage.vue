<script setup>
defineProps({
    aboutContent: {
        type: Object,
        default: null,
    },
    logoUrl: {
        type: String,
        required: true,
    },
    t: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <section class="about-page">
        <div class="about-hero">
            <img :src="logoUrl" alt="Karate Rating">
            <div>
                <p>{{ t.aboutProject }}</p>
                <h1>{{ aboutContent?.project?.title ?? t.about }}</h1>
            </div>
        </div>

        <div class="about-grid">
            <article class="about-card about-card-wide">
                <h2>{{ t.aboutProject }}</h2>
                <p class="lead">{{ aboutContent?.project?.lead }}</p>
                <p>{{ aboutContent?.project?.description }}</p>
                <ul>
                    <li v-for="feature in aboutContent?.project?.features ?? []" :key="feature">{{ feature }}</li>
                </ul>
                <p>{{ aboutContent?.project?.goal }}</p>
            </article>

            <article class="about-card">
                <h2>{{ t.companyInfo }}</h2>
                <dl>
                    <div><dt>{{ t.companyName }}</dt><dd>{{ aboutContent?.company?.name }}</dd></div>
                    <div><dt>{{ t.inn }}</dt><dd>{{ aboutContent?.company?.inn }}</dd></div>
                    <div><dt>{{ t.address }}</dt><dd>{{ aboutContent?.company?.address }}</dd></div>
                </dl>
            </article>

            <article class="about-card">
                <h2>{{ t.contacts }}</h2>
                <dl>
                    <div><dt>Email</dt><dd><a :href="`mailto:${aboutContent?.contacts?.email}`">{{ aboutContent?.contacts?.email }}</a></dd></div>
                    <div><dt>{{ t.workTime }}</dt><dd>{{ aboutContent?.contacts?.work_time }}</dd></div>
                    <div><dt>{{ t.partners }}</dt><dd><a :href="`mailto:${aboutContent?.contacts?.partners_email}`">{{ aboutContent?.contacts?.partners_email }}</a></dd></div>
                </dl>
            </article>

            <article class="about-card">
                <h2>{{ t.bankDetails }}</h2>
                <dl class="bank-grid">
                    <div><dt>{{ t.bank }}</dt><dd>{{ aboutContent?.bank?.bank }}</dd></div>
                    <div><dt>{{ t.bik }}</dt><dd>{{ aboutContent?.bank?.bik }}</dd></div>
                    <div><dt>{{ t.account }}</dt><dd>{{ aboutContent?.bank?.account }}</dd></div>
                    <div><dt>{{ t.correspondentAccount }}</dt><dd>{{ aboutContent?.bank?.correspondent_account }}</dd></div>
                </dl>
            </article>
        </div>
    </section>
</template>
