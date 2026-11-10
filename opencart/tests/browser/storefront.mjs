import assert from "node:assert/strict";
import { createRequire } from "node:module";
import { resolve } from "node:path";

const base = new URL(process.env.CMTEH_BROWSER_BASE || "http://127.0.0.1:3180");
assert.ok(base.protocol === "http:" && ["localhost", "127.0.0.1", "[::1]"].includes(base.hostname) && !base.username && !base.password,
  "Run browser scenarios only on the isolated local HTTP site.");
const tooling = process.env.CMTEH_BROWSER_TOOLING;
const require = tooling ? createRequire(resolve(tooling, "package.json")) : createRequire(import.meta.url);
const { chromium } = require("playwright");
const browser = await chromium.launch();
const sku = "RE568158";
const phone = "+7 999 000-00-00";
const storage = (page) => page.evaluate(() => JSON.parse(localStorage.getItem("smtechno-request") || "[]"));
async function scenario(title, test) {
  const context = await browser.newContext({ baseURL: base.href, viewport: { width: 390, height: 844 } });
  try {
    await test(await context.newPage());
    console.log("PASS " + title);
  } finally {
    await context.close();
  }
}
async function fill(page, name) {
  const form = page.locator('form[data-lead="request"]');
  await form.getByLabel("Контактное лицо").fill(name);
  await form.getByLabel("Телефон").fill(phone);
}
async function fullyInView(page, locator, label) {
  const bounds = await locator.boundingBox();
  const bottom = await page.locator('.mobile-actions').evaluate((node) => getComputedStyle(node).display === 'none' ? innerHeight : node.getBoundingClientRect().top);
  assert.ok(bounds && bounds.y >= 0 && bounds.y + bounds.height <= bottom, label + ' must be visible above fixed actions: ' + JSON.stringify({ bounds, bottom }));
}
try {
  await scenario("Телефон: ввод и проверка сразу → реальное предложение без поиска ниже", async (page) => {
    await page.setViewportSize({ width: 390, height: 664 });
    await page.goto('/parts-list');
    const input = page.getByLabel('Артикул и количество, по одной позиции в строке');
    await page.locator('[data-check-list]:enabled').waitFor();
    await fullyInView(page, input, 'List input');
    await fullyInView(page, page.locator('[data-check-list]'), 'Check action');
    await input.fill(sku + ';2');
    await page.locator('[data-check-list]').click();
    await page.locator('[data-result-row]').first().waitFor();
    await fullyInView(page, page.locator('#list-results-title'), 'Result heading');
    await fullyInView(page, page.locator('[data-result-row]').first().getByRole('button', { name: 'В корзину' }), 'First offer action');
    assert.deepEqual(await storage(page), []);
    assert.equal(await input.inputValue(), sku + ';2');
  });
  await scenario("Список 1000 строк → одна пакетная проверка → явный выбор товара", async (page) => {
    const calls = [];
    await page.route("**/api/list-check", async (route) => { calls.push(route.request().postDataJSON()); await route.continue(); });
    const response = await page.goto("/parts-list");
    assert.equal(response.status(), 200);
    const input = page.getByLabel("Артикул и количество, по одной позиции в строке");
    await input.fill([sku + " 3", "0000550380;2", "UNKNOWN-TEST;4", ...Array(997).fill(sku + " 1")].join("\n"));
    assert.deepEqual(await storage(page), []);
    await page.getByRole("button", { name: "Проверить наличие", exact: true }).click();
    await page.getByText("Проверено строк: 1000", { exact: true }).waitFor();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].rows.length, 1000);
    assert.equal(calls[0].rows[1].article, "0000550380");
    assert.equal(await page.locator('[data-result-row]').count(), 50);
    await page.getByText("В каталоге не найдено", { exact: true }).waitFor();
    assert.deepEqual(await storage(page), []);
    await page.locator('[data-result-row]').first().getByRole('button', { name: 'В корзину' }).click();
    assert.deepEqual((await storage(page)).map(({ article, quantity, productId }) => ({ article, quantity, productId })),
      [{ article: sku, quantity: 3, productId: "mock-11" }]);
    await input.fill(Array(1001).fill(sku).join("\n"));
    await page.getByRole("button", { name: "Проверить наличие", exact: true }).click();
    await page.locator('[data-list-message]').getByText(/не более 1000/).waitFor();
    assert.equal(calls.length, 1);
    await page.goto('/request#import');
    assert.equal(await page.getByRole('link', { name: 'Проверить список или отправить на расценку' }).getAttribute('href'), '/parts-list');
  });

  await scenario("Расценка текста: email, потерянный ответ, повтор; файл CP1251 сохраняет оригинал", async (page) => {
    const payloads = [];
    let id;
    await page.route('**/api/quote', async (route) => {
      const payload = route.request().postDataJSON(); payloads.push(payload);
      const response = await route.fetch();
      assert.equal(response.status(), 200);
      const body = await response.json();
      if (payloads.length === 1) { id = body.id; await route.abort(); }
      else { if (payloads.length === 2) { assert.equal(body.id, id); assert.equal(body.replayed, true); } await route.fulfill({ response }); }
    });
    await page.goto('/parts-list');
    await page.getByLabel('Артикул и количество, по одной позиции в строке').fill('0000550380;2\nUNKNOWN;3');
    await page.locator('#quote-details > summary').click();
    const form = page.locator('[data-quote-form]');
    await form.getByLabel('Контактное лицо').fill('Тест Расценки');
    await form.getByLabel('Email').fill('quote@example.invalid');
    await form.getByRole('button', { name: 'Отправить на расценку' }).click();
    await page.locator('[data-quote-message][role=alert]').waitFor();
    await page.reload();
    assert.equal(await form.getByLabel('Email').inputValue(), 'quote@example.invalid');
    await form.getByRole('button', { name: 'Отправить на расценку' }).click();
    await page.locator('[data-quote-message]').getByText(/Расценка принята/).waitFor();
    assert.deepEqual(payloads[0], payloads[1]);
    assert.equal(payloads[0].input_kind, 'text');
    assert.deepEqual(await storage(page), []);
    const bytes = Buffer.from([0xc0,0xf0,0xf2,0xe8,0xea,0xf3,0xeb,0x3b,0xca,0xee,0xeb,0x0a,0x30,0x30,0x30,0x30,0x35,0x35,0x30,0x33,0x38,0x30,0x3b,0x32]);
    await page.getByLabel('Файл списка').setInputFiles({ name: 'список.csv', mimeType: 'text/csv', buffer: bytes });
    await page.getByRole('button', { name: 'Проверить наличие', exact: true }).click();
    await page.getByText('Проверено строк: 1', { exact: true }).waitFor();
    await form.getByLabel('Контактное лицо').fill('Тест Файла');
    await form.getByLabel('Email').fill('file@example.invalid');
    await form.getByRole('button', { name: 'Отправить на расценку' }).click();
    await page.locator('[data-quote-message]').getByText(/Расценка принята/).waitFor();
    assert.equal(payloads[2].file.data_base64, bytes.toString('base64'));
    assert.deepEqual(await storage(page), []);
    assert.ok(!(await page.evaluate(() => JSON.stringify({ ...sessionStorage, ...localStorage }))).includes(bytes.toString('base64')));
  });

  await scenario("Denied session writes and stale readable pending data keep one quote after a lost acknowledgement", async (page) => {
    const payloads = [];
    const replies = [];
    await page.addInitScript(() => {
      sessionStorage.setItem('smtechno-quote-pending', JSON.stringify({ fingerprint: 'stale', key: 'be884ad8-8dfe-4c79-b759-39ea816e1076' }));
      const set = Storage.prototype.setItem;
      const remove = Storage.prototype.removeItem;
      Storage.prototype.setItem = function (key, value) {
        if (this === sessionStorage) throw new DOMException('Read-only storage', 'QuotaExceededError');
        return set.call(this, key, value);
      };
      Storage.prototype.removeItem = function (key) {
        if (this === sessionStorage) throw new DOMException('Read-only storage', 'SecurityError');
        return remove.call(this, key);
      };
    });
    await page.route('**/api/quote', async (route) => {
      payloads.push(route.request().postDataJSON());
      const response = await route.fetch();
      replies.push({ status: response.status(), ...await response.json() });
      if (payloads.length === 1) await route.abort();
      else await route.fulfill({ response });
    });
    await page.goto('/parts-list');
    await page.getByLabel('Артикул и количество, по одной позиции в строке').fill('RE568158;7');
    await page.locator('#quote-details > summary').click();
    const form = page.locator('[data-quote-form]');
    await form.getByLabel('Контактное лицо').fill('Read-only Расценка');
    await form.getByLabel('Email').fill('readonly@example.invalid');
    await form.getByRole('button', { name: 'Отправить на расценку' }).click();
    await page.locator('[data-quote-message][role=alert]').waitFor();
    await form.getByRole('button', { name: 'Отправить на расценку' }).click();
    await page.locator('[data-quote-message]').getByText(/Расценка принята/).waitFor();
    assert.deepEqual(payloads[1], payloads[0], 'Same-tab retry must retain its UUID when writes fail');
    assert.deepEqual(replies.map(({ status, id, replayed }) => [status, id, replayed]),
      [[200, replies[0].id, false], [200, replies[0].id, true]]);
    assert.equal(await page.evaluate(() => JSON.parse(sessionStorage.getItem('smtechno-quote-pending')).fingerprint), 'stale');
  });

  await scenario("XLSX reader retries without losing original; PDF is quote-only; full cart reports overflow", async (page) => {
    let first = true;
    await page.route((url) => url.pathname.endsWith('/read-excel-file.min.js'), async (route) => {
      assert.equal(new URL(route.request().url()).search, '?v=20261110-7');
      if (first) { first = false; await route.abort(); } else await route.continue();
    });
    await page.goto('/parts-list');
    await page.getByLabel('Файл списка').setInputFiles({ name: 'parts.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: Buffer.from('UEsDBBQAAAAIAIital0cQqjhkAAAAO4AAAATAAAAW0NvbnRlbnRfVHlwZXNdLnhtbHWPQQ6CQAxFr0Jma6DEhQsDbNQbeIFmLMzEYaah1eDtHZCV0WX7/u9Lm+uLSYp5DFFa41T5CCDW0YhSJaaYSZ+mETWP0wCM9o4Dwb6uD2BTVIpa6nLDdM2ZenwELS5zXotPsTUTBTHF6RNcXK1B5uAtaubwjLcvS7kZqtxcM+I8yy4HDPw0LOS/YOvB+mb3BlBLAwQUAAAACACIrWpd/luGcooAAADwAAAACwAAAF9yZWxzLy5yZWxzjc8xDsIwDAXQq1Q+QF0YGFDaiaUr4gImddqqTRw5QZTbk7EgBkbrf70vmyuvlGcJaZpjqja/htTClHM8IyY7sadUS+RQEifqKZdTR4xkFxoZj01zQt0b0Jm9WfVDC9oPB6hur8j/2OLcbPki9uE55B8TX40ik46cW9hWfIoud5GlLihgZ/Djwe4NUEsDBBQAAAAIAIital0HdP4ykQAAAOMAAAAPAAAAeGwvd29ya2Jvb2sueG1sjY8xDoMwDEWvEvkANXTogICpS7dewQXTRJA4slO1xy+Csney///y+3L7Fp0fIrP7xCVZB76U3CDa4DmSnSRzWpNJNFJZpT7RsjKN5plLXPBcVReMFBLshEb/Ycg0hYGvMrwip7JDlBcqQZL5kA36dmuw33SJIndwJy0GbrNuYwc1OG3CuuhtrAH7Fo8rPB7rv1BLAwQUAAAACACIrWpdtlSqz44AAADxAAAAGgAAAHhsL19yZWxzL3dvcmtib29rLnhtbC5yZWxzjc89DsIwDAXgq1Q5QN0yMKAmE0tXxAWi1G2iNj+yjYDbEzGgIjEwWX6WvicPF9yshJzYh8LNI26JtfIi5QTAzmO03OaCqV7mTNFKXWmBYt1qF4RD1x2B9oYyw95sxkkrGqdeNddnwX/sPM/B4Tm7W8QkPyrgnmlljygVtbSgaPWJGN6jb6uqwAzw9aF5AVBLAwQUAAAACACIrWpdyQlVHJMAAADNAAAAGAAAAHhsL3dvcmtzaGVldHMvc2hlZXQxLnhtbE2PUQrCMAxArzJ6ANMpE5EsoHgDT1BmdcW1HWnYPL7ZFDEfIXnhJQTnzM/Sey/VKw6ptKYXGY8Apet9dGWTR590cs8cnWjLDygje3dbpTjA1to9RBeSIVzZxYkj5DxX3JpaabcUp9pU0pqQhpD8VVh5KIRCVqNp7O5gEYQQFgrd1zov/kRbhOlDQfdq/jsEvw/oDVBLAQIUABQAAAAIAIital0cQqjhkAAAAO4AAAATAAAAAAAAAAAAAACAAQAAAABbQ29udGVudF9UeXBlc10ueG1sUEsBAhQAFAAAAAgAiK1qXf5bhnKKAAAA8AAAAAsAAAAAAAAAAAAAAIABwQAAAF9yZWxzLy5yZWxzUEsBAhQAFAAAAAgAiK1qXQd0/jKRAAAA4wAAAA8AAAAAAAAAAAAAAIABdAEAAHhsL3dvcmtib29rLnhtbFBLAQIUABQAAAAIAIital22VKrPjgAAAPEAAAAaAAAAAAAAAAAAAACAATICAAB4bC9fcmVscy93b3JrYm9vay54bWwucmVsc1BLAQIUABQAAAAIAIital3JCVUckwAAAM0AAAAYAAAAAAAAAAAAAACAAfgCAAB4bC93b3Jrc2hlZXRzL3NoZWV0MS54bWxQSwUGAAAAAAUABQBFAQAAwQMAAAAA', 'base64') });
    const check = page.getByRole('button', { name: 'Проверить наличие', exact: true });
    await check.click();
    await page.locator('[data-list-message][role=alert]').waitFor();
    await check.click();
    await page.getByText('Проверено строк: 1', { exact: true }).waitFor();
    assert.equal(await page.locator('[data-result-row] h3').textContent(), '1. 0000550380');
    await page.evaluate(() => localStorage.setItem('smtechno-request', JSON.stringify(Array.from({ length: 100 }, (_, i) => ({ article: 'LEGACY' + i, title: 'Legacy', brand: 'Не указан', quantity: 1 })))));
    await page.locator('[data-result-row]').getByRole('button', { name: 'В корзину' }).click();
    await page.getByText(/Корзина заполнена: максимум 100/).waitFor();
    const before = await storage(page);
    await page.getByLabel('Файл списка').setInputFiles({ name: 'parts.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4') });
    await check.click();
    await page.locator('[data-list-message][role=alert]').getByText(/PDF и фотографии можно отправить на расценку/).waitFor();
    assert.deepEqual(await storage(page), before);
  });

  await scenario("Недоступный модуль списка → ясная загрузка и контакты", async (page) => {
    await page.route((url) => url.pathname.endsWith('/parts-list.mjs'), (route) => route.abort());
    await page.goto('/parts-list');
    assert.equal(await page.getByRole('button', { name: 'Проверить наличие', exact: true }).isDisabled(), true);
    await page.locator('[data-list-preparing]').getByRole('link', { name: 'откройте страницу заново' }).waitFor();
  });

  await scenario("Canonical identity separates offers and preserves fresh additions during acknowledgement", async (page) => {
    await page.goto('/request');
    const result = await page.evaluate(async () => {
      const { writeCart, mergeCart, readCart, acknowledgeCart } = await import('/extension/cmteh/catalog/view/assets/cart.mjs?v=20261110-7');
      const legacy = { article: 'SAME', brand: 'Brand', title: 'Legacy', quantity: 2 };
      writeCart([legacy]);
      mergeCart([{ ...legacy, productId: 'offer-a', quantity: 3 }, { ...legacy, productId: 'offer-b', quantity: 4 }]);
      const selected = readCart();
      mergeCart([{ ...legacy, productId: 'offer-a', quantity: 1 }]);
      acknowledgeCart([legacy, selected[1]]);
      return { selected, remaining: readCart() };
    });
    assert.deepEqual(result.selected.map(({ productId, quantity }) => [productId || null, quantity]), [[null, 2], ['offer-a', 3], ['offer-b', 4]]);
    assert.deepEqual(result.remaining.map(({ productId, quantity }) => [productId, quantity]), [['offer-a', 1], ['offer-b', 4]]);
  });

  await scenario("Storage replacement of a canonical offer reloads its price and submits the new ID", async (page) => {
    const offers = ['offer-a', 'offer-b'].map((id, index) => ({
      id, sku: 'SAME-SKU', brand: 'Same Brand', title: 'Offer ' + id,
      price_status: 'fixed', price: 111 + 111 * index, currency: 'RUB',
    }));
    let searches = 0;
    let submitted;
    await page.route('**/api/search?*', async (route) => {
      searches++;
      await route.fulfill({ json: { data: offers, meta: { total: 2, partial: false } } });
    });
    await page.route('**/api/lead', async (route) => {
      submitted = route.request().postDataJSON();
      await route.fulfill({ json: { id: '462e243b-de01-4ca5-bb0a-cfe1134aa130', replayed: false } });
    });
    await page.goto('/request');
    const sibling = await page.context().newPage();
    await sibling.goto('/contacts');
    const replace = (id) => sibling.evaluate((productId) => {
      localStorage.setItem('smtechno-request', JSON.stringify([
        { article: 'SAME-SKU', brand: 'Same Brand', title: 'Selected offer', quantity: 2, productId },
      ]));
    }, id);
    await replace('offer-a');
    await page.locator('.cart-price-label').getByText(/111/).waitFor();
    const refreshed = page.waitForRequest('**/api/search?*', { timeout: 3000 });
    await replace('offer-b');
    await refreshed;
    await page.locator('.cart-price-label').getByText(/222.*444/).waitFor();
    assert.equal(searches, 2);
    await fill(page, 'Canonical Price');
    await page.locator('form[data-lead="request"]').getByRole('button', { name: 'Отправить заявку' }).click();
    await page.getByText(/Заявка принята/).waitFor();
    assert.deepEqual(submitted.request_items, [{ article: 'SAME-SKU', quantity: 2, product_id: 'offer-b' }]);
  });

  await scenario("Недоступный модуль заявки → объяснение в короткой форме и контакты", async (page) => {
    await page.route((url) => url.pathname.endsWith('/request.mjs'), (route) => route.abort());
    await page.goto("/");
    await page.getByRole("link", { name: "Запрос", exact: true }).click();
    const dialog = page.getByRole("dialog");
    assert.equal(await dialog.getByRole("button", { name: "Отправить запрос" }).isDisabled(), true);
    assert.match(await dialog.getByRole("status").filter({ visible: true }).allTextContents().then((messages) => messages.join(" ")), /Форма подготавливается/);
    assert.equal(await dialog.getByRole("link", { name: "Связаться через страницу контактов" }).getAttribute("href"), "/contacts");
  });

  await scenario("Поиск → карточка → корзина → заявка", async (page) => {
    const response = await page.goto("/");
    assert.equal(response.status(), 200);
    const search = page.getByRole("combobox", { name: "Поиск запчастей" });
    await search.fill("RE-568158");
    await page.getByRole("option", { name: new RegExp(sku) }).first().waitFor();
    await search.press("ArrowDown");
    await search.press("Enter");
    await page.waitForURL("**/product/jd-re568158");
    await page.locator(".product-info").getByRole("button", { name: "В корзину", exact: true }).click();
    await page.goto("/request");
    await page.getByRole("button", { name: "Увеличить количество " + sku }).click();
    assert.equal((await storage(page))[0].quantity, 2);
    await fill(page, "Алексей Тест");
    const sent = page.waitForResponse((res) => res.url().endsWith("/api/lead") && res.request().method() === "POST");
    await page.locator('form[data-lead="request"]').getByRole("button", { name: "Отправить заявку" }).click();
    const acknowledgement = await sent;
    assert.equal(acknowledgement.status(), 200);
    const body = await acknowledgement.json();
    assert.ok(body.id);
    assert.equal(body.replayed, false);
    await page.getByText(/Заявка принята\. Номер:/).waitFor();
    await page.getByRole("heading", { name: "Корзина пока пуста" }).waitFor();
  });

  await scenario("Потерянный ответ → тот же ключ → одна заявка", async (page) => {
    const payloads = [];
    let firstId;
    await page.route("**/api/lead", async (route) => {
      payloads.push(route.request().postDataJSON());
      const response = await route.fetch({ maxRedirects: 0 });
      assert.equal(response.status(), 200);
      const body = await response.json();
      if (payloads.length === 1) {
        assert.equal(body.replayed, false);
        firstId = body.id;
        await route.abort("failed");
      } else {
        assert.equal(body.id, firstId);
        assert.equal(body.replayed, true);
        await page.evaluate(() => {
          const items = JSON.parse(localStorage.getItem("smtechno-request"));
          items[0].quantity = 5;
          localStorage.setItem("smtechno-request", JSON.stringify(items));
          window.dispatchEvent(new StorageEvent("storage", { key: "smtechno-request" }));
        });
        await route.fulfill({ response });
      }
    });
    await page.goto('/request');
    await page.evaluate((article) => {
      localStorage.setItem('smtechno-request', JSON.stringify([{ article, quantity: 3, title: 'Legacy', brand: 'Не указан' }]));
    }, sku);
    await page.reload();
    await fill(page, "Иван Тест");
    const requestForm = page.locator('form[data-lead="request"]');
    const submit = requestForm.getByRole("button", { name: "Отправить заявку" });
    await submit.click();
    await requestForm.getByRole("alert").waitFor();
    assert.equal((await storage(page))[0].quantity, 3);
    await page.reload();
    assert.equal(await requestForm.getByLabel("Контактное лицо").inputValue(), "Иван Тест");
    assert.equal(await requestForm.getByLabel("Телефон").inputValue(), phone);
    await submit.click();
    await page.getByText(/Заявка принята\. Номер:/).waitFor();
    assert.deepEqual(payloads[0], payloads[1]);
    assert.ok(payloads[0].request_key);
    assert.equal((await storage(page))[0].quantity, 2);
  });

  await scenario("Невалидный короткий запрос → закрытие → фокус и нижняя навигация", async (page) => {
    await page.setViewportSize({ width: 390, height: 664 });
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto("/");
    const trigger = page.getByRole("link", { name: "Запрос", exact: true });
    const dialog = page.getByRole("dialog");
    for (const close of [() => page.keyboard.press("Escape"), () => dialog.locator("[data-close-quick]").click()]) {
      await trigger.click();
      await dialog.getByRole("button", { name: "Отправить запрос", exact: true }).click();
      await page.waitForFunction(() => getComputedStyle(document.querySelector(".mobile-actions")).visibility === "hidden");
      await close();
      await dialog.waitFor({ state: "hidden" });
      await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))));
      assert.equal(await trigger.evaluate((link) => document.activeElement === link), true,
        "После закрытия невалидной формы фокус должен вернуться на видимую ссылку Запрос");
      assert.equal(await page.locator(".mobile-actions").evaluate((bar) => getComputedStyle(bar).visibility), "visible");
    }
    // A real click proves that the restored bottom action remains reachable.
    await trigger.click();
    assert.equal(await dialog.isVisible(), true);
  });

  await scenario("Короткий запрос со страницы корзины сохраняет корзину", async (page) => {
    await page.addInitScript(() => localStorage.setItem("smtechno-request", JSON.stringify([
      { article: "RE568158", title: "Тестовая позиция", brand: "John Deere", quantity: 2 },
    ])));
    await page.goto("/");
    const homeTrigger = page.getByRole("link", { name: "Запрос", exact: true });
    await homeTrigger.click();
    const homeDialog = page.getByRole("dialog");
    await homeDialog.getByLabel("Контактное лицо").waitFor();
    assert.equal(await homeDialog.getByRole("button", { name: "Отправить запрос" }).isDisabled(), false);
    assert.equal(await homeDialog.getByText(/Форма подготавливается/).isVisible(), false);
    await page.keyboard.press("Escape");
    await page.goto("/request");
    const trigger = page.getByRole("link", { name: "Запрос", exact: true });
    await trigger.click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Контактное лицо").fill("Короткий тест");
    await dialog.getByLabel("Телефон").fill(phone);
    await page.keyboard.press("Escape");
    await dialog.waitFor({ state: "hidden" });
    await trigger.click();
    assert.equal(await dialog.getByLabel("Контактное лицо").inputValue(), "Короткий тест");
    await dialog.locator(".quick-dialog-close").evaluate((button) => button.closest("dialog").dispatchEvent(new MouseEvent("click", { bubbles: true })));
    assert.equal(await dialog.isVisible(), false);
    assert.equal(await trigger.evaluate((link) => document.activeElement === link), true);
    await trigger.click();
    await dialog.getByLabel("Комментарий").fill("Тест быстрого обращения");
    const sent = page.waitForResponse((res) => res.url().endsWith("/api/lead") && res.request().method() === "POST");
    await dialog.getByRole("button", { name: "Отправить запрос", exact: true }).click();
    const response = await sent;
    assert.equal(response.status(), 200);
    const body = await response.json();
    assert.equal(body.replayed, false);
    const lead = response.request().postDataJSON();
    assert.equal(lead.intent, "quick");
    assert.deepEqual(lead.request_items, []);
    assert.match(lead.page_url, /\/request(?:\?|$)/);
    await dialog.getByText(/Запрос принят/).waitFor();
    assert.equal(await page.locator(".request-line").count(), 1);
    assert.equal((await storage(page)).length, 1);
  });

  await scenario("Закрытие короткой формы не перекрывает отправку на контактах", async (page) => {
    await page.setViewportSize({ width: 390, height: 664 });
    await page.goto("/contacts");
    const submit = page.locator('main .lead-form button[type="submit"]');
    const backToTop = page.locator("[data-back-to-top]");
    await submit.evaluate((button) => window.scrollTo({ top: window.scrollY + button.getBoundingClientRect().bottom - 588, behavior: "instant" }));
    await page.waitForFunction(() => window.scrollY > 360 && document.querySelector("[data-back-to-top]").hidden);
    const before = await submit.boundingBox();
    assert.ok(before.y >= 0 && before.y + before.height <= 664);
    await page.getByRole("link", { name: "Запрос", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: "Отправить запрос" }).scrollIntoViewIfNeeded();
    // Allow the native observer to report the dialog submit entering the viewport.
    await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))));
    await page.keyboard.press("Escape");
    await dialog.waitFor({ state: "hidden" });
    await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))));
    const after = await submit.boundingBox();
    assert.ok(after.y >= 0 && after.y + after.height <= 664);
    const floating = await backToTop.boundingBox();
    const overlaps = floating && floating.x < after.x + after.width && floating.x + floating.width > after.x
      && floating.y < after.y + after.height && floating.y + floating.height > after.y;
    assert.equal(Boolean(overlaps), false, "Наверх перекрывает видимую отправку после Escape: " + JSON.stringify({ submit: after, backToTop: floating }));
    assert.equal(await backToTop.isVisible(), false);
  });
} finally {
  await browser.close();
}
