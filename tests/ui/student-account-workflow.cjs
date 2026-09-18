const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const unexpected = [], errors = [];
    try {
        for (const [locale, theme] of [['ru', 'light'], ['en', 'dark']]) {
            const page = await browser.newPage({ viewport: { width: 393, height: 852 } });
            page.setDefaultTimeout(8000);
            await page.addInitScript(({ locale, theme }) => {
                localStorage.setItem('kr-locale', locale); localStorage.setItem('kr-panel-theme', theme);
            }, { locale, theme });
            page.on('pageerror', error => errors.push(error.message));
            let failSave = true, saves = 0, enrolled = false, examEnrolled = false, failDetach = true;
            const documents = ['passport', 'brand', 'insurance', 'iko_card', 'certificate'];
            const detail = {
                student: { id: 101, first_name: 'Александр', last_name: 'Константинопольский', full_name: 'Константинопольский Александр', club: 'Очень длинное название клуба Karate Rating', coach_name: 'Мартиросян Эдуард', age: 11, age_label: '11', weight: 35, height: 140, gender: 'm', birthday: '02.01.2015', rang: '5 кю', belt: { color: '#facc15', accent: '#22c55e', label_key: 'yellowBelt' } },
                profile: { first_name: 'Александр', last_name: 'Константинопольский', patronymic: 'Александрович', email: 'student@example.test', gender: 'm', birthday: '2015-01-02', weight: 35, height: 140, rang: '5 кю', city_training: 'Москва', number_brand: '123', number_iko: '123', number_certificate: '123', last_examination_date: '2025-01-01', last_examination_city: 'Москва', last_receiving: 'Принимающий экзамен' },
                capabilities: { edit: true, birthday: false, rang: false, weight: true, documents: true, delete_account: true },
                can_confirm_documents: false,
                documents: { documents: documents.map(field => ({ key: field === 'iko_card' ? 'ikoCard' : field, confirmed: true, file: null })), rows: [documents.map(field => ({ document: field === 'iko_card' ? 'ikoCard' : field }))] },
                document_status: { items: Object.fromEntries(documents.map(field => [field, { field, key: field === 'iko_card' ? 'ikoCard' : field, ok: true, issue_key: null }])) },
                rating: { kumite: { year: '2026', medals: { gold: 2, silver: 1, bronze: 0 } }, kata: { year: '2026', medals: { gold: 1, silver: 0, bronze: 1 } }, record: { wins: 4, losses: 2, total: 6 } },
                tournaments: [], fight_records: { wins: [], losses: [] },
            };
            await page.route('**/api/**', async route => {
                const url = new URL(route.request().url()), path = url.pathname;
                const json = data => route.fulfill({ json: data });
                if (path === '/api/auth/user') return json({ user: { id: 101, name: detail.student.full_name, roles: ['Student'], capabilities: { view_examinations: true }, agreements_required: false, unread_notifications: 0 } });
                if (path === '/api/panel/student/profile') {
                    if (route.request().method() === 'POST') {
                        assert.match(route.request().postData(), /Александрович/);
                        saves++;
                        if (failSave) return route.fulfill({ status: 422, json: { errors: { email: ['Test validation error'] } } });
                    }
                    return json(detail);
                }
                if (path === '/api/panel/team/students/101/history') return json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
                if (path === '/api/panel/examinations/1') return json({ item: { id: 1, name: 'Kyu exam', date_label: '12.09.2026', city: 'Warsaw', receiving: 'Master', status: 'planned', students_count: examEnrolled ? 2 : 1, can_attach_self: !examEnrolled, can_export: false }, coaches: [] });
                if (path === '/api/panel/examinations/1/students') return json({ items: { data: [{ id: 102, full_name: 'Other student', can_detach: false }, ...(examEnrolled ? [{ id: 101, full_name: detail.student.full_name, can_detach: true }] : [])], meta: { current_page: 1, last_page: 1, per_page: 10, total: examEnrolled ? 2 : 1 } } });
                if (path === '/api/panel/examinations/1/attach-self') { examEnrolled = true; return json({ attached: [101] }); }
                if (path === '/api/panel/examinations/1/students/101') {
                    if (failDetach) return route.fulfill({ status: 503, json: { message: 'Temporary error' } });
                    examEnrolled = false; return json({ detached: true });
                }
                if (path === '/api/panel/tournaments/28/items/57/self' || path === '/api/panel/tournaments/28/items/57/self/1') {
                    assert.equal(JSON.parse(route.request().postData()).confirmed, true);
                    enrolled = route.request().method() === 'POST';
                    return json({ ok: true });
                }
                if (path === '/api/panel/tournaments/28/items/57') return json({ championship: { id: 28, name: 'Championship' }, tournament: { id: 57, championship_id: 28, name: 'Tournament', tournament_type: 1, can_manage: false, downloads: {}, documents: [], self_enrollment: { can_attach: !enrolled, memberships: enrolled ? [{ id: 1, name: '11 years Kata', can_detach: true }] : [] } }, detail: { students: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 10 } }, coaches: [], lists: [], options: { coaches: [], lists: [], students: [] } } });
                if (path === '/api/panel/student/education') return json({ data: ['kata_attestation', 'kihon', 'ido_geiko', 'competition'] });
                if (path === '/api/panel/student/education/catalog/kata_attestation') return json({ data: [{ id: 1, name: 'Тайкёку соно ичи' }], current_page: 1, last_page: 2 });
                if (path === '/api/panel/student/education/catalog/kata_attestation/1') return json({ data: [{ id: 2, title: 'Тайкёку соно ичи', video_url: `${base}/api/panel/student/education/files/kata_attestation/2/video` }], current_page: 1, last_page: 1 });
                if (path.startsWith('/api/panel/student/education/files/')) return route.fulfill({ status: 200, contentType: 'video/mp4', body: '' });
                unexpected.push(path); return route.fulfill({ status: 404, json: {} });
            });
            await page.goto(`${base}/panel`);
            await page.waitForURL('**/panel/profile');
            await page.locator('.student-account-actions button').click();
            const patronymic = page.getByLabel(locale === 'ru' ? 'Отчество' : 'Patronymic', { exact: true });
            assert.equal(await patronymic.inputValue(), 'Александрович');
            assert.equal(await page.locator('input[type=date]').first().isDisabled(), true);
            for (const width of [360, 393, 430, 1440]) {
                await page.setViewportSize({ width, height: 900 });
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `editor overflow ${width}/${locale}`);
            }
            await page.locator('.student-account-editor button[type=submit]').click();
            await page.getByRole('alert').filter({ hasText: 'Test validation error' }).waitFor();
            assert.equal(await patronymic.inputValue(), 'Александрович');
            failSave = false;
            await page.locator('.student-account-editor button[type=submit]').click();
            await page.locator('.student-account-actions [role=status]').waitFor();
            assert.equal(saves, 2);
            const tab = locale === 'ru' ? 'Документы' : 'Documents';
            await page.locator('.student-detail-tabs').getByRole('button', { name: tab, exact: true }).click();
            assert.equal(await page.locator('.document-status:enabled').count(), 0);
            assert.equal(await page.locator('.own-document strong').count(), 5);
            for (const name of await page.locator('.own-document strong').allTextContents()) assert.ok(name.trim());
            await page.setViewportSize({ width: 393, height: 852 });
            await page.screenshot({ path: `/tmp/kr-student-account-${locale}.png`, fullPage: true });
            await page.goto(`${base}/panel/education`);
            await page.locator('.education-categories button').click();
            await page.locator('.education-videos button').click();
            assert.equal(await page.locator('video').getAttribute('src'), `${base}/api/panel/student/education/files/kata_attestation/2/video`);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
            await page.locator('.education-player header button').click();
            await page.screenshot({ path: `/tmp/kr-student-education-${locale}.png`, fullPage: true });
            await page.goto(`${base}/panel/tournaments/28/items/57`);
            await page.locator('.tournament-detail-tabs button').nth(1).click();
            const enroll = locale === 'ru' ? 'Записаться' : 'Enroll';
            const withdraw = locale === 'ru' ? 'Отменить участие' : 'Withdraw';
            await page.locator('.tournament-toolbar-actions').getByRole('button', { name: enroll, exact: true }).click();
            await page.getByRole('dialog').getByRole('button', { name: enroll, exact: true }).click();
            await page.locator('.tournament-toolbar-actions').getByRole('button', { name: withdraw, exact: true }).waitFor();
            assert.equal(enrolled, true);
            await page.locator('.tournament-toolbar-actions').getByRole('button', { name: withdraw, exact: true }).click();
            await page.getByRole('dialog').getByRole('button', { name: withdraw, exact: true }).click();
            await page.locator('.tournament-toolbar-actions').getByRole('button', { name: enroll, exact: true }).waitFor();
            assert.equal(enrolled, false);
            await page.goto(`${base}/panel/exams/1`);
            await page.getByRole('button', { name: enroll, exact: true }).click();
            await page.getByRole('dialog').getByRole('button', { name: enroll, exact: true }).click();
            await page.locator('.detach-button').waitFor();
            assert.equal(examEnrolled, true);
            assert.equal(await page.locator('.detach-button').count(), 1);
            await page.locator('.detach-button').click();
            await page.getByRole('dialog').locator('button[type=submit]').click();
            await page.getByRole('dialog').getByRole('alert').filter({ hasText: 'Temporary error' }).waitFor();
            assert.equal(examEnrolled, true);
            failDetach = false;
            await page.getByRole('dialog').locator('button[type=submit]').click();
            await page.getByRole('button', { name: enroll, exact: true }).waitFor();
            assert.equal(examEnrolled, false);
            await page.close();
        }
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log('PASS: student profile/locks/files labels, save retry, education player, tournament enrollment/withdrawal, exam enrollment/withdrawal error/retry, RU/EN light/dark and responsive widths');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
