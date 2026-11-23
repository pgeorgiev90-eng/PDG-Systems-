const {chromium} = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({
    headless: true,
    ...(process.env.PDG_CHROMIUM ? {executablePath: process.env.PDG_CHROMIUM} : {})
  });
  try {
    for (const width of [1440, 390]) {
      const page = await browser.newPage({viewport: {width, height: 900}});
      const products = [
        {id: 1, name: 'Jinko 585 W', code: 'JK585', brand: 'Jinko', category: 'Панели', active: 1, price: '80.00'},
        {id: 2, name: 'SolaX X3', code: 'SX3', brand: 'SolaX', category: 'Инвертори', active: 0, price: null}
      ];
      const fields = [{label: 'Описание', value: 'Панел 585 W.'}, {label: 'Мощност', value: '585 W'}];
      const writes = [];
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('https://pdg.test/**', async route => {
        const url = new URL(route.request().url());
        const path = url.pathname.slice(1);
        if (path === 'api/account-draft.php') return route.fulfill({json: {csrf: 'test', user: {role: 'admin'}}});
        if (path === 'api/admin-products-draft.php') {
          if (route.request().method() === 'POST') {
            assert.equal(route.request().headers()['x-csrf-token'], 'test');
            writes.push(route.request().postDataJSON());
            return route.fulfill({json: {ok: true, id: 1}});
          }
          if (url.searchParams.has('id')) return route.fulfill({json: {product: products[0], fields, version: 'v1'}});
          return route.fulfill({json: {products}});
        }
        if (fs.existsSync(path) && fs.statSync(path).isFile()) return route.fulfill({path});
        return route.fulfill({status: 404, body: ''});
      });
      await page.goto('https://pdg.test/admin-products-draft.html');
      await page.getByRole('button', {name: 'Jinko 585 W'}).waitFor();
      await page.locator('#filter-brand').selectOption('SolaX');
      assert.equal(await page.locator('#rows tr').count(), 1);
      assert.match(await page.locator('#rows').innerText(), /SolaX/);
      await page.locator('#filter-brand').selectOption('');
      await page.locator('#product-search').fill('JK585');
      await page.getByRole('button', {name: 'Jinko 585 W'}).click();
      await page.getByLabel('Описание', {exact: true}).fill('Обновено описание');
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      await page.getByRole('button', {name: 'Публикувай', exact: true}).click();
      await page.locator('#message').filter({hasText: 'Артикулът е публикуван.'}).waitFor();
      assert.deepEqual(writes[0].fields, [{label: 'Описание', value: 'Обновено описание'}, fields[1]]);
      assert.equal(writes[0].version, 'v1');
      assert.equal(writes[0].active, true);
      await page.getByRole('button', {name: '+ Нов артикул'}).click();
      for (const [name, value] of Object.entries({name: 'Нов панел', code: 'N1', brand: 'PDG', category: 'Панели'})) {
        await page.locator(`#product-form [name="${name}"]`).fill(value);
      }
      await page.getByRole('button', {name: 'Запази скрит'}).click();
      await page.locator('#message').filter({hasText: 'запазен като скрит'}).waitFor();
      assert.equal(writes[1].id, 0);
      assert.equal(writes[1].active, false);
      assert.deepEqual(errors, []);
      await page.close();
    }
  } finally {
    await browser.close();
  }
  console.log('PASS: product catalog search, filters, editing and draft actions on desktop and mobile');
})().catch(error => {console.error(error); process.exitCode = 1;});
