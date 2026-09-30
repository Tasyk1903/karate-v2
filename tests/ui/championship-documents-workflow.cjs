const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  let rows = [{ id: 1, name: 'Положение международного чемпионата по киокушинкай', extension: 'pdf' }];
  const championship = { id: 28, name: 'Чемпионат', can_manage: true, cover: '/assets/auth/kr.jpg' };
  const meta = { current_page: 1, last_page: 1, total: 0, per_page: 10 };
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname;
    const method = route.request().method();
    if (path === '/api/auth/user') return route.fulfill({ json: { user: { id: 4, name: 'Organization', roles: ['Organization'], agreements_required: false } } });
    if (path === '/api/panel/tournaments/28') return route.fulfill({ json: { championship, items: { data: [], meta }, stats: {}, filters: { regions: [] }, forms: [] } });
    if (path === '/api/panel/tournaments/28/documents' && method === 'GET') return route.fulfill({ json: { data: rows, meta: { ...meta, total: rows.length }, can_manage: true } });
    if (path.startsWith('/api/panel/tournaments/28/documents') && method === 'POST') {
      const body = route.request().postData();
      assert.ok(body.includes('name="name"'));
      if (path.endsWith('/1')) rows[0].name = 'Новое название';
      else { assert.ok(body.includes('filename="rules.pdf"')); rows.push({ id: 2, name: 'Дополнительный документ', extension: 'pdf' }); }
      return route.fulfill({ json: { item: rows.at(-1) } });
    }
    if (path.endsWith('/documents/2') && method === 'DELETE') { rows = rows.filter(r => r.id !== 2); return route.fulfill({ json: { deleted: true } }); }
    return route.fulfill({ status: 404, json: {} });
  });
  try {
    for (const locale of ['ru', 'en']) for (const theme of ['light', 'dark']) for (const width of [393, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(base);
      await page.evaluate(({ locale, theme }) => { localStorage.setItem('kr-locale', locale); localStorage.setItem('kr-panel-theme', theme); }, { locale, theme });
      await page.goto(`${base}/panel/tournaments/28`);
      await page.locator('.championship-detail-tabs button').last().click();
      await page.locator('.championship-documents li').first().waitFor();
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
      await page.screenshot({ path: `/tmp/kr-documents-${locale}-${theme}-${width}.png`, fullPage: true });
    }
    await page.goto(base);
    await page.evaluate(() => localStorage.setItem('kr-locale', 'ru'));
    await page.goto(`${base}/panel/tournaments/28`);
    await page.locator('.championship-detail-tabs button').last().click();
    await page.getByRole('button', { name: 'Добавить документ', exact: true }).click();
    await page.getByLabel('Название документа').fill('Дополнительный документ');
    await page.locator('input[type=file]').setInputFiles({ name: 'rules.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF test') });
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await page.locator('.championship-documents li').nth(1).waitFor();
    await page.locator('.championship-documents li').first().getByRole('button', { name: 'Редактировать', exact: true }).click();
    await page.getByLabel('Название документа').fill('Новое название');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await page.getByText('Новое название', { exact: true }).waitFor();
    await page.locator('.championship-documents li').nth(1).getByRole('button', { name: 'Удалить', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Удалить', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('.championship-documents li').length === 1);
    assert.deepEqual(errors, []);
    console.log('PASS: championship documents upload/rename/delete, RU/EN light/dark, mobile/desktop; mocked API');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
