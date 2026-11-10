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
try {
  await scenario("Список неизвестных артикулов → три позиции по 1 шт. → reload", async (page) => {
    // Old unversioned URLs can remain cached with the hosting's invalid MIME.
    await page.route((url) => url.pathname.endsWith('.mjs') && !url.search,
      (route) => route.fulfill({ contentType: 'text/plain', body: 'stale module response' }));
    const response = await page.goto("/request?import=1");
    assert.equal(response.status(), 200);
    const articles = ["R267987", "Dz100599", "H280976"];
    const input = page.getByLabel("Артикул и количество, по одной позиции в строке");
    await input.fill(articles.join("\n"));
    await page.getByRole("button", { name: "Добавить список", exact: true }).click();
    await page.locator("[data-import-message]").getByText(/добавлено 3/).waitFor();
    assert.equal(await input.inputValue(), "");
    assert.deepEqual((await storage(page)).map(({ article, quantity }) => ({ article, quantity })),
      articles.map((article) => ({ article, quantity: 1 })));
    assert.equal(await page.locator(".request-line").count(), 3);
    await page.reload();
    await page.getByRole("button", { name: "Увеличить количество H280976" }).waitFor();
    assert.equal(await page.locator(".request-line").count(), 3);
    assert.deepEqual((await storage(page)).map(({ article, quantity }) => ({ article, quantity })),
      articles.map((article) => ({ article, quantity: 1 })));
  });

  await scenario("Недоступный модуль импорта → явное сообщение и заблокированная кнопка", async (page) => {
    await page.route((url) => url.pathname.endsWith('/request.mjs'), (route) => route.abort());
    await page.goto("/request?import=1");
    assert.equal(await page.locator('[data-import-text]').isDisabled(), true);
    assert.equal(await page.locator('[data-import-file]').isDisabled(), true);
    await page.locator('[data-import-unavailable]').getByRole('link', { name: 'откройте страницу заново' }).waitFor();
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
    const response = await page.goto("/request?import=1");
    assert.equal(response.status(), 200);
    await page.getByLabel("Артикул и количество, по одной позиции в строке").fill(sku + " 3");
    await page.getByRole("button", { name: "Добавить список" }).click();
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
