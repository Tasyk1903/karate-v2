const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [], unexpected = [];
    page.on('pageerror', error => errors.push(error.message));
    let role = 'Organization', required = false, accepted = false, unread = 2, profileSaves = 0;
    let name = 'Karate Federation';
    const pagination = data => ({ data, current_page: 1, last_page: 1 });
    await page.addInitScript(() => localStorage.setItem('kr-locale', 'en'));
    // Intercept all API traffic: no actual account, consent, mail or shared DB changes.
    await page.route('**/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const json = data => route.fulfill({ json: data });
        if (path === '/api/auth/user') return role ? json({ user: { id: 50, name, roles: [role], capabilities: { view_examinations: role === 'Organization', team_sections: ['trainers', 'students', 'pending'] }, unread_notifications: unread, agreements_required: required } }) : route.fulfill({ status: 401, json: {} });
        if (path === '/api/panel/account/dashboard') return json({ trainers: 18, students: 240, pending: 3, unread, upcoming: [
            { id: 57, name: 'Kumite 10-11 years', championship: 'MOSCOW OPEN 2026', date: '2026-09-06', path: '/panel/tournaments/28/items/57/edit' },
            { id: 58, name: 'Online Kata - individual championship', championship: 'HAYABUSA CUP 2026', date: '2026-09-12', path: '/panel/tournaments/28/items/58/edit' },
        ] });
        if (path === '/api/panel/account/profile') {
            if (route.request().method() === 'POST') { profileSaves++; name = 'Updated Federation'; }
            return json({ name, first_name: 'Anna', last_name: 'Secretary', is_organization: role === 'Organization', avatar: '/assets/auth/kr.jpg' });
        }
        if (path === '/api/panel/account/notifications') return json({ ...pagination([1, 2].map(id => ({ id, content: `<p><strong>Karate Rating</strong></p><p>${id === 1 ? 'Tournament registration is now open.' : 'The championship schedule has been updated.'}</p><a href="/panel/tournaments">View championships</a>`, read_at: unread < id ? '2026-09-05' : null }))), unread });
        if (path.endsWith('/read-all')) { unread = 0; return json({ unread }); }
        if (/\/notifications\/\d+\/read$/.test(path)) { unread = 1; return json({ unread }); }
        if (path === '/api/panel/account/agreements') return json(pagination([{ id: 2, type: 'Privacy policy', version: 'v1', required: true, accepted_at: accepted ? '2026-09-05' : null }]));
        if (path === '/api/panel/account/agreements/2') return json({ id: 2, type: 'Privacy policy', version: 'v1', content: '<h3>Personal information</h3><p>This is a synthetic agreement used only for UI verification.</p><ul><li>Account information</li><li>Competition results</li></ul>', accepted_at: accepted ? '2026-09-05' : null });
        if (path === '/api/panel/account/agreements/2/accept') { accepted = true; required = false; return json({ agreements_required: false }); }
        if (path === '/api/auth/forgot-password') return json({ message: 'If an account exists for this email, a recovery link will be sent.' });
        if (path === '/api/auth/reset-password') return json({ message: 'Password changed. Sign in with your new password.' });
        unexpected.push(path); return route.fulfill({ status: 404, json: {} });
    });
    const noOverflow = async () => assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    try {
        await page.goto(`${base}/panel/dashboard`);
        await page.getByText('Kumite 10-11 years', { exact: true }).waitFor();
        await page.locator('.sidebar-user').getByText('Organizer', { exact: true }).waitFor();
        await noOverflow();
        await page.screenshot({ path: '/tmp/kr-account-dashboard-desktop.png', fullPage: true });
        await page.getByRole('button', { name: 'Profile', exact: true }).click();
        await page.getByLabel('Organization name', { exact: true }).fill('Updated Federation');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await page.getByRole('status').getByText('Changes saved').waitFor();
        assert.equal(profileSaves, 1);
        await page.reload();
        assert.equal(await page.getByLabel('Organization name', { exact: true }).inputValue(), 'Updated Federation');
        await page.getByRole('button', { name: 'Notifications', exact: true }).click();
        await page.getByText('Tournament registration is now open.').waitFor();
        await page.getByRole('button', { name: 'Mark all as read', exact: true }).click();
        await page.waitForFunction(() => !document.querySelector('.account-bell-count'));
        assert.equal(unread, 0);
        await page.getByRole('button', { name: 'Mark all as read', exact: true }).isDisabled().then(value => assert.equal(value, true));
        required = true;
        await page.goto(`${base}/panel/dashboard`);
        await page.waitForURL('**/panel/documents');
        await page.getByRole('link', { name: /Privacy policy/ }).click();
        await page.getByRole('checkbox').check();
        await page.getByRole('button', { name: 'Confirm consent', exact: true }).click();
        await page.waitForURL('**/panel/dashboard');
        await page.getByText('Kumite 10-11 years', { exact: true }).waitFor();
        assert.equal(accepted, true);
        role = 'Secretary';
        await page.goto(`${base}/panel/profile`);
        await page.getByLabel('First name', { exact: true }).waitFor();
        assert.equal(await page.getByLabel('Organization name', { exact: true }).count(), 0);
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => { document.documentElement.dataset.theme = theme; localStorage.setItem('kr-panel-theme', theme); }, theme);
            for (const path of ['dashboard', 'profile', 'notifications', 'documents/2']) {
                await page.setViewportSize({ width: 390, height: 844 });
                await page.goto(`${base}/panel/${path}`);
                await page.waitForLoadState('networkidle');
                await noOverflow();
                await page.screenshot({ path: `/tmp/kr-account-${path.replace('/', '-')}-${theme}-mobile.png`, fullPage: true });
            }
        }
        role = null;
        await page.goto(`${base}/forgot-password`);
        await page.getByLabel('Email', { exact: true }).fill('test@example.test');
        await noOverflow();
        await page.getByRole('button', { name: 'Send recovery link', exact: true }).click();
        await page.getByRole('status').waitFor();
        await page.goto(`${base}/reset-password?token=synthetic&email=test@example.test`);
        const password = page.getByLabel('New password', { exact: true });
        await password.fill('safe-password');
        await page.getByLabel('Confirm password', { exact: true }).fill('safe-password');
        assert.equal(await password.getAttribute('type'), 'password');
        await page.getByRole('button', { name: 'Show password', exact: true }).click();
        assert.equal(await password.getAttribute('type'), 'text');
        await page.getByRole('button', { name: 'Hide password', exact: true }).click();
        await page.screenshot({ path: '/tmp/kr-account-reset-mobile.png', fullPage: true });
        await page.getByRole('button', { name: 'Change password', exact: true }).click();
        await page.getByRole('status').getByText('Password changed. Sign in with your new password.').waitFor();
        await noOverflow();
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log('PASS: dashboard, profile save/reload, notification read count, consent gate, secretary fields, recovery flow, light/dark mobile layouts');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
