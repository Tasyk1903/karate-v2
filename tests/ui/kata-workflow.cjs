const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    page.setDefaultTimeout(8000);
    const errors = [], unexpected = [], writes = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.addInitScript(() => localStorage.setItem('kr-locale', 'en'));
    const fields = ['referee_score', 'judge1_score', 'judge2_score', 'judge3_score', 'judge4_score'];
    const champ = { id: 28, name: 'Championship', can_manage: true };
    const tournament = { id: 57, name: 'Online kata tournament', can_manage: true, tournament_type: 2, tournament_type_kata: 2, is_online_kata: true, downloads: {}, status: 'active', clubs: [], documents: [] };
    const lists = [{ id: 1, name: 'Boys 10-11 individual kata', has_generated: true, type: 'kata', participants_count: 4 }];
    const person = id => ({ id, first_name: 'Alexandrovich', last_name: 'Konstantinopolsky ' + id, rang: '5 kyu', club: 'International Karate Federation', coach_name: 'Coach Alexandrov', online_application: null, can_upload_final_video: false });
    const row = (id, final = false) => ({ id, round: final ? 'FINAL' : 'PRELIMINARY STAGE', participant_number: id, student: { ...person(id), can_upload_final_video: final, final_video_uploaded: final }, group_students: [], ...Object.fromEntries(fields.map(f => [f, '8.0'])), total_score: '24.0', rank: null, winner_1: false, winner_2: false, winner_3: false });
    let version = 1, videoFailure = true;
    const state = { finalists_count: 4, can_edit: true, can_edit_numbers: true, editable_score_fields: fields, is_judge: false, is_online_kata: true, education_categories: [{ id: 1, name: 'Taikyoku sono san' }], pools: { pre: [row(1), row(2)], final: [] } };
    const revision = () => String(version).padStart(64, '0');
    const payload = () => ({ ...state, revision: revision() });
    const meta = { current_page: 1, last_page: 1, total: 0, per_page: 10 };
    await page.route('**/api/**', async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const json = data => route.fulfill({ json: data });
        if (path === '/api/auth/user') return json({ user: { id: 1, name: 'Federation', roles: ['Organization'], capabilities: { team_sections: ['trainers', 'students'] }, unread_notifications: 0, agreements_required: false } });
        if (path === '/api/panel/tournaments/28/items/57') return json({ championship: champ, tournament, detail: { students: { data: [], meta }, coaches: [], lists, options: { coaches: [], lists: [], students: [] } }, create_options: { regions: [], scales: [] } });
        if (path.endsWith('/kata/1')) return json(payload());
        if (path.endsWith('/final-video')) {
            assert(req.postData().includes('video.mp4'));
            if (videoFailure) { videoFailure = false; return route.fulfill({ status: 500, json: { message: 'Upload failed. Try again.' } }); }
            writes.push('video'); return json({ second_round: { video_uploaded: true, category_id: 1 } });
        }
        const cell = path.match(/\/kata-pools\/(\d+)\/(score|number)$/);
        if (cell) {
            const data = req.postDataJSON(), p = [...state.pools.pre, ...state.pools.final].find(p => p.id === +cell[1]);
            if (cell[2] === 'number') { p.participant_number = data.participant_number; version++; return json(payload()); }
            assert.equal(data.original_value, p[data.field]);
            const value = String(data.value).replace(',', '.');
            if (value && (!/^(?:[0-9]|10)(?:\.[0-9])?$/.test(value) || Number(value) > 10)) return route.fulfill({ status: 422, json: { message: 'Score must be 0-10, step 0.1.' } });
            if (state.pools.final.length && !data.confirmed) return route.fulfill({ status: 409, json: { code: 'kata_confirmation_required', message: 'Clear the final and previous results?' } });
            if (data.confirmed) { assert.equal(data.revision, revision()); state.pools.final = []; }
            p[data.field] = value ? Number(value).toFixed(1) : null;
            p.total_score = fields.every(f => p[f] != null) ? fields.map(f => Number(p[f])).sort((a, b) => a - b).slice(1, 4).reduce((a, b) => a + b, 0).toFixed(1) : null;
            writes.push(data.field); version++;
            await new Promise(resolve => setTimeout(resolve, 120));
            return json(payload());
        }
        if (path.endsWith('/kata/1/final')) {
            const data = req.postDataJSON(); assert.equal(data.revision, revision());
            if (state.pools.final.length && !data.confirmed) return route.fulfill({ status: 409, json: { code: 'kata_confirmation_required', message: 'Replace the existing final?' } });
            state.pools.final = [row(3, true)]; version++; return json(payload());
        }
        if (path.endsWith('/kata/1/winners')) return route.fulfill({ status: 422, json: { message: 'Resolve the tie before generating results.' } });
        unexpected.push(path); return route.fulfill({ status: 404, json: {} });
    });
    const score = n => page.locator('.kata-score-table').first().locator('input[aria-label]').nth(n);
    const button = name => page.getByRole('button', { name, exact: true });
    const confirmation = page.locator('.confirm-modal');
    const submit = async (n, value) => { await score(n).fill(value); await score(n).press('Enter'); };
    const settled = async n => { await page.waitForFunction(n => !document.querySelectorAll('.kata-score-table input[aria-label]')[n].disabled, n); };
    try {
        await page.goto(`${base}/panel/tournaments/28/items/57/edit?tab=brackets&list=1`);
        await submit(0, '8,1'); await settled(0); assert.equal(await score(0).inputValue(), '8.1');
        await submit(1, '8.2'); await score(2).fill('8.3'); await settled(1);
        assert.equal(await score(2).inputValue(), '8.3'); await score(2).press('Enter'); await settled(2);
        assert.equal(state.pools.pre[0].judge1_score, '8.2'); assert.equal(state.pools.pre[0].judge2_score, '8.3');
        await submit(0, ''); await settled(0); assert.equal(state.pools.pre[0].total_score, null);
        await submit(0, '11'); await page.getByRole('alert').getByText('Score must be 0-10, step 0.1.', { exact: false }).waitFor();
        assert.equal(await score(0).inputValue(), '11'); assert.equal(await score(0).getAttribute('aria-invalid'), 'true');
        await submit(0, '8'); await settled(0);
        await button('Generate final').click(); await button('Regenerate final').waitFor();
        await submit(0, '7'); await confirmation.waitFor();
        await confirmation.getByRole('button', { name: 'Cancel', exact: true }).click(); await settled(0);
        assert.equal(state.pools.final.length, 1); assert.equal(await score(0).inputValue(), '7');
        await score(0).press('Enter'); await confirmation.waitFor();
        await page.screenshot({ path: '/tmp/kr-kata-confirm-desktop.png', fullPage: true });
        await confirmation.getByRole('button', { name: 'Confirm', exact: true }).click(); await settled(0);
        assert.equal(state.pools.final.length, 0); assert.equal(state.pools.pre[0].referee_score, '7.0');
        await button('Generate final').click(); await button('Regenerate final').waitFor();
        await button('Regenerate winners').click(); await page.getByRole('alert').getByText('Resolve the tie before generating results.', { exact: false }).waitFor();
        await button('Refresh table').click(); await page.getByRole('alert').waitFor({ state: 'hidden' });
        assert(page.url().includes('tab=brackets&list=1'));
        await page.locator('.kata-final-video-state').click();
        const video = page.locator('.online-kata-video-modal');
        await video.locator('input[type=file]').setInputFiles({ name: 'video.mp4', mimeType: 'video/mp4', buffer: Buffer.from('mock video') });
        await video.getByRole('button', { name: 'Change final video', exact: true }).click();
        await video.getByText('Upload failed. Try again.').waitFor();
        assert.equal(await video.locator('input[type=file]').evaluate(el => el.files.length), 1);
        await video.getByRole('button', { name: 'Change final video', exact: true }).click(); await video.waitFor({ state: 'hidden' });
        assert(writes.includes('video'));
        for (const theme of ['light', 'dark']) {
            await page.evaluate(value => localStorage.setItem('kr-panel-theme', value), theme);
            await page.setViewportSize({ width: 390, height: 844 }); await page.reload();
            await button('Regenerate final').click(); await confirmation.waitFor();
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
            assert.equal(await confirmation.evaluate(el => el.scrollWidth > el.clientWidth + 1), false);
            await page.screenshot({ path: `/tmp/kr-kata-confirm-${theme}-mobile.png`, fullPage: true });
            await confirmation.getByRole('button', { name: 'Cancel', exact: true }).click();
        }
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log('PASS: score validation/clear, draft protection, confirmation/cancel, final/result workflow, video retry, responsive light/dark, no runtime errors');
    } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
