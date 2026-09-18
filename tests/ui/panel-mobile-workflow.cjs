const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage();
    page.setDefaultTimeout(7000);
    let role = 'Organization';
    const errors = [], unexpected = [];
    page.on('pageerror', e => errors.push(e.message));
    const meta = { current_page: 1, last_page: 9, per_page: 10, total: 83, from: 1, to: 2 };
    const people = [1, 2].map(id => ({ id, full_name: 'Константинопольский Александр Александрович', name: 'Константинопольский Александр Александрович', first_name: 'Александр', last_name: 'Константинопольский', age: 12, weight: 43, rang: '5 кю', club: 'Международный клуб карате HAYABUSA', trainer: 'Мартиросян Эдуард', coach_line: 'Международный клуб карате HAYABUSA', email: 'long-address@example.test', avatar: '/assets/auth/kr.jpg', documents_ok: true, pivot_id: id, list_name: 'Мальчики 12–13 лет, кумитэ до 45 кг', memberships: [] }));
    const lists = [{ id: 1, name: 'Мальчики 12–13 лет, кумитэ до 45 кг', list_type: 'kumite', type: 'kumite', age_from: 12, age_to: 13, weight_from: 35, weight_to: 45, rang_from: 8, rang_to: 4, gender: 'm', participants_count: 4, has_generated: true }];
    let templates = [lists[0], { ...lists[0], id: 2, name: 'Девочки 12–13 лет, кумитэ до 45 кг' }];
    const tournament = { id: 57, name: 'Открытое первенство по кумитэ среди юношей и девушек 2026', can_manage: true, can_confirm_weight: true, can_attach_group_students: false, tournament_type: 1, downloads: {}, date: '2026-09-15', date_finish: '2026-09-16', tatami: 2, clubs: ['Международный клуб карате HAYABUSA'], cover: '/assets/auth/kr.jpg', status: 'active', students_count: 83, trainers_count: 18, address: 'Москва, Международный центр единоборств, улица Спортивная, 12' };
    const championship = { id: 28, name: 'Чемпионат России по киокушинкай карате', can_manage: true, cover: '/assets/auth/kr.jpg', export_coaches: people };
    const pool = (id, type, round) => ({ id, student_id: 1, opponent_id: 2, student: people[0], opponent: people[1], type, round, position_in_round: id, winner_id: null });
    // Never reach real data, payments, S3 uploads or mutation endpoints.
    await page.route('**/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const json = value => route.fulfill({ json: value });
        if (path === '/api/auth/user') return json({ user: { id: 50, name: 'Федерация киокушинкай карате', roles: [role], agreements_required: false, capabilities: { team_sections: role === 'Organization' ? ['judges', 'secretaries', 'trainers', 'students', 'pending'] : ['trainers', 'students', 'pending'], view_examinations: role === 'Organization' } } });
        if (path === '/api/panel/team/invitation-code') return json({ code: 'KR-TEST12345678' });
        if (path === '/api/panel/team') return json({ items: { data: people, meta }, stats: { trainers: 18, students: 240, judges: 4, secretaries: 2, pending: 8 } });
        if (path === '/api/panel/template-student-lists/reorder') {
            const { ids } = route.request().postDataJSON();
            templates = ids.map(id => templates.find(item => item.id === id));
            return json({ success: true });
        }
        if (path === '/api/panel/template-student-lists') return json({ items: templates, meta, stats: { total: 83, active_filters: 0, kata: 0 } });
        if (path === '/api/panel/examinations') return json({ items: { data: [{ id: 1, name: 'Аттестационный экзамен по киокушинкай карате', date: '2026-10-01', city: 'Москва', students_count: 40 }], meta }, stats: { total: 10, planned: 5, completed: 5, students: 40 }, filters: { cities: [], coaches: [] } });
        if (path === '/api/panel/examinations/1') return json({ item: { id: 1, name: 'Аттестационный экзамен по киокушинкай карате', date_label: '01.10.2026', city: 'Москва', receiving: 'Константинопольский Александр Александрович', status: 'planned', can_manage: true }, coaches: [] });
        if (path === '/api/panel/examinations/1/students') return json({ items: { data: people, meta } });
        if (path === '/api/panel/settings') return json({ settings: { can_edit_students: true, can_edit_coaches: true } });
        if (path === '/api/panel/rating') return json({ groups: [{ key: 'test', name: 'Юноши 12–13 лет, кумитэ до 45 кг', leader: { ...people[0], rating_points: 150, coach_label: people[0].club }, items: people.map(person => ({ ...person, student_id: person.id, rating_points: 150, coach_label: person.club })) }], organizationOptions: { 1: championship.name }, regionOptions: { 1: 'Московская область' } });
        if (path === '/api/panel/tournaments' || path === '/api/panel/tournaments/28') return json({ championship: path.endsWith('/28') ? championship : null, items: { data: [tournament], meta }, stats: { total: 10, active: 5, completed: 5, this_month: 2 }, filters: { regions: [] }, forms: [], create_options: { regions: [], scales: [] } });
        if (path === '/api/panel/tournaments/28/items/57') return json({ championship: { id: 28, name: 'Чемпионат России по киокушинкай карате' }, tournament, detail: { students: { data: people, meta }, coaches: people, lists, options: { coaches: [], lists: [], students: [] } }, create_options: { regions: [], scales: [] } });
        if (path.endsWith('/brackets/1')) return json({ revision: '0'.repeat(64), can_swap: true, swap_participant_ids: [1, 2], tournament: { ...tournament, fight_for_third_place: true, pools: [pool(1, '1/2', 1), pool(2, '1/2', 1), pool(3, 'final', 2), pool(4, '3rd', 3)] } });
        if (path === '/api/panel/account/dashboard') return json({ trainers: 18, students: 240, pending: 3, unread: 2, upcoming: [{ id: 57, name: tournament.name, championship: 'Чемпионат России', date: tournament.date, path: '/panel/tournaments/28/items/57/edit' }] });
        unexpected.push(path); return route.fulfill({ status: 404, json: {} });
    });
    async function geometry(label) {
        const data = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth, overflow: [...document.querySelectorAll(innerWidth <= 720 ? '.panel-main > *, .responsive-table' : '.panel-main > *')].filter(el => el.getBoundingClientRect().right > innerWidth + 1).map(el => el.className) }));
        assert(data.scroll <= data.width + 1, `${label}: ${JSON.stringify(data)}`);
        assert.deepEqual(data.overflow, [], label);
    }
    try {
        for (const width of [360, 393, 430, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            for (const locale of ['ru', 'en']) {
                role = locale === 'ru' ? 'Organization' : 'Secretary';
                await page.addInitScript(({ locale }) => { localStorage.setItem('kr-locale', locale); localStorage.setItem('kr-panel-theme', locale === 'en' ? 'dark' : 'light'); }, { locale });
                for (const path of ['/panel/dashboard', '/panel/team', '/panel/templates', '/panel/settings', '/panel/rating', '/panel/tournaments', '/panel/tournaments/28', ...(role === 'Organization' ? ['/panel/exams', '/panel/exams/1'] : []), '/panel/tournaments/28/items/57/edit?tab=students', '/panel/tournaments/28/items/57/edit?tab=lists', '/panel/tournaments/28/items/57/edit?tab=brackets&list=1']) {
                    await page.goto(base + path);
                    await page.waitForLoadState('networkidle');
                    await geometry(`${width}/${locale}/${path}`);
                    if (width <= 430) {
                        assert.equal(await page.locator('.panel-mobile-nav').isVisible(), true);
                        for (const table of await page.locator('.responsive-table').all()) assert.equal(await table.evaluate(el => el.scrollWidth <= el.clientWidth + 1), true, path);
                        const clipped = await page.locator('.responsive-table tbody td').evaluateAll(cells => cells.filter(cell => cell.clientHeight > 0 && cell.scrollHeight > cell.clientHeight + 2).map(cell => cell.dataset.mobileLabel));
                        assert.deepEqual(clipped, [], `${path}: clipped cell content`);
                    }
                    if (width === 393) await page.screenshot({ path: `/tmp/kr-web-mobile-${locale}-${path.split('?').at(-1).replace(/[^a-z0-9]/gi, '-')}.png`, fullPage: true });
                    if (width === 360 && path === '/panel/templates') {
                        const expected = templates[1].name;
                        const saved = page.waitForResponse(response => response.url().includes('/template-student-lists/reorder') && response.request().method() === 'POST');
                        await page.locator('.mobile-template-move').nth(1).click();
                        await saved;
                        await page.waitForFunction(name => document.querySelector('.name-cell strong')?.textContent === name, expected);
                    }
                }
                if (width <= 1024) {
                    await page.locator('.panel-mobile-nav button').last().click();
                    await page.getByRole('dialog').waitFor();
                    const menu = page.locator('.panel-mobile-menu');
                    assert.equal(await menu.evaluate(el => el.scrollWidth <= el.clientWidth + 1), true);
                    const fullScreen = async () => {
                        const bounds = await menu.boundingBox();
                        const viewport = page.viewportSize();
                        assert.deepEqual(bounds, { x: 0, y: 0, width: viewport.width, height: viewport.height });
                        assert.equal(await page.evaluate(() => document.documentElement.classList.contains('panel-menu-open')), true);
                        for (const selector of ['header button', '.mobile-menu-logout']) {
                            const button = await menu.locator(selector).boundingBox();
                            assert.ok(button.y >= 0 && button.y + button.height <= viewport.height);
                        }
                    };
                    await fullScreen();
                    if (width === 393) await page.screenshot({ path: `/tmp/kr-fullscreen-menu-${locale}.png` });
                    await page.setViewportSize({ width, height: 360 });
                    await fullScreen();
                    const content = menu.locator('.panel-mobile-menu-content');
                    assert.equal(await content.evaluate(el => el.scrollHeight > el.clientHeight), true);
                    await content.evaluate(el => { el.scrollTop = el.scrollHeight; });
                    await fullScreen();
                    await menu.locator('header button').click();
                    await menu.waitFor({ state: 'hidden' });
                    assert.equal(await page.locator('.panel-mobile-nav button').last().evaluate(el => el === document.activeElement), true);
                    await page.setViewportSize({ width, height: 900 });
                    await page.locator('.panel-mobile-nav button').last().click();
                    await menu.locator('.mobile-menu-logout').focus();
                    await page.keyboard.press('Tab');
                    // Native dialog may pass focus through browser chrome, but not to the inert page.
                    assert.equal(await page.locator('.panel-main, .panel-mobile-nav').evaluateAll(elements => elements.some(el => el.contains(document.activeElement))), false);
                    await page.keyboard.press('Tab');
                    assert.equal(await menu.evaluate(el => el.contains(document.activeElement)), true);
                    await page.keyboard.press('Escape');
                    await menu.waitFor({ state: 'hidden' });
                    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('panel-menu-open')), false);
                    const controls = page.locator('.mobile-bracket-controls');
                    await controls.locator('button').last().click();
                    assert.equal(await controls.locator('select').inputValue(), '1');
                    assert.equal(await page.locator('.bracket-column:visible').count(), 1);
                    await page.locator('.bracket-tree').dispatchEvent('touchstart', { touches: [{ identifier: 1, clientX: 60, clientY: 200 }] });
                    await page.locator('.bracket-tree').dispatchEvent('touchend', { changedTouches: [{ identifier: 1, clientX: 220, clientY: 205 }] });
                    assert.equal(await controls.locator('select').inputValue(), '0');
                    await page.locator('.bracket-column:visible .bracket-participant').first().click();
                    await page.getByRole('dialog').waitFor();
                    await geometry('result modal');
                    assert.equal(await page.getByRole('dialog').evaluate(el => el.scrollWidth <= el.clientWidth + 1), true);
                }
            }
        }
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log('PASS: organizer/secretary, RU/EN, light/dark, 360/393/430/768/1024/1440, records, menus, rounds and result modal');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
