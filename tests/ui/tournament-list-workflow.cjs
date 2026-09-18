const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const errors = [], unexpected = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => localStorage.setItem('kr-locale', 'en'));
    let attached = false, rejected = false, moved = false, detached = false, kumite = false, weightUpdated = false;
    const lists = [{ id: 1, name: 'Individual kata', type: 'kata', kata_type: 'personal' }, { id: 2, name: 'Team kata', type: 'kata', kata_type: 'group' }, { id: 3, name: 'Another team category', type: 'kata', kata_type: 'group' }, { id: 4, name: 'Kumite', type: 'kumite' }, { id: 5, name: 'Generated team', type: 'kata', kata_type: 'group', has_generated: true }];
    const student = { id: 101, pivot_id: 77, name: 'Student One', age: 10, weight: 30, rang: '5 kyu', trainer: 'Coach', documents_ok: true, list_name: 'Individual kata', memberships: [{ id: 10, list_id: 1, name: 'Individual kata', group_id: null, locked: false }, { id: 11, list_id: 2, name: 'Team kata', group_id: 'team-uuid', locked: false }] };
    await page.route('**/api/**', async route => {
        const url = new URL(route.request().url()), path = url.pathname, method = route.request().method();
        const json = data => route.fulfill({ json: data });
        if (path === '/api/auth/user') return json({ user: { id: 1, name: 'Federation', roles: ['Organization'], capabilities: { team_sections: ['trainers', 'students', 'pending'] }, unread_notifications: 0, agreements_required: false } });
        if (path.endsWith('/student-attach-options')) {
            const p = Number(url.searchParams.get('page') || 1), search = url.searchParams.get('search');
            return json({ data: search ? [{ id: 330, name: 'Student 330', club: 'DOJO', rang: '5 kyu', age: 10 }] : [{ id: p === 1 ? 101 : 131, name: p === 1 ? 'Student One' : 'Student 31', club: 'DOJO', rang: '5 kyu', age: 10 }], meta: { current_page: p, last_page: search ? 1 : 11, total: search ? 1 : 330 } });
        }
        if (path.endsWith('/students/group')) {
            assert.deepEqual(route.request().postDataJSON().student_ids, [101, 131, 330]);
            if (!rejected) { rejected = true; return route.fulfill({ status: 422, json: { message: 'Test validation error' } }); }
            attached = true; return json({ ok: true });
        }
        if (path.endsWith('/students/77/list')) {
            assert.deepEqual(route.request().postDataJSON(), { membership_id: 11, list_tournament_id: 3 });
            Object.assign(student.memberships[1], { list_id: 3, name: 'Another team category' }); moved = true; return json({ ok: true });
        }
        if (path.endsWith('/students/77') && method === 'DELETE') {
            assert.equal(route.request().postDataJSON().membership_id, 11); student.memberships.splice(1); detached = true; return json({ ok: true });
        }
        if (path.endsWith('/students/77') && method === 'PATCH') {
            assert.equal(route.request().postDataJSON().membership_id, 12);
            weightUpdated = true; return json({ ok: true });
        }
        if (path === '/api/panel/tournaments/28/items/57') return json({ championship: { id: 28, name: 'Championship' }, tournament: { id: 57, name: 'Kata tournament', can_manage: true, can_attach_group_students: !kumite, tournament_type: kumite ? 1 : 2, tournament_type_kata: 2, downloads: {}, date: '2026-09-06', date_finish: '2026-09-07', tatami: 1 }, detail: { students: { data: attached ? [student] : [], meta: { current_page: 1, last_page: 1, per_page: 10, total: attached ? 1 : 0 } }, lists, coaches: [], options: { coaches: [], lists: [], students: [] } } });
        unexpected.push(path); return route.fulfill({ status: 404, json: {} });
    });
    const noOverflow = async () => assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    try {
        await page.goto(`${base}/panel/tournaments/28/items/57/edit?tab=students`);
        await page.getByRole('button', { name: 'Attach group', exact: true }).click();
        let dialog = page.getByRole('dialog');
        await dialog.getByRole('checkbox').check();
        await dialog.getByRole('button', { name: 'Next page', exact: true }).click();
        await dialog.getByText('Student 31', { exact: false }).waitFor();
        await dialog.getByRole('checkbox').check();
        await dialog.getByRole('button', { name: 'Previous page', exact: true }).click();
        await dialog.getByText('Student One', { exact: false }).waitFor();
        assert.equal(await dialog.getByRole('checkbox').isChecked(), true);
        await dialog.getByRole('textbox').fill('330');
        await dialog.getByText('Student 330', { exact: false }).waitFor();
        await dialog.getByRole('checkbox').check();
        await dialog.getByRole('button', { name: 'Add', exact: true }).click();
        await dialog.getByRole('alert').getByText('Test validation error').waitFor();
        assert.equal(await dialog.getByRole('checkbox').isChecked(), true);
        await page.screenshot({ path: '/tmp/kr-list-picker-desktop.png', fullPage: true });
        await dialog.getByRole('button', { name: 'Add', exact: true }).click();
        await dialog.waitFor({ state: 'hidden' });
        await page.getByRole('button', { name: 'Move to list', exact: true }).click();
        dialog = page.getByRole('dialog');
        assert.equal(await dialog.getByRole('button', { name: 'Move to list', exact: true }).isEnabled(), false);
        await dialog.getByRole('radio').nth(1).check();
        await dialog.getByText('The entire group will move, preserving its members.', { exact: true }).waitFor();
        assert.deepEqual(await dialog.locator('select option').allTextContents(), ['Select list', 'Another team category']);
        await dialog.getByRole('combobox').selectOption('3');
        await page.screenshot({ path: '/tmp/kr-list-move-desktop.png', fullPage: true });
        await dialog.getByRole('button', { name: 'Move to list', exact: true }).click();
        await dialog.waitFor({ state: 'hidden' });
        await page.getByRole('cell', { name: 'Individual kata, Another team category', exact: true }).waitFor();
        await page.getByRole('button', { name: 'Detach application', exact: true }).click();
        dialog = page.getByRole('dialog');
        await dialog.getByRole('radio').nth(1).check();
        await dialog.getByText('The entire group will be detached. Individual applications will remain.', { exact: true }).waitFor();
        await dialog.getByRole('button', { name: 'Detach application', exact: true }).click();
        await dialog.waitFor({ state: 'hidden' });
        assert.equal(moved && detached, true);
        await noOverflow();
        await page.getByRole('button', { name: 'Lists', exact: true }).click();
        await page.getByRole('button', { name: 'Create', exact: true }).click();
        const template = page.locator('.template-modal');
        await template.getByLabel('List type').selectOption('kumite');
        await template.getByLabel('Rank from').selectOption('8');
        await template.getByLabel('Rank to').selectOption('4');
        assert.equal(await template.getByLabel('Rank from').inputValue(), '8');
        await template.getByLabel('Rank to').selectOption('0');
        assert.equal(await template.getByLabel('Rank to').locator('option:checked').textContent(), 'Dan');
        await template.getByLabel('Rank from').selectOption('10');
        assert.equal(await template.getByLabel('Rank from').locator('option:checked').textContent(), '10 kyu / 0 kyu');
        await page.screenshot({ path: '/tmp/kr-list-ranks-desktop.png', fullPage: true });
        await template.getByRole('button', { name: 'Cancel', exact: true }).click();
        await page.getByRole('button', { name: 'Participants', exact: true }).click();
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => localStorage.setItem('kr-panel-theme', theme), theme);
            await page.setViewportSize({ width: 390, height: 844 }); await page.reload();
            await page.getByRole('button', { name: 'Attach group', exact: true }).click();
            await page.getByRole('dialog').getByRole('checkbox').waitFor();
            await noOverflow();
            await page.screenshot({ path: `/tmp/kr-list-picker-${theme}-mobile.png`, fullPage: true });
            await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).last().click();
        }
        kumite = true;
        student.memberships.push({ id: 12, list_id: 6, name: 'Another personal category', group_id: null, locked: false });
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.reload();
        await page.getByRole('button', { name: 'Edit weight', exact: true }).click();
        const weightDialog = page.locator('.template-modal.confirm-modal');
        assert.equal(await weightDialog.getByRole('button', { name: 'Save', exact: true }).isEnabled(), false);
        await weightDialog.getByRole('radio').nth(1).check();
        await weightDialog.getByRole('spinbutton').fill('35');
        await weightDialog.getByRole('button', { name: 'Save', exact: true }).click();
        await weightDialog.waitFor({ state: 'hidden' });
        assert.equal(weightUpdated, true);
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log('PASS: server paging/search, persistent selection, error recovery, close/reload on success, concrete application move/detach, compatible targets, desktop/mobile themes');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
