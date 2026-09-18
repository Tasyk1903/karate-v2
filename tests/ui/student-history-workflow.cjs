const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    page.setDefaultTimeout(8000);
    const errors = [], unexpected = [], opened = [], documentUpdates = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => { if (!localStorage.getItem('kr-locale')) localStorage.setItem('kr-locale', 'en'); });
    const own = { id: 57, championship_id: 28, name: 'Own tournament', date: '01.06.2026', type: 'kumite', wins: 1, losses: 0, can_open: true };
    const foreign = { id: 58, championship_id: 29, name: 'Other organization tournament', date: '02.06.2026', type: 'kata', wins: 0, losses: 1, can_open: false };
    const fight = (id, tournament) => ({ id, tournament, opponent: { first_name: 'Ivan', last_name: 'Petrov', full_name: 'Petrov Ivan', age_at_fight: '10', coach_name: 'Coach Ivanov' }, pool: '10-11 Kumite', fight_date: '01.06.2026' });
    const detail = {
        student: { id: 101, first_name: 'Alex', last_name: 'Example', full_name: 'Example Alex', club: 'DOJO', coach_name: 'Coach Ivanov', age: 10, gender: 'm', weight: 30, rang: '5 kyu', birthday: '01.01.2016', belt: { label_key: 'yellowBelt', color: '#facc15', accent: '#22c55e', progress: 50 } },
        rating: { kumite: { label: 'TOP-3', year: '2026', points: 96, medals: { gold: 2, silver: 1, bronze: 0 } }, kata: { label: 'TOP-1', year: '2026', points: 30, medals: { gold: 4, silver: 0, bronze: 1 } }, record: { wins: 1, losses: 1, total: 2 } },
        can_confirm_documents: true,
        documents: {
            documents: ['insurance', 'ikoCard', 'certificate', 'passport', 'brand'].map((key, index) => ({ key, file: index === 4 ? null : '/assets/auth/kr.jpg', confirmed: index % 2 === 0, action_type: index < 3 ? (index === 0 ? 'insurance' : 'check') : 'toggle', check_field: `check_${key}`, form: { [`confirmed_${key}`]: index % 2 === 0 } })),
            rows: [
                [{ label: 'brandNumber', value: 'BRAND-12345' }, { label: 'ikoNumber', value: 'IKO-67890' }, { label: 'certificateNumber', value: 'CERT-1234567890123456789012345678901234567890' }, { label: 'lastExamDate', value: '01.09.2026' }, { label: 'lastExamCity', value: 'Saint Petersburg / Санкт-Петербург' }],
                [{ label: 'lastReceiving', value: 'Константинопольский Александр Александрович' }, null, null, null, null],
                ['insurance', 'ikoCard', 'certificate', 'passport', 'brand'].map(document => ({ document })),
                [{ label: 'insuranceCloseDate', value: '18.09.2027' }, { label: 'includedInDocumentCheck', value: 'yes' }, { label: 'includedInDocumentCheck', value: 'no' }, null, null],
            ],
        }, tournaments: [own, foreign], fight_records: { wins: [fight(1, own)], losses: [fight(2, foreign)] },
    };
    await page.route('**/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const json = data => route.fulfill({ json: data });
        if (path === '/api/auth/user') return json({ user: { id: 1, name: 'Federation', roles: ['Organization'], capabilities: { team_sections: ['students', 'trainers'] }, unread_notifications: 0, agreements_required: false } });
        if (path === '/api/panel/team/students/101/history') {
            const kind=new URL(route.request().url()).searchParams.get('kind');
            const data=kind==='tournaments'?detail.tournaments:detail.fight_records[kind];
            return json({data,meta:{current_page:1,last_page:1,total:data.length}});
        }
        if (path === '/api/panel/team/students/101') return json(detail);
        if (path.startsWith('/api/panel/team/students/101/documents/')) {
            documentUpdates.push({ key: path.split('/').pop(), values: route.request().postDataJSON() });
            return json({ documents: detail.documents });
        }
        if (path === '/api/panel/tournaments/28/items/57') {
            opened.push(path);
            return json({ championship: { id: 28, name: 'Championship' }, tournament: { ...own, tournament_type: 1, can_manage: false, downloads: {} }, detail: { students: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 10 } }, coaches: [], lists: [], options: { coaches: [], lists: [], students: [] } } });
        }
        unexpected.push(path); return route.fulfill({ status: 404, json: {} });
    });
    const profile = `${base}/panel/team/students/101`;
    const tab = name => page.locator('.student-detail-tabs').getByRole('button', { name, exact: true });
    const noForeignNavigation = async () => {
        const row = page.locator('.student-tournaments-card tbody tr').filter({ hasText: foreign.name });
        await row.waitFor();
        assert.equal(await row.getAttribute('role'), null);
        assert.equal(await row.getAttribute('tabindex'), null);
        assert.notEqual(await row.evaluate(el => getComputedStyle(el).cursor), 'pointer');
        await row.click(); assert.equal(page.url(), profile);
    };
    try {
        await page.goto(profile);
        await page.locator('.student-medals-row').first().waitFor();
        assert.deepEqual(await page.locator('.student-rating-card.kumite .student-medals-row b').allTextContents(), ['2', '1', '0']);
        assert.deepEqual(await page.locator('.student-rating-card.kata .student-medals-row b').allTextContents(), ['4', '0', '1']);
        await tab('Tournaments').click(); await noForeignNavigation();
        await page.screenshot({ path: '/tmp/kr-student-history-desktop.png', fullPage: true });
        await page.getByRole('link', { name: /Own tournament/ }).press('Enter');
        await page.waitForURL('**/panel/tournaments/28/items/57'); assert.equal(opened.length, 1);
        await page.goto(profile);
        await page.locator('.record-score button').nth(0).click();
        await page.getByRole('link', { name: /Own tournament/ }).click();
        await page.waitForURL('**/panel/tournaments/28/items/57'); assert.equal(opened.length, 2);
        for (const theme of ['light', 'dark']) {
            await page.evaluate(value => localStorage.setItem('kr-panel-theme', value), theme);
            await page.setViewportSize({ width: 390, height: 844 }); await page.goto(profile);
            await page.locator('.record-score button').nth(1).click(); await noForeignNavigation();
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
            await page.screenshot({ path: `/tmp/kr-student-history-${theme}-mobile.png`, fullPage: true });
        }
        detail.student.full_name = 'Константинопольский Александр Александрович';
        detail.rating.kumite.subtitle = 'Международные соревнования / International championship';
        detail.rating.kata.subtitle = 'Мальчики и девочки 10–11 лет / Boys and girls';
        detail.rating.record = { wins: 1234, losses: 123, total: 1357 };
        const fits = async selector => {
            const failures = await page.locator(selector).evaluateAll(elements => elements.filter(el => el.scrollWidth > el.clientWidth + 1).map(el => el.className));
            assert.deepEqual(failures, [], `Overflow: ${selector}`);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
        };
        for (const locale of ['ru', 'en']) for (const theme of ['light', 'dark']) for (const width of [360, 393, 430, 768, 1440]) {
            await page.evaluate(({ locale, theme }) => { localStorage.setItem('kr-locale', locale); localStorage.setItem('kr-panel-theme', theme); }, { locale, theme });
            await page.setViewportSize({ width, height: 900 });
            await page.goto(profile);
            await page.locator('.student-medals-row').first().waitFor();
            await fits('.student-rating-grid, .student-rating-card, .student-rating-title, .student-medals-row, .student-medals-row span, .student-record-card, .record-score, .record-score button');
            if (width <= 430) {
                const cards = await page.locator('.student-rating-grid > div').evaluateAll(elements => elements.map(el => { const r = el.getBoundingClientRect(); return { left: r.left, top: r.top, bottom: r.bottom }; }));
                assert.equal(cards[0].left, cards[1].left);
                assert.ok(cards[1].top >= cards[0].bottom && cards[2].top >= cards[1].bottom);
            }
            if (width === 393) await page.locator('.student-section-card').screenshot({ path: `/tmp/kr-student-rating-${locale}-${theme}.png` });
            await page.locator('.student-detail-tabs button').nth(1).click();
            assert.equal(await page.locator('.student-document-card').count(), 5);
            for (const [key, value] of [['insurance', '18.09.2027'], ['ikoCard', 'IKO-67890'], ['certificate', 'CERT-'], ['brand', 'BRAND-12345']]) {
                const card = page.locator(`[data-document="${key}"]`);
                assert.ok((await card.innerText()).includes(value));
                assert.equal(await card.locator('.document-status').count(), 1);
            }
            assert.ok(!(await page.locator('[data-document="insurance"]').innerText()).includes('BRAND-'));
            assert.equal(await page.locator('.student-examination-fields dd').count(), 3);
            assert.equal(await page.locator('[data-document="brand"] .student-document-preview').isDisabled(), true);
            await fits('.student-documents-table, .student-document-card, .student-document-fields, .document-status');
            if (width === 393) await page.locator('.student-documents-table').screenshot({ path: `/tmp/kr-student-documents-${locale}-${theme}.png` });
        }
        await page.locator('[data-document="ikoCard"] .student-document-preview').click();
        assert.equal(await page.locator('.document-lightbox h2').innerText(), 'IKO card');
        await page.keyboard.press('Escape');
        await page.locator('[data-document="ikoCard"] .document-status').click();
        assert.equal(await page.locator('.student-document-modal h2').innerText(), 'IKO card');
        await page.locator('.student-document-modal .soft-button').click();
        await Promise.all([
            page.waitForResponse(response => response.url().endsWith('/documents/passport')),
            page.locator('[data-document="passport"] .document-status').click(),
        ]);
        assert.deepEqual(documentUpdates, [{ key: 'passport', values: { confirmed_passport: true } }]);
        detail.can_confirm_documents = false;
        await page.reload(); await page.locator('.student-detail-tabs button').nth(1).click();
        assert.equal(await page.locator('.document-status:disabled').count(), 5);
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log('PASS: medals display, own tournament/history navigation by mouse and keyboard, foreign history read-only, mobile light/dark, no unauthorized requests');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
