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

async function scenario(name, run) {
  const page = await browser.newPage({ baseURL: base.href, reducedMotion: 'reduce' });
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
    await scenario(name, scenarios[name]);
  }
} finally {
  await browser.close();
}
