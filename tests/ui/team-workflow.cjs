const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    let role = null;
    let deletedRequests = 0;
    let members = [1, 2].map(id => ({ id, full_name: `Judge ${id}`, first_name: 'Judge', last_name: String(id), email: `judge${id}@example.test`, judge_position: 'judge1_score' }));
    const meta = total => ({ current_page: 1, last_page: 1, per_page: 10, total, from: total ? 1 : null, to: total || null });
    const team = [
        { id: 99, full_name: 'Test Coach', first_name: 'Test', last_name: 'Coach', avatar: '/assets/auth/kr.jpg', club: 'Test Club' },
        { id: 100, full_name: 'Another Member', first_name: 'Another', last_name: 'Member', club: 'Test Club' },
    ];
    // API fixtures never send mail or modify the shared application database.
    await page.route('**/api/**', async route => {
        const url = new URL(route.request().url());
        const json = data => route.fulfill({ json: data });
        if (url.pathname === '/api/auth/user') return role ? json({ user: { id: 50, name: role, roles: [role], capabilities: { team_sections: role === 'Organization' ? ['judges', 'secretaries', 'trainers', 'students', 'pending'] : ['trainers', 'students', 'pending'] } } }) : route.fulfill({ status: 401, json: {} });
        if (url.pathname === '/api/auth/trainer-registration') return json({ verification_required: true });
        if (url.pathname === '/api/panel/team/invitation-code') return json({ code: 'KR-TEST12345678' });
        if (url.pathname === '/api/panel/team/judges/members') {
            deletedRequests++;
            const ids = route.request().postDataJSON().ids;
            members = members.filter(member => !ids.includes(member.id));
            return json({ deleted_ids: ids });
        }
        if (url.pathname === '/api/panel/team') {
            const section = url.searchParams.get('section');
            const rows = section === 'judges' ? members : ['trainers', 'students'].includes(section) ? team : [];
            return json({ items: { data: rows, meta: meta(rows.length) }, stats: { judges: members.length, secretaries: 0, trainers: 2, students: 2, pending: 0 } });
        }
        if (url.pathname === '/api/panel/team/trainers/99') return json({
            trainer: { id: 99, full_name: 'Test Coach', first_name: 'Test', last_name: 'Coach', email: 'coach@example.test', club: 'Test Club' },
            capabilities: { detach_students: role === 'Organization' }, filters: { tournaments: [] },
            documents: ['passport', 'brand', 'insurance', 'ikoCard'].map(key => ({ key, file: '/assets/auth/kr.jpg' })),
            students: { data: [{ id: 101, first_name: 'Test', last_name: 'Student', full_name: 'Test Student' }], meta: meta(1) },
        });
        return route.fulfill({ status: 404, json: { message: 'Unmocked API request' } });
    });
    await page.addInitScript(() => localStorage.setItem('kr-locale', 'en'));
    try {
        await page.goto(`${base}/panel/register`);
        await page.getByRole('heading', { name: 'Coach registration' }).waitFor();
        await page.getByLabel('Organization code', { exact: true }).fill('KR-TEST12345678');
        await page.getByLabel('Email', { exact: true }).fill('coach@example.test');
        await page.getByLabel('Last name', { exact: true }).fill('Coach');
        await page.getByLabel('First name', { exact: true }).fill('Test');
        await page.getByLabel('Password', { exact: true }).fill('safe-password');
        await page.getByLabel('Confirm password', { exact: true }).fill('safe-password');
        assert.equal(await page.getByLabel('Password', { exact: true }).getAttribute('type'), 'password');
        await page.screenshot({ path: '/tmp/kr-registration-desktop.png', fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        await page.screenshot({ path: '/tmp/kr-registration-mobile.png', fullPage: true });
        await page.getByRole('button', { name: 'Continue', exact: true }).click();
        await page.getByRole('heading', { name: 'Verify email' }).waitFor();
        await page.getByRole('button', { name: 'Back to registration' }).click();
        await page.getByRole('button', { name: 'Existing account', exact: true }).click();
        assert.equal(await page.getByLabel('First name', { exact: true }).count(), 0);
        role = 'Organization';
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${base}/panel/team`);
        await page.getByText('KR-TEST12345678').waitFor();
        for (const section of ['Trainers', 'Students']) {
            await page.locator('.team-tabs').getByRole('button', { name: section, exact: true }).click();
            await page.locator('.team-avatar').first().waitFor();
            for (const width of [1280, 360, 393, 430]) {
                await page.setViewportSize({ width, height: 900 });
                const cells = await page.locator('td:has(> .team-avatar)').evaluateAll(elements => elements.map(cell => {
                    const style = getComputedStyle(cell);
                    const avatar = cell.querySelector('.team-avatar').getBoundingClientRect();
                    return { overflow: style.textOverflow, available: cell.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight), width: avatar.width, height: avatar.height };
                }));
                assert.equal(cells.length, 2);
                for (const cell of cells) {
                    assert(cell.available >= cell.width, `Avatar clipped in ${section} at ${width}px: ${JSON.stringify(cell)}`);
                    assert.equal(cell.overflow, 'clip');
                    assert.equal(cell.width, 34);
                    assert.equal(cell.height, 34);
                }
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
                await page.screenshot({ path: `/tmp/kr-team-${section}-${width}.png` });
            }
        }
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.locator('.team-tabs').getByRole('button', { name: 'Judges', exact: true }).click();
        await page.getByRole('checkbox', { name: 'Select this page' }).check();
        await page.getByTitle('Delete selected', { exact: true }).click();
        assert.equal(deletedRequests, 0);
        await page.getByRole('dialog').locator('footer').getByRole('button', { name: 'Cancel', exact: true }).click();
        assert.equal(members.length, 2);
        await page.getByTitle('Delete selected', { exact: true }).click();
        await page.getByRole('dialog').getByRole('button', { name: 'Delete', exact: true }).click();
        await page.getByRole('dialog').waitFor({ state: 'hidden' });
        assert.equal(deletedRequests, 1);
        assert.equal(await page.locator('tbody').getByRole('checkbox').count(), 0);
        for (const currentRole of ['Organization', 'Secretary']) {
            role = currentRole;
            await page.goto(`${base}/panel/team/trainers/99`);
            await page.getByRole('heading', { name: 'Test Coach', exact: true }).waitFor();
            assert.equal(await page.getByTitle('Detach', { exact: true }).count(), role === 'Organization' ? 1 : 0);
            assert.equal(await page.locator('.trainer-documents, .trainer-document-grid, .student-document-preview').count(), 0);
            await page.getByText('Test Student', { exact: true }).waitFor();
            await page.screenshot({ path: `/tmp/kr-trainer-profile-${role}.png`, fullPage: true });
        }
        assert.deepEqual(errors, []);
        console.log('PASS: registration, mobile layout, avatar cells, confirmation-only bulk deletion, dynamic rows, secretary ACL and trainer profile without documents');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
