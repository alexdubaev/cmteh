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
const context = await browser.newContext({ baseURL: base.href });
const read = page => page.evaluate(() => JSON.parse(localStorage.getItem('smtechno-request') || '[]')
  .map(({ article, quantity }) => ({ article, quantity })));

async function holdCart(page) {
  await page.evaluate(() => new Promise(acquired => {
    navigator.locks.request('smtechno-cart-write', () => new Promise(release => {
      window.releaseCart = release;
      acquired();
    }));
  }));
  return () => page.evaluate(() => window.releaseCart());
}

try {
  const first = await context.newPage();
  const second = await context.newPage();
  const product = '/product/jd-re568158';
  await Promise.all([first.goto(product), second.goto(product)]);

  const releaseAdditions = await holdCart(first);
  try {
    await Promise.all([first, second].map(async page => {
      await page.locator('[data-add-article="RE568158"]').first().click();
    }));
    assert.deepEqual(await read(first), [], 'Each tab must queue its action behind the open cart write.');
  } finally {
    await releaseAdditions();
  }
  await expect.poll(() => read(first)).toEqual([{ article: 'RE568158', quantity: 2 }]);
  console.log('PASS simultaneous additions from two tabs preserve both quantities');

  await first.evaluate(() => localStorage.setItem('smtechno-warehouse-request-v1', '{unreadable'));
  await first.reload();
  await first.locator('[data-add-article="RE568158"]').first().click();
  await expect.poll(() => read(second)).toEqual([{ article: 'RE568158', quantity: 3 }]);
  console.log('PASS an unreadable warehouse cart does not discard or disable persistence for the valid cart');

  await first.goto('/request');
  const form = first.locator('form[data-lead="request"]');
  await form.getByLabel('Контактное лицо').fill('Проверка количества');
  await form.getByLabel('Телефон').fill('+70000000000');
  await first.route('**/api/lead', route => route.fulfill({ json: { id: 'synthetic-cart', replayed: false } }));
  const sent = first.waitForRequest(request => new URL(request.url()).pathname === '/api/lead' && request.method() === 'POST');
  const releaseQuantity = await holdCart(second);
  try {
    await first.getByRole('button', { name: 'Увеличить количество RE568158' }).click();
    await form.getByRole('button', { name: 'Отправить заявку' }).click();
  } finally {
    await releaseQuantity();
  }
  assert.equal((await sent).postDataJSON().request_items[0].quantity, 4,
    'A submission after a queued increment must send the new quantity.');
  console.log('PASS RFQ sends the cart after its queued quantity update');
} finally {
  await browser.close();
}
