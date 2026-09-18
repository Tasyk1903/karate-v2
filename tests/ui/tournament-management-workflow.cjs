const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    page.setDefaultTimeout(8000);
    const errors = [], unexpected = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.addInitScript(() => localStorage.setItem('kr-locale', 'en'));
    const champ = { id: 28, name: 'Championship', can_manage: true, can_delete_forms: true, status: 'active', export_coaches: [] };
    const tournament = { id: 57, name: 'Kumite tournament', can_manage: true, can_delete: true, tournament_type: 1, downloads: {}, tatami: 1, region_id: 1, scale_id: 1, age_from: 0, age_to: 100, price: 0, address: 'City', date_input: '2026-09-08', date_finish_input: '2026-09-08', date_commission_input: '2026-09-08T18:00', status: 'active', clubs: [], documents: [{ key: 'regulation_document', url: '/storage/regulation_document/old.pdf' }, { key: 'application_document', url: '/storage/application_document/old.pdf' }] };
    let coaches = [{ id: 10, name: 'Coach One', club: 'DOJO' }, { id: 11, name: 'Coach Two', club: 'DOJO' }];
    let lists = [{ id: 1, name: 'List One', type: 'kumite' }, { id: 2, name: 'List Two', type: 'kumite' }];
    let forms = [1, 2].map(id => ({ id, organization_name: `Team ${id}`, status: 'closed', clubs: [], participants_count: 2, editor_url: `/panel/tournaments/28/forms/${id}` }));
    let deleted = false, rejected = false, overviewSaved = false, applied = false, accepted = false, attached = false;
    const meta = total => ({ current_page: 1, last_page: 1, total, per_page: 10, from: total ? 1 : null, to: total || null });
    const options = { regions: [{ id: 1, name: 'Region' }], scales: [{ id: 1, name: 'Scale' }] };
    await page.route('**/api/**', async route => {
        const req = route.request(), url = new URL(req.url()), path = url.pathname, method = req.method();
        const json = data => route.fulfill({ json: data });
        if (path === '/api/auth/user') return json({ user: { id: 1, name: 'Federation', roles: ['Organization'], capabilities: { team_sections: ['trainers', 'students'] }, unread_notifications: 0, agreements_required: false } });
        if (path === '/api/panel/tournaments') return json({ items: { data: [champ], meta: meta(1) }, stats: {}, filters: { regions: [] } });
        if (path === '/api/panel/tournaments/28' && method === 'POST') {
            assert.match(req.postData(), /name="_method"\r\n\r\nPUT/);
            assert.match(req.postData(), /Updated championship/); champ.name = 'Updated championship'; return json({ item: champ });
        }
        if (path === '/api/panel/tournaments/28') return json({ championship: champ, items: { data: deleted ? [] : [tournament], meta: meta(deleted ? 0 : 1) }, forms, stats: {}, filters: { regions: [] }, create_options: options });
        if (path.endsWith('/forms/bulk-delete')) { assert.deepEqual(req.postDataJSON().ids, [1, 2]); forms = []; return json({ ok: true }); }
        if (path.endsWith('/coaches/bulk-detach')) {
            assert.deepEqual(req.postDataJSON().ids, [10, 11]);
            if (!rejected) { rejected = true; return route.fulfill({ status: 422, json: { message: 'Selection unavailable' } }); }
            coaches = []; return json({ ok: true });
        }
        if (path.endsWith('/lists/bulk-detach')) { assert.deepEqual(req.postDataJSON().ids, [1, 2]); lists = []; return json({ ok: true }); }
        if (path === '/api/panel/tournaments/28/items/57') {
            if (method === 'DELETE') { deleted = true; return json({ deleted: true }); }
            if (method === 'POST') {
                const body = req.postData();
                assert.match(body, /name="_method"\r\n\r\nPUT/);
                assert.match(body, /name="regulation_document"; filename="new.pdf"/);
                assert.match(body, /name="remove_application_document"\r\n\r\n1/);
                assert.match(body, /name="accepts_organization_applications"\r\n\r\n1/);
                overviewSaved = true; tournament.accepts_organization_applications = true;
                tournament.documents = [{ key: 'regulation_document', url: '/storage/regulation_document/new.pdf' }];
            }
            return json({ championship: champ, tournament, detail: { students: { data: [], meta: meta(0) }, coaches, lists, options: { coaches: [], lists: [], students: [] } }, create_options: options });
        }
        if (path === '/api/panel/tournament-applications') {
            const view = url.searchParams.get('view');
            return json({ data: [{ id: 90, name: 'Partner tournament', championship: 'Partner championship', date: '08.09.2026', date_finish: '09.09.2026', address: 'City', price: 100, application_id: applied || view !== 'discover' ? 7 : null, status: accepted ? 'accepted' : 'pending', can_apply: view === 'discover' && !applied, can_decide: view === 'incoming', can_open_team: view === 'outgoing' && accepted }], last_page: 1 });
        }
        if (path.endsWith('/90/apply')) { applied = true; return json({ id: 7 }); }
        if (path.endsWith('/7/decision')) { assert.equal(req.postDataJSON().status, 'accepted'); accepted = true; return json({ ok: true }); }
        if (path.endsWith('/7/team')) return json({ tournament: { name: 'Partner tournament', championship: 'Partner championship' }, coaches: { data: [{ id: 9, name: 'Own coach', club: 'DOJO', attached, can_detach: attached }], last_page: 1 }, can_manage_team: true });
        if (path.endsWith('/7/coaches')) { assert.deepEqual(req.postDataJSON().ids, [9]); attached = req.postDataJSON().attach; return json({ ok: true }); }
        unexpected.push(path); return route.fulfill({ status: 404, json: {} });
    });
    const dialog = () => page.getByRole('dialog');
    const button = name => page.getByRole('button', { name, exact: true });
    const noOverflow = async () => assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    const bulk = async label => {
        await button(label).click();
        for (const checkbox of await dialog().getByRole('checkbox').all()) await checkbox.check();
        await dialog().getByRole('button', { name: 'Continue', exact: true }).click();
        await dialog().getByRole('button', { name: 'Confirm', exact: true }).click();
    };
    try {
        await page.goto(`${base}/panel/tournaments/28`);
        for (const width of [1440, 360, 393, 430]) {
            await page.setViewportSize({ width, height: 900 });
            await button('Delete tournament').click();
            const close = dialog().locator('header .panel-icon-button');
            const bounds = await close.boundingBox();
            const icon = await close.locator('svg').boundingBox();
            assert(bounds.width >= 32 && bounds.width <= 44, `Close button width at ${width}px: ${bounds.width}`);
            assert(bounds.height >= 32 && bounds.height <= 44, `Close button height at ${width}px: ${bounds.height}`);
            assert(icon.width <= 20 && icon.height <= 20, `Close icon is oversized at ${width}px`);
            await noOverflow();
            await page.screenshot({ path: `/tmp/kr-tour-delete-${width}.png` });
            await close.click();
            await dialog().waitFor({ state: 'hidden' });
            assert.equal(deleted, false);
            assert.equal(new URL(page.url()).pathname, '/panel/tournaments/28');
        }
        await page.setViewportSize({ width: 1440, height: 900 });
        await button('Edit championship').click();
        await dialog().getByRole('textbox').fill('Updated championship');
        await dialog().getByRole('button', { name: 'Save', exact: true }).click();
        await dialog().waitFor({ state: 'hidden' });
        await page.getByRole('heading', { name: /Updated championship/ }).waitFor();
        await button('Team forms').click();
        await bulk('Delete team forms'); await dialog().waitFor({ state: 'hidden' });
        assert.equal(forms.length, 0); assert.equal(await button('Team forms').getAttribute('class'), 'active');
        await page.goto(`${base}/panel/tournaments/28/items/57/edit?tab=overview`);
        await page.locator('.file-dropzone').filter({ hasText: 'Regulation' }).locator('input').setInputFiles({ name: 'new.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 test') });
        await page.getByLabel('Remove current file', { exact: true }).nth(1).check();
        await page.getByLabel('Accept applications from other organizations', { exact: true }).check();
        await button('Save').click(); await page.getByRole('status').getByText('Tournament updated').waitFor(); assert.equal(overviewSaved, true);
        await noOverflow(); await page.screenshot({ path: '/tmp/kr-tour-overview-desktop.png', fullPage: true });
        await button('Trainers').click(); await bulk('Detach coaches');
        await dialog().getByRole('alert').getByText('Selection unavailable').waitFor();
        await dialog().getByRole('button', { name: 'Confirm', exact: true }).click();
        await dialog().waitFor({ state: 'hidden' }); assert.equal(coaches.length, 0); assert.match(page.url(), /tab=coaches/);
        await button('Lists').click(); await bulk('Detach lists'); await dialog().waitFor({ state: 'hidden' }); assert.equal(lists.length, 0);
        await page.reload(); await button('Lists').waitFor(); assert.equal(await button('Lists').getAttribute('class'), 'active');
        await button('Delete tournament').click(); await dialog().getByRole('button', { name: 'Cancel', exact: true }).last().click(); assert.equal(deleted, false);
        await button('Delete tournament').click(); await dialog().getByRole('button', { name: 'Delete', exact: true }).click();
        await page.waitForURL('**/panel/tournaments/28'); assert.equal(deleted, true);
        await page.goto(`${base}/panel/tournaments/applications`);
        await button('Apply').click(); await dialog().getByRole('button', { name: 'Confirm', exact: true }).click(); await dialog().waitFor({ state: 'hidden' }); assert.equal(applied, true);
        await button('Incoming').click(); await button('Accept').click(); await dialog().getByRole('button', { name: 'Confirm', exact: true }).click(); await dialog().waitFor({ state: 'hidden' }); assert.equal(accepted, true);
        await button('My applications').click(); await button('My team').click();
        await button('Attach').click(); await dialog().getByRole('button', { name: 'Confirm', exact: true }).click(); await dialog().waitFor({ state: 'hidden' }); assert.equal(attached, true);
        await page.getByText('Attached', { exact: true }).waitFor(); await noOverflow();
        await page.screenshot({ path: '/tmp/kr-tour-applications-desktop.png', fullPage: true });
        for (const theme of ['light', 'dark']) {
            await page.evaluate(value => localStorage.setItem('kr-panel-theme', value), theme);
            await page.setViewportSize({ width: 390, height: 844 }); await page.reload();
            await button('My applications').click(); await button('My team').click(); await button('Detach').click();
            await noOverflow();
            const contrast = await dialog().evaluate(element => {
                const luminance = color => color.match(/[\d.]+/g).slice(0, 3).map(Number).map(n => n / 255).map(n => n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4).reduce((sum, n, i) => sum + n * [.2126, .7152, .0722][i], 0);
                const style = getComputedStyle(element), a = luminance(style.color), b = luminance(style.backgroundColor);
                return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
            });
            assert(contrast >= 4.5, `Dialog contrast ${contrast}`);
            await page.screenshot({ path: `/tmp/kr-tour-applications-${theme}-mobile.png`, fullPage: true });
            await dialog().getByRole('button', { name: 'Cancel', exact: true }).click();
        }
        await page.goto(`${base}/panel/tournaments/28/items/57/edit?tab=overview`);
        await button('Save').waitFor(); await noOverflow();
        await page.screenshot({ path: '/tmp/kr-tour-overview-dark-mobile.png', fullPage: true });
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log('PASS: championship editing, multipart assets, bulk confirmation/error recovery, dynamic updates, tab persistence, soft deletion, organization applications and own team, desktop/mobile themes');
    } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
