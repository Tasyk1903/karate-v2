const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1024, height: 900 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    let configured = false, documentFailure = false, language;
    await page.route('**/api/auth/user', route => route.fulfill({ status: 401, json: {} }));
    await page.route('**/api/public/app-links', route => route.fulfill({ json: { ios: configured ? 'https://apps.apple.com/test-app' : null, android: configured ? 'https://play.google.com/store/apps/details?id=test' : null, contact_email: 'info@karaterating.ru' } }));
    await page.route('**/api/public/agreements/*', route => {
        language = route.request().headers()['accept-language'];
        return route.fulfill({ status: documentFailure ? 500 : 200, json: documentFailure ? {} : { title: language === 'ru' ? 'Политика конфиденциальности' : 'Privacy policy', content: '<p>Legal document content</p>' } });
    });
    async function inspect() {
        await page.locator('.landing-hero h1').waitFor();
        await page.locator('.kr-landing img').evaluateAll(async images => {
            await Promise.all(images.map(async img => { img.loading = 'eager'; await img.decode(); }));
        });
        await page.evaluate(() => document.fonts.ready);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'page overflow');
        assert.equal(await page.locator('.kr-landing img').evaluateAll(images => images.every(img => img.naturalWidth > 0)), true);
        assert.equal(await page.locator('.landing-hero-content').evaluate(element => element.scrollWidth > element.clientWidth + 2), false, 'hero text overflow');
        assert.equal(await page.locator('.landing-stat').evaluateAll(elements => elements.every(element => element.scrollWidth <= element.clientWidth + 1)), true, 'statistics overflow');
        assert.deepEqual(await page.locator('.landing-stat strong').allTextContents(), ['3 000+', '50+', '40+', '4+']);
        assert.equal(await page.locator('.landing-stat span').last().textContent(), await page.evaluate(() => localStorage.getItem('kr-locale')) === 'ru' ? 'страны' : 'countries');
        assert.equal(await page.locator('.landing-role').count(), 4);
        assert.equal(await page.locator('.landing-platform-item').count(), 4);
        assert.equal(await page.locator('.landing-detail').count(), 4);
        assert.equal(await page.locator('.landing-detail-1 .landing-checklist li').last().textContent(), await page.evaluate(() => localStorage.getItem('kr-locale')) === 'ru' ? 'В разработке: Kumite разбор, Kumite класс' : 'In development: Kumite reviews, Kumite classes');
        assert.equal(await page.locator('.landing-nav .landing-brand img').getAttribute('src'), '/assets/auth/kr.jpg');
        assert.match(await page.locator('.landing-nav .landing-brand').innerText(), /Kumite Rating/);
        assert.match(await page.locator('.landing-footer-bottom p').innerText(), /^© 2024 Kumite Rating\./);
        assert.equal(await page.locator('.landing-download-inner>img').getAttribute('src'), '/assets/landing/download-phones-moscow-kyokushin.webp');
        assert(await page.evaluate(() => document.documentElement.scrollHeight - (document.querySelector('.landing-footer').getBoundingClientRect().bottom + scrollY)) <= 1, 'no scrollable strip below footer');
        const symbols = page.locator('.landing-hero-kanji, .landing-kanji');
        assert.equal(await symbols.count(), 3);
        assert.equal(await symbols.evaluateAll(images => images.every(img => img.getAttribute('src') === '/assets/landing/kyokushinkai-symbol.webp')), true, 'shared Kyokushinkai symbol');
        assert.equal(await page.locator('.landing-hero-image, .landing-tournament-art, .landing-detail-art, .landing-download-inner>img').evaluateAll(images => images.length === 7 && images.every(img => img.getAttribute('src').endsWith('-kyokushin.webp'))), true, 'edited illustration assets');
        assert.equal(await page.locator('.landing-role, .landing-platform-item, .landing-detail-copy, .landing-footer-top').evaluateAll(elements => elements.every(element => element.scrollWidth <= element.clientWidth + 1)), true, 'content overflow');
    }
    async function inspectTransparency() {
        const assets = page.locator('.landing-hero-kanji, .landing-detail-1 img, .landing-detail-2 img, .landing-detail-3 img, .landing-download-inner>img');
        const samples = await assets.evaluateAll(images => images.map(img => {
            const canvas = document.createElement('canvas');
            canvas.width = 64; canvas.height = 96;
            const context = canvas.getContext('2d');
            context.drawImage(img, 0, 0, canvas.width, canvas.height);
            const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
            let transparent = 0, opaque = 0;
            for (let index = 3; index < pixels.length; index += 4) {
                if (pixels[index] < 10) transparent++;
                if (pixels[index] > 240) opaque++;
            }
            return { src: img.getAttribute('src'), transparent, opaque };
        }));
        assert.equal(samples.length, 5);
        for (const sample of samples) {
            assert(sample.transparent > 64 * 96 * 0.05, `${sample.src}: transparent background`);
            assert(sample.opaque > 64 * 96 * 0.1, `${sample.src}: visible image content`);
        }
    }
    try {
        await page.goto(base);
        for (const locale of ['ru', 'en']) {
            await page.evaluate(value => localStorage.setItem('kr-locale', value), locale);
            for (const [width, height] of [[736,950],[1024,900],[1440,1000],[1920,1080],[768,1024],[360,800],[393,852],[430,932]]) {
                await page.setViewportSize({ width, height });
                await page.goto(base);
                await inspect();
                await page.screenshot({ path: `/tmp/kr-reference-${locale}-${width}.png`, fullPage: true });
                if (width <= 600) {
                    await page.locator('.landing-menu-button').click();
                    await page.locator('.landing-mobile-nav').getByRole('button', { name: 'FAQ', exact: true }).click();
                } else await page.locator('.landing-desktop-nav').getByRole('button', { name: 'FAQ', exact: true }).click();
                await page.locator('dialog[open] summary').first().click();
                assert(await page.locator('dialog[open] details').first().getAttribute('open') !== null);
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('dialog[open]').count(), 0);
                await page.locator('.landing-hero-content .landing-store').first().click();
                await page.locator('dialog[open] .landing-publication-status').waitFor();
                assert.equal(await page.locator('dialog[open] .landing-store:disabled').count(), 2);
                await page.keyboard.press('Escape');
            }
        }
        await inspectTransparency();
        await page.locator('.landing-tournament-copy .landing-primary').click();
        assert.equal(await page.locator('dialog[open] .landing-checklist li').count(), 5);
        await page.locator('dialog[open] a[href="#app"]').click();
        assert.equal(await page.locator('dialog[open]').count(), 0);
        assert.equal(new URL(page.url()).hash, '#app');
        await page.locator('.landing-footer nav button').nth(1).click();
        await page.getByText('Legal document content', { exact: true }).waitFor();
        assert.equal(language, 'en');
        await page.keyboard.press('Escape');
        documentFailure = true;
        await page.locator('.landing-footer nav button').nth(1).click();
        await page.locator('dialog[open] [role=alert]').waitFor();
        await page.keyboard.press('Escape');
        await page.locator('.landing-footer nav button').nth(0).click();
        assert.equal(await page.locator('dialog[open] .landing-contact').getAttribute('href'), 'mailto:info@karaterating.ru');
        await page.keyboard.press('Escape');
        configured = true;
        await page.reload();
        await inspect();
        assert.equal(await page.locator('.landing-hero-content .landing-store').first().getAttribute('href'), 'https://apps.apple.com/test-app');
        await page.locator('.landing-role').nth(2).click();
        await page.waitForURL('**/login');
        assert.deepEqual(errors, []);
        console.log('PASS new reference landing: 16 viewports/locales, Kyokushinkai assets/alpha, copyright 2024, all sections, FAQ, downloads, documents, contacts, login');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
