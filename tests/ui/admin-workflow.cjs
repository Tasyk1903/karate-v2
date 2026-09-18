const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });
    page.setDefaultTimeout(8000);
    const errors = [], unexpected = [];
    page.on('pageerror', error => errors.push(error.message));
    let auth = false, lastTarget = '', savedAgreement;
    const rows = {
        feed: [{ id: 1, text: 'A tournament announcement', author: 'Long Coach Name', user_id: 7, created_at: '2026-09-17T12:00:00Z', comments_count: 0 }],
        organizations: [{ id: 2, name: 'International Kyokushin Organization', email: 'organization@example.test', code: 'KR-ABCDEFGHIJKL', region_id: 1 }],
        regions: [{ id: 1, name: 'Very long region name for responsive verification' }, { id: 2, name: 'Second region' }],
        scales: [{ id: 1, name: 'National championship', slug: 'russian_championship' }],
    };
    const paged = data => ({ data, current_page: 1, last_page: 1, total: data.length, from: data.length ? 1 : null, to: data.length || null });
    const agreements = [{ id: 1, name: 'Terms of service', description: '<p>Русские условия</p>', description_en: '<p>English terms</p>', version: 'a'.repeat(64) }];
    const activity = { id: 1, object_type: 'User', object_id: 526, actor: { id: 5, name: 'Organizer' }, target: { id: 526, name: 'Alexandrov Alexander Alexandrovich' }, event: 'student.profile.updated', created_at: '2026-09-17 12:00:00', source: 'panel' };
    // All mutations are intercepted: no real people, mail, S3 or production data are changed.
    await page.route('**/api/**', async route => {
        const url = new URL(route.request().url()), path = url.pathname, method = route.request().method();
        const json = data => route.fulfill({ json: data });
        if (path === '/api/auth/user') return auth ? json({ user: { id: 1, name: 'Administrator', roles: ['super_admin'], capabilities: { super_admin: true } } }) : route.fulfill({ status: 401, json: {} });
        if (path === '/api/public/app-links') return json({});
        if (path.startsWith('/api/public/agreements/')) return json({ title: 'Privacy policy', content: '<p>Policy</p>' });
        if (path === '/api/admin/activity') { lastTarget = url.searchParams.get('target'); return json(paged([activity])); }
        if (path === '/api/admin/activity/1') return json({ ...activity, changes: [{ field: 'weight', old: 45, new: 46 }], context: { tournament_id: 57 } });
        if (path === '/api/admin/agreements') return json({ data: agreements });
        if (path === '/api/admin/agreements/1') { savedAgreement = route.request().postDataJSON(); Object.assign(agreements[0], savedAgreement); return json({ ok: true }); }
        if (/\/comments$/.test(path)) return json(paged([]));
        if (path.startsWith('/api/admin/education/')) {
            if (method !== 'GET') return json({ ok: true });
            if (path.endsWith('/videos')) return json(paged([{ id: 1, title: 'Kata technique', video_url: '/fixture-video.mp4' }]));
            const current = Number(url.searchParams.get('page') || 1);
            return json({ data: [{ id: current, name: current === 1 ? 'Kata category' : `Category ${current}`, price: 1200 }, { id: current + 100, name: 'Advanced international kata training with a long category name', price: 1800 }], current_page: current, last_page: 11, total: 22, from: current * 2 - 1, to: current * 2 });
        }
        const match = path.match(/^\/api\/admin\/(?:directories\/)?(feed|organizations|regions|scales)(?:\/(\d+))?$/);
        if (match) {
            const key = match[1], id = Number(match[2]);
            if (method === 'GET' && key === 'regions') {
                const current = Number(url.searchParams.get('page') || 1);
                return json({ data: rows[key].slice(current - 1, current), current_page: current, last_page: rows[key].length, total: rows[key].length, from: current, to: current });
            }
            if (method === 'GET') return json(paged(rows[key]));
            const data = route.request().postDataJSON();
            if (method === 'PUT') Object.assign(rows[key].find(row => row.id === id), data);
            if (method === 'POST') rows[key].push({ id: 90, ...data });
            if (method === 'DELETE') rows[key] = rows[key].filter(row => !(data.ids || [id]).includes(row.id));
            return json({ ok: true });
        }
        unexpected.push(`${method} ${path}`);
        return route.fulfill({ status: 404, json: {} });
    });
    await page.addInitScript(() => !localStorage.getItem('kr-locale') && localStorage.setItem('kr-locale', 'en'));
    async function noOverflow() { assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `overflow at ${page.url()}`); }
    try {
        await page.goto(base);
        await page.locator('.landing-hero h1').waitFor();
        await page.locator('.landing-hero-image').evaluate(img => img.decode());
        await noOverflow();
        await page.screenshot({ path: '/tmp/kr-landing-desktop.png', fullPage: true });
        await page.setViewportSize({ width: 393, height: 852 });
        await page.waitForFunction(() => document.querySelector('.landing-hero-image').complete);
        await page.locator('.landing-hero-image').evaluate(img => img.decode());
        await noOverflow();
        await page.screenshot({ path: '/tmp/kr-landing-mobile.png', fullPage: true });
        await page.locator('.landing-menu-button').click();
        await page.getByRole('link', { name: 'Sign in', exact: true }).first().click();
        await page.waitForURL('**/login');
        auth = true;
        await page.setViewportSize({ width: 1440, height: 960 });
        await page.goto(base + '/panel/admin/feed');
        await page.getByText('A tournament announcement', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Edit', exact: true }).click();
        await page.getByLabel('Post text', { exact: true }).fill('Moderated publication');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await page.getByText('Moderated publication', { exact: true }).waitFor();
        assert.equal(await page.locator('dialog[open]').count(), 0);
        await page.goto(base + '/panel/admin/organizations');
        await page.getByText('International Kyokushin Organization', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Add', exact: true }).click();
        await page.getByLabel('Name', { exact: true }).fill('New organization');
        await page.getByLabel('Email', { exact: true }).fill('new@example.test');
        await page.getByLabel('New password', { exact: true }).fill('TestPassword123');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await page.getByText('New organization', { exact: true }).waitFor();
        await page.goto(base + '/panel/admin/regions');
        await page.getByText('Very long region name for responsive verification', { exact: true }).waitFor();
        await page.getByRole('checkbox', { name: 'All', exact: true }).check();
        await page.getByRole('button', { name: 'Next', exact: true }).click();
        await page.getByText('Second region', { exact: true }).waitFor();
        await page.getByRole('checkbox', { name: 'All', exact: true }).check();
        await page.getByRole('button', { name: 'Previous', exact: true }).click();
        await page.getByText('Very long region name for responsive verification', { exact: true }).waitFor();
        assert(await page.getByRole('checkbox', { name: 'All', exact: true }).isChecked());
        await page.locator('.admin-selection button[title="Delete"]').click();
        await page.locator('dialog[open]').getByText('Selected: 2', { exact: false }).waitFor();
        await page.getByRole('button', { name: 'Cancel', exact: true }).click();
        await page.goto(base + '/panel/admin/agreements');
        await page.getByRole('button', { name: 'Edit', exact: true }).click();
        await page.locator('.tiptap').fill('Revised English terms');
        await page.getByRole('tab', { name: 'Русский', exact: true }).click();
        await page.locator('.tiptap').fill('Новые условия');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await page.getByText('Changes saved', { exact: true }).waitFor();
        assert(savedAgreement.description.includes('Новые условия'));
        assert(savedAgreement.description_en.includes('Revised English terms'));
        await page.goto(base + '/panel/admin/education');
        await page.getByRole('button', { name: 'Kata category', exact: true }).waitFor();
        assert(await page.locator('.admin-search-field input').evaluate(input => parseFloat(getComputedStyle(input).paddingLeft) >= 36), 'search icon overlaps text');
        await page.locator('.admin-page-summary').getByText('1–2', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Page 11', exact: true }).click();
        await page.getByText('Category 11', { exact: true }).waitFor();
        assert.equal(await page.getByRole('button', { name: 'Page 11', exact: true }).getAttribute('aria-current'), 'page');
        await page.getByRole('button', { name: 'Page 1', exact: true }).click();
        await page.getByRole('button', { name: 'Kata category', exact: true }).waitFor();
        await page.getByRole('checkbox', { name: 'Kata category', exact: true }).check();
        assert.equal(await page.locator('.admin-breadcrumb strong').textContent(), 'Categories');
        await page.getByRole('button', { name: 'Clear selection', exact: true }).click();
        const row = page.locator('.admin-table tbody tr').first();
        await row.locator('.admin-name-cell').click({ position: { x: 280, y: 12 } });
        await page.getByText('Kata technique', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Categories', exact: true }).click();
        await page.getByRole('button', { name: 'Kata category', exact: true }).waitFor();
        await page.getByRole('button', { name: 'Category videos', exact: true }).first().focus();
        await page.keyboard.press('Enter');
        await page.getByText('Kata technique', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Open', exact: true }).click();
        assert.equal(await page.locator('dialog[open] video').getAttribute('src'), '/fixture-video.mp4');
        await page.getByRole('button', { name: 'Close', exact: true }).click();
        await page.getByRole('button', { name: 'Edit', exact: true }).click();
        await page.getByLabel('Video title', { exact: true }).fill('New technique');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await page.getByText('Changes saved', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Categories', exact: true }).click();
        await page.getByRole('button', { name: 'Kata category', exact: true }).waitFor();
        await page.screenshot({ path: '/tmp/kr-admin-education-desktop.png', fullPage: true });
        await page.goto(base + '/panel/admin/activity');
        await page.getByLabel('Affected user', { exact: true }).fill('Alexandrov');
        await page.waitForTimeout(400);
        assert.equal(lastTarget, 'Alexandrov');
        await page.getByRole('button', { name: 'Open', exact: true }).click();
        await page.getByText('Weight', { exact: true }).waitFor();
        await page.screenshot({ path: '/tmp/kr-admin-activity-desktop.png', fullPage: true });
        await page.getByRole('button', { name: 'Close', exact: true }).click();
        for (const locale of ['en', 'ru']) for (const theme of ['light', 'dark']) for (const width of [360, 393, 430]) {
            await page.evaluate(({ locale, theme }) => { localStorage.setItem('kr-locale', locale); localStorage.setItem('kr-panel-theme', theme); }, { locale, theme });
            await page.setViewportSize({ width, height: 852 });
            for (const section of ['feed', 'organizations', 'activity', 'education', 'agreements', 'regions', 'scales']) {
                await page.goto(base + '/panel/admin/' + section);
                await page.locator('.admin-workspace').waitFor();
                await page.locator('.admin-table tbody tr, .admin-post').first().waitFor();
                await noOverflow();
                const alignment = await page.locator('.admin-row-actions').evaluateAll(groups => groups.every(group => {
                    const bounds = [...group.children].map(button => button.getBoundingClientRect());
                    return bounds.every(box => Math.abs(box.y - bounds[0].y) < 1 && box.width >= 32 && box.width <= 40);
                }));
                assert(alignment, `action alignment: ${section} ${width} ${theme}`);
                if (width === 393 && ['education', 'organizations'].includes(section)) await page.screenshot({ path: `/tmp/kr-admin-${section}-${locale}-${theme}-mobile.png`, fullPage: true });
            }
            if (width === 393) {
                await page.goto(base + '/panel/admin/activity');
                await page.locator('.admin-table tbody tr').waitFor();
                await page.screenshot({ path: `/tmp/kr-admin-${locale}-${theme}-mobile.png`, fullPage: true });
            }
        }
        assert.deepEqual(unexpected, []);
        assert.deepEqual(errors, []);
        console.log('PASS landing, admin workflows, 84 responsive views, RU/EN and both themes');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
