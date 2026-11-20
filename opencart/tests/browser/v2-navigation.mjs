import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';

const base = new URL(process.env.CMTEH_BROWSER_BASE);
assert.equal(base.protocol, 'http:');
assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname) && !base.username && !base.password);
const tooling = process.env.CMTEH_BROWSER_TOOLING;
const require = createRequire(tooling ? resolve(tooling, 'package.json') : import.meta.url);
const { chromium, expect } = require('@playwright/test');
const browser = await chromium.launch();
const product = '/product/jd-re568158';

async function scenario(name, run, reducedMotion = 'reduce') {
  const page = await browser.newPage({ baseURL: base.href, reducedMotion });
  try {
    await run(page);
    console.log('PASS ' + name);
  } finally {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await page.close();
  }
}

function holdFetch(page, path) {
  let release;
  const gate = new Promise(resolve => { release = resolve; });
  const started = page.waitForRequest(request => request.resourceType() === 'fetch' && new URL(request.url()).pathname === path);
  const installed = page.route(url => url.pathname === path, async route => {
    if (route.request().resourceType() !== 'fetch') return route.continue();
    await gate;
    await route.continue();
  });
  return { installed, started, release };
}

async function openPartsFromProduct(page) {
  await page.goto(product);
  await page.locator('.v2-list-top').click();
  await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'parts-list');
  await expect(page).toHaveURL(new URL('/parts-list', base).href);
}

const scenarios = {
  'search-example': async page => {
    await page.goto('/');
    await page.getByRole('link', { name: '1R-1808', exact: true }).click();
    await expect(page).toHaveURL(new URL('/product/cat-1r-1808', base).href);
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'product');
    await page.evaluate(() => history.back());
    await expect(page).toHaveURL(base.href);
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'home');
  },
  'brand-return-motion': async page => {
    const drawingGeometry = () => page.evaluate(async () => {
      const stage = document.querySelector('.v2-stage');
      const layer = document.querySelector('.v2-excavator-layer');
      const points = [];
      const end = performance.now() + 650;
      while (performance.now() < end) {
        const stageRect = stage.getBoundingClientRect();
        const layerRect = layer.getBoundingClientRect();
        points.push({ stageY: stageRect.y, drawingY: layerRect.y, drawingHeight: layerRect.height });
        await new Promise(requestAnimationFrame);
      }
      return points;
    });
    const assertStableGeometry = (baseline, samples, size) => {
      for (const key of ['stageY', 'drawingY', 'drawingHeight']) {
        assert.ok(Math.max(...samples.map(point => point[key]), baseline[key])
          - Math.min(...samples.map(point => point[key]), baseline[key]) < 1,
        `${size} ${key} should stay fixed while the page opens or returns`);
      }
    };
    for (const size of [{ width: 390, height: 844 }, { width: 768, height: 883 }]) {
      await page.setViewportSize(size);
      await page.goto('/');
      await page.evaluate(() => document.fonts.ready);
      const baseline = await page.evaluate(() => {
        const stage = document.querySelector('.v2-stage').getBoundingClientRect();
        const drawing = document.querySelector('.v2-excavator-layer').getBoundingClientRect();
        return { stageY: stage.y, drawingY: drawing.y, drawingHeight: drawing.height };
      });
      await page.getByRole('link', { name: '1R-1808', exact: true }).click();
      await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'product');
      assertStableGeometry(baseline, await drawingGeometry(), `${size.width}px product open`);
      await page.locator('.v2-header .brand').click();
      await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'home');
      assertStableGeometry(baseline, await drawingGeometry(), `${size.width}px product return`);
    }
    await page.setViewportSize({ width: 888, height: 883 });
    await page.goto('/');
    await page.evaluate(() => document.fonts.ready);
    const brandLink = page.locator('a[href^="/brand/"]:visible').first();
    await expect(brandLink).toBeVisible();
    const brandUrl = await brandLink.getAttribute('href');
    await brandLink.click();
    await expect(page).toHaveURL(new URL(brandUrl, base).href);
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'brand');
    await page.locator('.v2-header .brand').click();
    await expect(page).toHaveURL(base.href);
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'home');
    const samples = await page.evaluate(async () => {
      const search = document.querySelector('#article-search');
      const drawing = document.querySelector('.v2-excavator');
      const points = [];
      const end = performance.now() + 650;
      while (performance.now() < end) {
        const searchRect = search.getBoundingClientRect();
        const drawingRect = drawing.getBoundingClientRect();
        points.push({ searchY: searchRect.y, drawingY: drawingRect.y, drawingHeight: drawingRect.height });
        await new Promise(requestAnimationFrame);
      }
      return points;
    });
    assert.ok(Math.max(...samples.map(point => point.searchY)) - Math.min(...samples.map(point => point.searchY)) < 1,
      'The desktop search should start in its final position');
    assert.ok(Math.max(...samples.map(point => point.drawingY)) - Math.min(...samples.map(point => point.drawingY)) < 1,
      'Drawing position should stay fixed during the reveal');
    assert.ok(Math.max(...samples.map(point => point.drawingHeight)) - Math.min(...samples.map(point => point.drawingHeight)) < 1,
      'Drawing size should stay fixed during the reveal');
  },
  'mobile-waiting-and-return': async page => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await page.waitForFunction(() => history.state?.v2View);
    const search = page.locator('#article-search');
    const start = await search.boundingBox();
    const firstList = holdFetch(page, '/parts-list');
    await firstList.installed;
    try {
      await page.locator('.v2-quote-link').click();
      await firstList.started;
      await expect(page.getByText('Открываем список или файл…')).toBeVisible();
      assert.ok(Math.abs((await search.boundingBox()).y - start.y) < 1, 'Loading must not move the search field');
      await expect(page.locator('#main-content')).toHaveCSS('opacity', '1');
    } finally {
      firstList.release();
    }
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'parts-list');
    await page.locator('#parts-list-text').fill('RE568158;2');

    const repeats = [];
    page.on('request', request => {
      const path = new URL(request.url()).pathname;
      if (request.resourceType() === 'fetch' && (path === '/' || path === '/parts-list')) repeats.push(path);
    });
    await page.locator('header .brand').click();
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'home');
    await page.locator('.v2-quote-link').click();
    await expect(page.locator('#parts-list-text')).toHaveValue('RE568158;2');
    assert.deepEqual(repeats, [], 'Already visited home and list should not require another request');
  },
  'cart-soft-reveal': async page => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await page.waitForFunction(() => history.state?.v2View);
    await page.evaluate(() => { window.originalSearch = document.querySelector('#article-search'); });
    await page.getByRole('link', { name: '1R-1808', exact: true }).click();
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'product');
    await page.locator('[data-add-article="1R-1808"]').first().click();
    const cartLink = page.locator('.request-link');
    await expect(cartLink.locator('[data-cart-count]')).toHaveText('1');
    await cartLink.click();
    await expect(page).toHaveURL(new URL('/request', base).href);
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'request');
    await expect(page.locator('#article-search input[name=q]')).toHaveValue('1R-1808');
    assert.ok(await page.evaluate(() => window.originalSearch === document.querySelector('#article-search')),
      'The cart should use the original document and search field');
    await page.evaluate(() => { location.hash = 'main-content'; });
    await expect(page).toHaveURL(new URL('/request#main-content', base).href);
    await page.evaluate(() => history.back());
    await expect(page).toHaveURL(new URL('/request', base).href);
    assert.ok(await page.evaluate(() => window.originalSearch === document.querySelector('#article-search')),
      'The cart should keep normal in-page anchors without reloading');

    const form = page.locator('form[data-lead="request"]');
    const name = form.getByLabel('Контактное лицо');
    await name.fill('Проверка корзины');
    await form.getByLabel('Телефон').fill('+70000000000');
    await page.getByRole('button', { name: 'Увеличить количество 1R-1808' }).click();
    await expect(page.locator('.request-line .qty b')).toHaveText('2');
    await page.locator('header .brand').click();
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'home');
    await page.locator('.menu-toggle').click();
    await page.locator('#main-navigation [data-open-quick]').click();
    const quick = page.locator('dialog[data-quick-dialog]');
    await expect(quick).toBeVisible();
    const prices = page.waitForRequest(request => new URL(request.url()).pathname === '/api/product-prices');
    await quick.getByRole('link', { name: 'корзину', exact: true }).click();
    await prices;
    await expect(quick).toBeHidden();
    assert.ok(await page.evaluate(() => window.originalSearch === document.querySelector('#article-search')),
      'The cart link in the quick form should also preserve the document');
    await expect(name).toHaveValue('Проверка корзины');
    await expect(page.locator('.request-line .qty b')).toHaveText('2');

    const posts = [];
    await page.route('**/api/lead', async route => {
      posts.push(route.request().postDataJSON());
      await route.fulfill({ json: { id: 'synthetic-soft-cart', replayed: false } });
    });
    await page.evaluate(() => new Promise(acquired => {
      navigator.locks.request('smtechno-cart-write', () => new Promise(release => {
        window.releaseCart = release;
        acquired();
      }));
    }));
    try {
      await form.getByRole('button', { name: 'Отправить заявку' }).click();
      await page.locator('header .brand').click();
      await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'home');
    } finally {
      await page.evaluate(() => window.releaseCart());
    }
    await cartLink.click();
    await expect(form.getByText(/Заявка принята/)).toBeVisible();
    assert.equal(posts.length, 1, 'Opening the cart repeatedly must not attach duplicate send handlers');
    assert.equal(new URL(posts[0].page_url).pathname, '/request');
    assert.equal(posts[0].request_items[0].quantity, 2);
    await expect(page.locator('[data-cart-empty]')).toBeVisible();
  },
  'contacts-soft-reveal': async page => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await page.waitForFunction(() => history.state?.v2View);
    await page.evaluate(() => { window.originalSearch = document.querySelector('#article-search'); });
    await page.locator('.v2-footer-nav a[href="/contacts"]').click();
    await expect(page).toHaveURL(new URL('/contacts', base).href);
    const form = page.locator('form[data-lead="contact"]');
    const name = form.getByLabel('Контактное лицо');
    await name.fill('Проверка контактов');
    await form.getByLabel('Телефон').fill('+70000000000');
    await page.locator('header .brand').click();
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'home');

    await page.locator('.menu-toggle').click();
    await page.locator('#main-navigation [data-open-quick]').click();
    const dialog = page.locator('dialog[data-quick-dialog]');
    await expect(dialog).toBeVisible();
    await dialog.locator('.quick-fallback').click();
    await expect(dialog).toBeHidden();
    await expect(name).toHaveValue('Проверка контактов');
    assert.ok(await page.evaluate(() => window.originalSearch === document.querySelector('#article-search')),
      'Contacts should keep the same document and search after a dialog link');

    let submitted;
    await page.route('**/api/lead', async route => {
      submitted = route.request().postDataJSON();
      await route.fulfill({ json: { id: 'synthetic-contact', replayed: false } });
    });
    await form.getByRole('button', { name: 'Отправить заявку' }).click();
    await expect(form.getByText(/Заявка принята/)).toBeVisible();
    assert.equal(new URL(submitted.page_url).pathname, '/contacts');
  },
  'article-parts-list-link': async page => {
    await page.goto('/articles/zayavka-po-spisku-artikulov');
    await page.waitForFunction(() => history.state?.v2View);
    await page.evaluate(() => { window.originalSearch = document.querySelector('#article-search'); });
    const link = page.getByRole('link', { name: 'заявку по списку', exact: true });
    await expect(link).toHaveAttribute('href', '/parts-list');
    await link.click();
    await expect(page).toHaveURL(new URL('/parts-list', base).href);
    await expect(page.locator('[data-parts-list]')).toBeVisible();
    assert.ok(await page.evaluate(() => window.originalSearch === document.querySelector('#article-search')));
  },
  'catalog-forms-soft-reveal': async page => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/catalog?q=1R-1808');
    await page.waitForFunction(() => history.state?.v2View);
    await page.evaluate(() => { window.originalSearch = document.querySelector('#article-search'); });
    await page.getByLabel('Сортировка').selectOption('price_desc');
    await page.getByRole('button', { name: 'Применить', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/catalog'
      && url.searchParams.get('q') === '1R-1808' && url.searchParams.get('sort') === 'price_desc');
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'catalog');
    await page.locator('.filter-disclosure > summary').click();
    await page.locator('.facet-form select[name=availability]').selectOption('in_stock');
    await page.getByRole('button', { name: 'Применить фильтры' }).click();
    await expect(page).toHaveURL(url => url.pathname === '/catalog'
      && url.searchParams.get('q') === '1R-1808' && url.searchParams.get('sort') === 'price_desc'
      && url.searchParams.get('availability') === 'in_stock');
    assert.ok(await page.evaluate(() => window.originalSearch === document.querySelector('#article-search')),
      'Sorting and applying filters should keep the original document');
  },
  'history-error': async page => {
    await openPartsFromProduct(page);
    const text = page.locator('#parts-list-text');
    await text.fill('RE568158;3');
    await page.route(url => url.pathname === product, route => route.request().resourceType() === 'fetch'
      ? route.abort('failed') : route.continue());
    await page.evaluate(() => history.back());
    await expect(page.getByText('Не удалось открыть страницу.')).toBeVisible();
    await expect(page).toHaveURL(new URL(product, base).href);
    await expect(text).toHaveValue('RE568158;3');
    await page.unrouteAll();
    await page.getByRole('link', { name: 'Попробовать ещё раз' }).click();
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'product');
    await page.evaluate(() => history.forward());
    await expect(page).toHaveURL(new URL('/parts-list', base).href);
    await expect(text).toHaveValue('RE568158;3');
  },
  'history-input': async page => {
    await openPartsFromProduct(page);
    const request = holdFetch(page, product);
    await request.installed;
    await page.evaluate(() => history.back());
    await request.started;
    await page.locator('#article-search input[name=q]').fill('RE568158');
    request.release();
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'product');
    await expect(page).toHaveURL(new URL(product, base).href);
  },
  'fragment-history': async page => {
    await page.goto(product);
    await page.evaluate(() => { location.hash = 'main-content'; });
    await expect(page).toHaveURL(new URL(product + '#main-content', base).href);
    await page.evaluate(() => history.back());
    await expect(page).toHaveURL(new URL(product, base).href);
    await expect(page.locator('#main-content')).not.toHaveAttribute('aria-busy', 'true');
    const request = holdFetch(page, '/parts-list');
    await request.installed;
    await page.locator('.v2-list-top').click();
    await request.started;
    await page.evaluate(() => history.forward());
    await expect(page).toHaveURL(new URL(product + '#main-content', base).href);
    // The canceled request is still held until the browser reaches the fragment history entry.
    await expect(page.locator('#main-content')).not.toHaveAttribute('aria-busy', 'true');
    request.release();
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'product');
    await expect(page).toHaveURL(new URL(product + '#main-content', base).href);
  },
  'parts-prefill': async page => {
    await page.goto('/catalog?article=RE568158');
    await page.locator('.v2-list-top').click();
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'parts-list');
    await expect(page.locator('#parts-list-text')).toHaveValue('');
    await page.goto('/parts-list?article=RE568158');
    await expect(page.locator('#parts-list-text')).toHaveValue('RE568158');
  },
  'parts-article-handoff': async page => {
    await page.setViewportSize({ width: 390, height: 844 });
    const article = 'ABSENT-V2-000';
    await page.goto('/catalog?q=' + article);
    await page.waitForFunction(() => history.state?.v2View);
    await page.evaluate(() => { window.originalSearch = document.querySelector('#article-search'); });
    const transfer = page.getByRole('link', { name: 'Передать артикул для подбора' });
    await transfer.click();
    await expect(page).toHaveURL(new URL('/parts-list?article=' + article, base).href);
    const list = page.locator('#parts-list-text');
    await expect(list).toHaveValue(article);
    assert.ok(await page.evaluate(() => window.originalSearch === document.querySelector('#article-search')),
      'Passing an article from empty results should keep the document');

    await page.locator('header .brand').click();
    await page.locator('.v2-quote-link').click();
    await expect(list).toHaveValue('');
    await list.fill('RE568158;2');
    await page.evaluate(() => history.go(-3));
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'catalog');
    await transfer.click();
    await expect(list).toHaveValue('RE568158;2');
    assert.ok(await page.evaluate(() => window.originalSearch === document.querySelector('#article-search')),
      'Prefilled links must share the same working form and preserve the draft');
  },
  'parts-results-viewport': async page => {
    const height = 664;
    await page.setViewportSize({ width: 390, height });
    for (const seamless of [false, true]) {
      await page.goto(seamless ? '/' : '/parts-list');
      if (seamless) {
        await page.getByRole('link', { name: 'Проценить список или файл' }).click();
        await expect(page).toHaveURL(new URL('/parts-list', base).href);
        const documentUrl = await page.evaluate(() => performance.getEntriesByType('navigation')[0].name);
        assert.equal(new URL(documentUrl).pathname, '/');
      }
      await page.locator('#parts-list-text').fill('RE568158;2\n0000550380;3');
      await page.locator('[data-check-list]').click();
      const heading = page.locator('#list-results-title');
      const action = page.locator('[data-result-row]').first().getByRole('button', { name: 'В корзину' });
      await expect(action).toBeVisible();
      for (const locator of [heading, action]) {
        const bounds = await locator.boundingBox();
        assert.ok(bounds && bounds.y >= 0 && bounds.y + bounds.height <= height, 'List result must fit in the viewport: ' + JSON.stringify(bounds));
      }
    }
  },
  'cached-filters': async page => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    const input = page.locator('#article-search input[name=q]');
    await input.press('Enter');
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'catalog');
    const filter = page.locator('.filter-disclosure');
    await filter.locator(':scope > summary').click();
    await expect(filter).toHaveJSProperty('open', true);
    await page.getByRole('link', { name: 'Заявка по списку' }).click();
    await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'parts-list');
    await page.evaluate(() => history.back());
    await expect(filter).toHaveJSProperty('open', true);
  },
  'quick-draft': async page => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    const open = async () => {
      const menu = page.locator('.menu-toggle');
      if (await menu.getAttribute('aria-expanded') !== 'true') await menu.click();
      await page.getByRole('link', { name: 'Быстрый запрос', exact: true }).click();
    };
    const form = page.locator('[data-lead=quick]');
    const name = form.getByLabel('Контактное лицо');
    await open();
    await name.fill('Черновик главной');
    await page.locator('[data-close-quick]').click();
    const search = page.locator('#article-search input[name=q]');
    await search.fill('RE568158');
    await search.press('Enter');
    await expect(page).toHaveURL(new URL(product, base).href);
    await open();
    await expect(name).toHaveValue('');
    await name.fill('Черновик товара');
    await form.getByLabel('Телефон').fill('+70000000000');

    let releaseReply;
    const reply = new Promise(resolve => { releaseReply = resolve; });
    await page.route('**/api/lead', async route => {
      await reply;
      await route.fulfill({ json: { id: 'synthetic-quick-draft', replayed: false } });
    });
    const posted = page.waitForRequest('**/api/lead', { timeout: 5000 });
    await form.getByRole('button', { name: 'Отправить запрос' }).click();
    try {
      assert.equal(new URL((await posted).postDataJSON().page_url).pathname, product);
      await page.evaluate(() => history.back());
      await expect(page.locator('body')).toHaveAttribute('data-v2-page', 'home');
      await expect(name).toHaveValue('Черновик главной');
    } finally {
      releaseReply();
    }
    await expect(form).toHaveAttribute('aria-busy', 'false');
    await expect(name).toHaveValue('Черновик главной');
    assert.equal(await page.evaluate(path => sessionStorage.getItem('smtechno-form-draft:quick:' + path), product), null);
  },
};

try {
  for (const name of process.argv.slice(2).length ? process.argv.slice(2) : Object.keys(scenarios)) {
    assert.ok(scenarios[name], `Unknown scenario ${name}`);
    await scenario(name, scenarios[name], name === 'brand-return-motion' ? 'no-preference' : 'reduce');
  }
} finally {
  await browser.close();
}
