const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [], unexpected = [];
    page.on('pageerror', error => errors.push(error.message));
    let imports = 0, saves = 0, linked = false, polls = 0;
    const rows = Array.from({ length: 45 }, (_, index) => ({ row_id: `row-${index}`, first_name: `Participant ${index + 1}`, last_name: 'Student', birthday: '01.01.2016', age: 10, weight: 30, rank: '5 кю', club: 'HAYABUSA', coach_first_name: 'John', coach_last_name: 'Coach', category: ['kata_point', 'kata_group'], kata_group: 1, gender: 'm', city: 'Warsaw', region: 'Mazovia', razriad: '', best_results: '', student_id: null }));
    await page.addInitScript(() => localStorage.setItem('kr-locale', 'en'));
    await page.route('**/api/**', async route => {
        const url = new URL(route.request().url()), path = url.pathname;
        const json = data => route.fulfill({ json: data });
        if (path === '/api/auth/user') return json({ user: { id: 1, name: 'Federation', roles: ['Organization'], capabilities: { team_sections: ['trainers', 'students', 'pending'] }, unread_notifications: 0, agreements_required: false } });
        if (path.endsWith('/link-options')) return json({ rows: [{ id: 555, name: 'Student Participant 21', birthday: '2016-01-01', club: 'HAYABUSA', coach: 'John Coach' }], meta: { current_page: 1, last_page: 1, total: 1 } });
        if (path.endsWith('/links')) { linked = true; const body = route.request().postDataJSON(); rows.find(row => row.row_id === body.row_id).student_id = 555; return json({ ok: true }); }
        if (path.endsWith('/rows')) { saves++; const body = route.request().postDataJSON(); for (const row of body.upserts || []) Object.assign(rows.find(item => item.row_id === row.row_id), row); return json({ ok: true }); }
        if (path.endsWith('/import')) { imports++; return route.fulfill({ status: 202, json: { run_id: 9 } }); }
        if (path.endsWith('/imports/9')) { polls++; return json({ id: 9, status: polls > 1 ? 'completed' : 'queued', result: { created_users: 0, attached: 1, removed: 0, issues: 0 }, entries: polls > 1 ? [{ name: 'Edited participant', category: 'kata_group', status: 'attached', student_id: 555 }] : [], meta: { current_page: 1, last_page: 1, total: 1 } }); }
        if (path === '/api/panel/tournaments/28/forms/7') { const p = Number(url.searchParams.get('page') || 1); const search = url.searchParams.get('search') || ''; const filtered = rows.filter(row => (row.last_name + row.first_name).toLowerCase().includes(search.toLowerCase())); return json({ form: { id: 7, organization_name: 'HAYABUSA team', status: 'closed' }, championship: { id: 28, name: 'MOSCOW OPEN 2026' }, revision: 'a'.repeat(64), category_options: { kata_point: 'Individual', kata_group: 'Group' }, rows: filtered.slice((p - 1) * 20, p * 20 + 0), meta: { current_page: p, last_page: Math.ceil(filtered.length / 20), total: filtered.length }, latest_run_id: imports ? 9 : null }); }
        unexpected.push(path); return route.fulfill({ status: 404, json: {} });
    });
    const noOverflow = async () => assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    try {
        await page.goto(`${base}/panel/tournaments/28/forms/7`);
        await page.getByText('Student Participant 1', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Next page', exact: true }).click();
        await page.getByText('Student Participant 21', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Edit', exact: true }).first().click();
        const dialog = page.getByRole('dialog', { name: 'Edit participant' });
        await dialog.getByLabel('First name', { exact: true }).fill('Edited participant');
        assert.equal(await dialog.getByRole('checkbox', { name: 'Kata (individual)' }).isChecked(), true);
        assert.equal(await dialog.getByRole('checkbox', { name: 'Kata (team)' }).isChecked(), true);
        await page.screenshot({ path: '/tmp/kr-form-editor-modal-desktop.png', fullPage: true });
        await dialog.getByRole('button', { name: 'Save', exact: true }).click();
        await dialog.waitFor({ state: 'hidden' });
        await page.getByText('Student Edited participant', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Link to championship participant' }).first().click();
        await page.getByRole('radio').check();
        await page.getByRole('button', { name: 'Confirm link' }).click();
        await page.getByRole('dialog').waitFor({ state: 'hidden' });
        assert.equal(linked, true);
        assert.equal(await page.locator('a[href="/panel/team/students/555"]').getAttribute('target'), '_blank');
        await page.getByRole('button', { name: 'Import participants', exact: true }).click();
        await page.getByRole('dialog').getByRole('button', { name: 'Import participants', exact: true }).click();
        await page.getByText('Import completed', { exact: true }).waitFor();
        assert.equal(imports, 1); assert.equal(saves, 1);
        await noOverflow();
        await page.screenshot({ path: '/tmp/kr-form-editor-desktop.png', fullPage: true });
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => localStorage.setItem('kr-panel-theme', theme), theme);
            await page.setViewportSize({ width: 390, height: 844 });
            await page.reload();
            await page.getByText('Student Participant 1', { exact: true }).waitFor();
            await noOverflow();
            await page.getByRole('button', { name: 'Edit', exact: true }).first().click();
            await page.getByRole('dialog', { name: 'Edit participant' }).waitFor();
            await noOverflow();
            await page.screenshot({ path: `/tmp/kr-form-editor-modal-${theme}-mobile.png`, fullPage: true });
            await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).last().click();
        }
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log('PASS: full pagination, closed form editing, both kata categories, legacy link, import confirmation/report, desktop/mobile light and dark, no overflow');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
