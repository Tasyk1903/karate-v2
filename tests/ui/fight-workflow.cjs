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
    const champ = { id: 28, name: 'Championship', can_manage: true };
    const tournament = { id: 57, name: 'Kumite tournament', can_manage: true, tournament_type: 1, downloads: {}, status: 'active', can_generate_all_brackets: true, clubs: [], documents: [] };
    const lists = [{ id: 1, name: 'Boys 10-11 Kumite', has_generated: true, type: 'kumite', participants_count: 4 }, { id: 2, name: 'Round Robin', has_generated: true, type: 'kumite', participants_count: 3 }];
    const people = [1, 2, 3, 4].map(id => ({ id, first_name: `Alexandrovich ${id}`, last_name: 'Konstantinopolsky', coach_line: 'International Karate Federation / Coach Alexandrov', is_success_weight: true }));
    const pool = (id, a, b, type, round, position) => ({ id, student_id: a?.id || null, opponent_id: b?.id || null, student: a, opponent: b, type, round, position_in_round: position, winner_id: null, student_wazari_count: 0, opponent_wazari_count: 0, student_ippon: false, opponent_ippon: false, absent_student: false, absent_opponent: false });
    const draws = {
        1: [pool(1, people[0], people[1], '1/2', 1, 1), pool(2, people[2], people[3], '1/2', 1, 2), pool(3, null, null, 'final', 2, 1), pool(4, null, null, '3rd', 3, 1)],
        2: [pool(5, people[0], people[1], 'Round Robin', 1, 1), pool(6, people[0], people[2], 'Round Robin', 1, 2), pool(7, people[1], people[2], 'Round Robin', 1, 3)],
    };
    const versions = { 1: 1, 2: 1 };
    const revision = list => String(versions[list]).padStart(64, '0');
    let staleNext = false;
    const meta = { current_page: 1, last_page: 1, total: 0, per_page: 10 };
    await page.route('**/api/**', async route => {
        const req = route.request(), url = new URL(req.url()), path = url.pathname;
        const json = data => route.fulfill({ json: data });
        if (path === '/api/auth/user') return json({ user: { id: 1, name: 'Federation', roles: ['Organization'], capabilities: { team_sections: ['trainers', 'students'] }, unread_notifications: 0, agreements_required: false } });
        if (path === '/api/panel/tournaments/28/items/57') return json({ championship: champ, tournament, detail: { students: { data: [], meta }, coaches: [], lists, options: { coaches: [], lists: [], students: [] } }, create_options: { regions: [], scales: [] } });
        const bracketMatch = path.match(/\/brackets\/(\d+)$/);
        if (bracketMatch) {
            const list = bracketMatch[1];
            return json({ revision: revision(list), can_swap: list === '1' && draws[list].every(p => !p.winner_id && !p.absent_student && !p.absent_opponent), swap_participant_ids: [1, 2, 3, 4], tournament: { ...tournament, fight_for_third_place: true, pools: draws[list] } });
        }
        const actionMatch = path.match(/\/(pools|brackets)\/(\d+)\/(winner|absences|tatami|swap|round-robin\/winners)$/);
        if (actionMatch) {
            const [, resource, id, action] = actionMatch;
            const list = resource === 'brackets' ? id : Number(id) < 5 ? 1 : 2;
            const data = req.postDataJSON();
            assert.equal(data.revision, revision(list));
            if (staleNext) { staleNext = false; versions[list]++; return route.fulfill({ status: 409, json: { message: 'Bracket changed. Refresh it.' } }); }
            writes.push({ action, data });
            const p = draws[list].find(p => p.id === Number(id));
            if (action === 'swap') {
                assert.notEqual(data.participant_1, data.participant_2);
                const slots = draws[list].flatMap(p => ['student', 'opponent'].map(side => ({ p, side })));
                const a = slots.find(({ p, side }) => p[`${side}_id`] === data.participant_1), b = slots.find(({ p, side }) => p[`${side}_id`] === data.participant_2);
                [a.p[a.side], b.p[b.side]] = [b.p[b.side], a.p[a.side]];
                [a.p[`${a.side}_id`], b.p[`${b.side}_id`]] = [b.p[`${b.side}_id`], a.p[`${a.side}_id`]];
            } else if (action === 'winner') {
                assert([p.student_id, p.opponent_id].includes(data.winner_id));
                const loser = p.student_id === data.winner_id ? 'opponent' : 'student';
                assert.equal(data[`${loser}_wazari_count`], 0); assert.equal(data[`${loser}_ippon`], false);
                assert(data.student_wazari_count <= 2 && data.opponent_wazari_count <= 2);
                Object.assign(p, data, { absent_student: false, absent_opponent: false });
            } else if (action === 'absences') {
                p.winner_id = null; p.student_wazari_count = p.opponent_wazari_count = 0; p.student_ippon = p.opponent_ippon = false;
                p.absent_student = data.absent_ids.includes(p.student_id); p.absent_opponent = data.absent_ids.includes(p.opponent_id);
            } else if (action === 'round-robin/winners') {
                assert.equal(new Set([data.winner_id_1rd_robbin, data.winner_id_2rd_robbin, data.winner_id_3rd_robbin]).size, 3);
                for (const p of draws[list]) Object.assign(p, data);
            } else if (action === 'tatami') p.tatami_and_fight_number = data.value;
            if (list == 2 && ['winner', 'absences'].includes(action)) for (const p of draws[list]) for (const field of ['winner_id_1rd_robbin', 'winner_id_2rd_robbin', 'winner_id_3rd_robbin']) p[field] = null;
            versions[list]++; return json({ ok: true, revision: revision(list) });
        }
        unexpected.push(path); return route.fulfill({ status: 404, json: {} });
    });
    const dialog = () => page.getByRole('dialog');
    const button = name => page.getByRole('button', { name, exact: true });
    const openFight = async (index = 0, rr = false) => {
        await page.locator(rr ? '.round-robin-participant' : '.bracket-participant').nth(index).click();
        await dialog().waitFor();
    };
    const save = async () => { await dialog().getByRole('button', { name: 'Save result', exact: true }).click(); await dialog().waitFor({ state: 'hidden' }); };
    const noOverflow = async () => {
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
        assert.equal(await dialog().evaluate(el => el.scrollWidth > el.clientWidth + 1), false);
    };
    try {
        await page.goto(`${base}/panel/tournaments/28/items/57/edit?tab=brackets&list=1`);
        await button('Swap participants').click();
        await dialog().getByRole('combobox').nth(0).selectOption('1');
        assert.equal(await dialog().getByRole('combobox').nth(1).locator('option[value="1"]').count(), 0);
        await dialog().getByRole('combobox').nth(1).selectOption('2');
        await dialog().getByRole('button', { name: 'Swap participants', exact: true }).click();
        await dialog().waitFor({ state: 'hidden' }); assert.equal(draws[1][0].student_id, 2);
        const geometry = await page.locator('.bracket-tree').evaluate(root => {
            const rect = el => { const r = el.getBoundingClientRect(); return { top:r.top, bottom:r.bottom, left:r.left, right:r.right, center:r.top+r.height/2 }; };
            const third = rect(root.querySelector('.bracket-third-place-card .bracket-match'));
            const final = rect(root.querySelector('.bracket-match.final'));
            const semis = [...root.querySelectorAll('.third-place-host .bracket-match-slot .bracket-match')].map(rect);
            return { alignment:Math.abs(third.center-final.center), overlap:semis.some(s => s.top < third.bottom && s.bottom > third.top && s.left < third.right && s.right > third.left) };
        });
        assert(geometry.alignment < 2); assert.equal(geometry.overlap, false);
        await openFight();
        await dialog().getByRole('spinbutton').nth(0).fill('2');
        assert.equal(await dialog().getByRole('spinbutton').nth(1).isDisabled(), true);
        assert.equal(await dialog().getByRole('spinbutton').nth(0).getAttribute('max'), '2');
        await dialog().getByRole('radio').nth(1).check();
        assert.equal(await dialog().getByRole('spinbutton').nth(0).inputValue(), '0');
        await dialog().getByRole('spinbutton').nth(1).fill('2');
        await dialog().getByRole('checkbox').nth(2).check();
        await page.screenshot({ path: '/tmp/kr-fight-result-desktop.png', fullPage: true });
        await save(); assert.equal(draws[1][0].winner_id, 1); assert.equal(draws[1][0].opponent_ippon, true);
        await button('Swap participants').waitFor({ state: 'hidden' });
        await openFight(1);
        await dialog().getByRole('checkbox').nth(1).check();
        assert.equal(await dialog().getByRole('spinbutton').nth(1).inputValue(), '0');
        assert.equal(await dialog().getByRole('spinbutton').nth(1).isDisabled(), true);
        await save(); assert.equal(draws[1][0].absent_student, true);
        await openFight();
        await dialog().getByRole('radio').last().check(); await save();
        assert.equal(draws[1][0].absent_student, false); assert.deepEqual(writes.at(-1).data.absent_ids, []);
        await openFight(); staleNext = true;
        await dialog().getByRole('button', { name: 'Save result', exact: true }).click();
        await dialog().getByRole('alert').getByText('Bracket changed. Refresh it.').waitFor();
        await dialog().getByRole('button', { name: 'Refresh bracket', exact: true }).click();
        await dialog().waitFor({ state: 'hidden' });
        await openFight(); await save();
        for (const theme of ['light', 'dark']) {
            await page.evaluate(value => localStorage.setItem('kr-panel-theme', value), theme);
            await page.setViewportSize({ width: 390, height: 844 }); await page.reload();
            await openFight(); await noOverflow();
            const contrast = await dialog().evaluate(el => {
                const lum = c => c.match(/[\d.]+/g).slice(0, 3).map(Number).map(n => n / 255).map(n => n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4).reduce((v, n, i) => v + n * [.2126, .7152, .0722][i], 0);
                const style = getComputedStyle(el), a = lum(style.color), b = lum(style.backgroundColor); return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
            });
            assert(contrast >= 4.5, `Contrast ${contrast}`);
            await page.screenshot({ path: `/tmp/kr-fight-result-${theme}-mobile.png`, fullPage: true });
            await dialog().getByRole('button', { name: 'Cancel', exact: true }).last().click();
        }
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto(`${base}/panel/tournaments/28/items/57/edit?tab=brackets&list=2`);
        await page.locator('.round-robin-panel').waitFor();
        assert.equal(await button('Swap participants').count(), 0);
        const selects = page.locator('.round-robin-winners select');
        await selects.nth(0).selectOption('1');
        assert.equal(await selects.nth(1).locator('option[value="1"]').count(), 0);
        await selects.nth(1).selectOption('2'); await selects.nth(2).selectOption('3');
        await page.locator('.round-robin-panel').getByRole('button', { name: 'Save', exact: true }).click();
        await page.waitForFunction(() => !document.querySelector('.round-robin-panel .save-button').disabled);
        assert.equal(draws[2][0].winner_id_1rd_robbin, 1);
        await openFight(0, true); await save();
        await selects.nth(0).waitFor(); assert.equal(await selects.nth(0).inputValue(), '');
        await page.screenshot({ path: '/tmp/kr-fight-round-robin-desktop.png', fullPage: true });
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log('PASS: swap, winner-only score/reset, absences/clear, revision conflict recovery, Round Robin podium, desktop/mobile light/dark, no UI errors or overflow');
    } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
