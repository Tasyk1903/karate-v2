const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage();
    const errors = [], unexpected = [];
    let role = 'Organization';
    const items = [28, 29, 30].map(id => ({ id, name: `Championship ${id}: Международное первенство по киокушинкай карате среди юношей и девушек`, cover: '/assets/auth/kr.jpg', status: 'active', tournaments_count: 12, active_tournaments_count: 10, completed_tournaments_count: 2 }));
    const meta = { current_page: 1, last_page: 1, per_page: 10, total: 3, from: 1, to: 3 };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/api/**', route => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/api/auth/user') return route.fulfill({ json: { user: { id: 4, name: 'Federation', roles: [role], agreements_required: false, capabilities: { team_sections: ['students', 'trainers'] } } } });
        if (path === '/api/panel/tournaments') return route.fulfill({ json: { items: { data: items, meta }, stats: {}, filters: { regions: [] } } });
        if (path === '/api/panel/tournaments/28') return route.fulfill({ json: { championship: items[0], items: { data: [], meta: { ...meta, total: 0 } }, stats: {}, filters: { regions: [] }, forms: [] } });
        unexpected.push(path);
        return route.fulfill({ status: 404, json: {} });
    });
    try {
        await page.goto(`${base}/panel/tournaments`);
        for (const locale of ['ru', 'en']) for (const theme of ['light', 'dark']) {
            role = locale === 'ru' ? 'Organization' : 'Secretary';
            await page.evaluate(({ locale, theme }) => { localStorage.setItem('kr-locale', locale); localStorage.setItem('kr-panel-theme', theme); }, { locale, theme });
            for (const width of [360, 393, 430, 768, 1024, 1440]) {
                await page.setViewportSize({ width, height: 900 });
                await page.goto(`${base}/panel/tournaments`);
                await page.locator('.tournament-card').last().waitFor();
                const bounds = await page.locator('.tournament-card').evaluateAll(cards => cards.map(card => {
                    const r = card.getBoundingClientRect();
                    return { x: r.x, y: r.y, right: r.right, bottom: r.bottom, overflow: card.scrollWidth > card.clientWidth + 1 };
                }));
                assert.equal(bounds.length, 3);
                assert.ok(bounds.every(card => !card.overflow && card.right <= width));
                if (width <= 1024) {
                    assert.ok(bounds.every(card => card.x === bounds[0].x));
                    assert.ok(bounds[1].y >= bounds[0].bottom && bounds[2].y >= bounds[1].bottom);
                } else {
                    assert.ok(bounds.every(card => card.y === bounds[0].y));
                    assert.ok(bounds[1].x >= bounds[0].right && bounds[2].x >= bounds[1].right);
                }
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
                if (width <= 430) {
                    const title = await page.locator('.tournament-heading h1').boundingBox();
                    const action = await page.locator('.tournament-heading .create-button').boundingBox();
                    assert.ok(title.height <= 30, 'Mobile title must fit on one line');
                    assert.ok(action.y >= title.y + title.height, 'Actions must not squeeze the heading');
                }
                if (width === 393) await page.screenshot({ path: `/tmp/kr-championship-grid-${locale}-${theme}.png`, fullPage: true });
            }
        }
        await page.setViewportSize({ width: 393, height: 900 });
        await page.locator('.tournament-card').first().click();
        await page.waitForURL('**/panel/tournaments/28');
        await page.locator('.championship-detail-hero').waitFor();
        assert.deepEqual(errors, []);
        assert.deepEqual(unexpected, []);
        console.log('PASS: one championship per mobile row, three on desktop, RU/EN light/dark, card navigation');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
