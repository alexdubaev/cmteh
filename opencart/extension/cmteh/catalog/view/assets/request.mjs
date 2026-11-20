import { MAX_QUANTITY, acknowledgeCart, articleKey, hasBrand, lineKey, normalizeQuantity, onCartChange, readCart, withCartWrite, writeCart } from "./cart.mjs?v=20261117-2";

const element = (tag, text = "", className = "") => {
  const node = document.createElement(tag);
  node.className = className;
  node.textContent = text;
  return node;
};

function quantityButton(label, text, click) {
  const button = element("button", text);
  button.type = "button";
  button.setAttribute("aria-label", label);
  button.addEventListener("click", click);
  return button;
}

function formatCount(count) {
  const tens = count % 100;
  const ones = count % 10;
  const noun = tens >= 11 && tens <= 14 ? "позиций" : ones === 1 ? "позиция" : ones >= 2 && ones <= 4 ? "позиции" : "позиций";
  return count + " " + noun;
}

function exactProduct(item, payload) {
  const result = payload?.data;
  if (!Array.isArray(result) || result.length !== payload.meta?.total || payload.meta.partial) return null;
  const candidates = result.filter((product) => matchesItem(item, product));
  return candidates.length === 1 ? candidates[0] : null;
}

function matchesItem(item, product) {
  if (item.productId && product?.id !== item.productId) return false;
  if (typeof product?.sku !== "string" || articleKey(product.sku) !== articleKey(item.article)) return false;
  if (!hasBrand(item.brand)) return true;
  return typeof product.brand === "string" && product.brand.trim().toLocaleLowerCase("ru") === item.brand.trim().toLocaleLowerCase("ru");
}

function batchLookups(path, ids, signal) {
  const lookups = new Map();
  let batch = [];
  let query = "";
  function addBatch() {
    if (!batch.length) return;
    const url = path + "?" + query;
    let response;
    // Start only when a cart worker needs an item in this batch.
    const load = () => response ??= fetch(url, { signal, cache: "no-store" }).then(async (result) => {
      if (!result.ok) throw new Error("Unavailable");
      const { data } = await result.json();
      if (!data || typeof data !== "object") throw new Error("Missing lookup data");
      return data;
    });
    for (const id of batch) lookups.set(id, load);
    batch = [];
    query = "";
  }
  for (const id of ids) {
    const parameter = new URLSearchParams([["ids[]", id]]).toString();
    // Bound the encoded request target, which is ASCII after serialization.
    if (path.length + parameter.length + 1 > 3500) continue;
    if (path.length + query.length + parameter.length + 2 > 3500) addBatch();
    query += (query ? "&" : "") + parameter;
    batch.push(id);
  }
  addBatch();
  return lookups;
}

async function lookup(lookups, id) {
  const data = await lookups.get(id)();
  return Object.hasOwn(data, id) ? data[id] : null;
}

const retiredFilterId = "mock-1";
const publishedFilterId = "storeland-product-195589389";
const oilFilter = { article: "1R1808", brand: "Caterpillar" };
let filterLookup;

function matchesFilterRow(item, id) {
  return !item.warehouseSelection && !item.offerId && item.productId === id
    && articleKey(item.article) === oilFilter.article
    && item.brand.trim().toLocaleLowerCase("ru") === oilFilter.brand.toLocaleLowerCase("ru");
}

const retiredFilterRow = (item) => matchesFilterRow(item, retiredFilterId);
class FilterMigrationError extends Error {}

function lookupFilterReplacement() {
  if (filterLookup) return filterLookup;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 5000);
  const lookups = batchLookups("/api/product-prices", [retiredFilterId, publishedFilterId], controller.signal);
  filterLookup = lookups.get(publishedFilterId)().then((data) => {
    if (Object.hasOwn(data, retiredFilterId)) return null;
    const product = data[publishedFilterId];
    if (product?.id !== publishedFilterId || !matchesItem(oilFilter, product)
        || product.inventory_managed !== false || typeof product.title !== "string" || !product.title.trim()) {
      throw new Error("Replacement unavailable");
    }
    return product;
  }).finally(() => {
    clearTimeout(timeout);
    filterLookup = undefined;
  });
  return filterLookup;
}

async function readRequestCart() {
  const items = await withCartWrite(readCart);
  if (!document.body.classList.contains("v2-storefront") || !items.some(retiredFilterRow)) return items;

  let replacement;
  try {
    replacement = await lookupFilterReplacement();
  } catch {
    const latest = await withCartWrite(readCart);
    if (!latest.some(retiredFilterRow)) return latest;
    throw new FilterMigrationError("Не удалось проверить артикул 1R1808. Повторите отправку или удалите эту позицию из корзины.");
  }

  return withCartWrite(() => {
    const latest = readCart();
    if (!replacement || !latest.some(retiredFilterRow)) return latest;
    const sameFilter = (item) => retiredFilterRow(item) || matchesFilterRow(item, publishedFilterId);
    const quantity = latest.filter(sameFilter).reduce((sum, item) => sum + item.quantity, 0);
    if (quantity > MAX_QUANTITY) {
      throw new FilterMigrationError("Уменьшите суммарное количество фильтров 1R1808 до 100 000 штук и повторите отправку.");
    }
    const first = latest.findIndex(sameFilter);
    const migrated = latest.filter((item) => !sameFilter(item));
    migrated.splice(first, 0, {
      productId: replacement.id, article: replacement.sku, brand: replacement.brand, title: replacement.title, quantity,
    });
    writeCart(migrated);
    return migrated;
  });
}

function fixedPrice(product) {
  if (!product || product.inventory_managed || product.price_status !== "fixed") return null;
  const currency = typeof product.currency === "string" ? product.currency.trim().toUpperCase() : "";
  if (!/^[A-Z]{3}$/.test(currency)) return null;
  const digits = new Intl.NumberFormat("ru-RU", { style: "currency", currency }).resolvedOptions().maximumFractionDigits;
  const scale = 10n ** BigInt(digits);
  let minor;
  if (typeof product.price === "string" && /^\d{1,13}\.\d{2}$/.test(product.price) && digits === 2) {
    minor = BigInt(product.price.replace(".", ""));
  } else {
    if (!Number.isFinite(product.price) || product.price < 0) return null;
    const value = Math.round(product.price * Number(scale));
    if (!Number.isSafeInteger(value)) return null;
    minor = BigInt(value);
  }
  return { minor, currency, scale, digits };
}
function money(unit, minor = unit.minor) {
  const whole = minor / unit.scale;
  const fraction = minor % unit.scale;
  return whole.toLocaleString("ru-RU") + (fraction ? "," + fraction.toString().padStart(unit.digits, "0") : "") + " " + ({ RUB: "₽", USD: "$", EUR: "€" }[unit.currency] || unit.currency);
}

function setupCart(cart) {
  const heading = cart.querySelector("[data-cart-heading]");
  const empty = cart.querySelector("[data-cart-empty]");
  const list = cart.querySelector(".request-lines");
  const totals = cart.querySelector(".cart-totals");
  const showUnavailable = document.body.classList.contains("v2-storefront");
  let entries = new Map();
  const unavailable = (item) => showUnavailable && entries.get(lineKey(item))?.unavailable === true;
  let identity;
  let controller;

  async function updateItem(key, delta) {
    await withCartWrite(() => {
      const items = readCart();
      const index = items.findIndex((item) => lineKey(item) === key);
      if (index < 0) return;
      if (delta === null) items.splice(index, 1);
      else items[index].quantity = normalizeQuantity(items[index].quantity + delta);
      writeCart(items);
    });
  }
  async function review(item, offer) {
    await withCartWrite(() => {
      const items = readCart();
      const current = items.find((candidate) => lineKey(candidate) === lineKey(item));
      if (!current) return;
      Object.assign(current, { offerVersion: offer.offer_version, price: offer.price, currency: offer.currency,
        warehouseName: offer.warehouse.name, warehouseCode: offer.warehouse.code, warehouseCity: offer.warehouse.city,
        deliveryTerm: offer.delivery_term, selectionInvalid: false });
      writeCart(items);
    });
  }
  function render() {
    const items = readCart();
    const focused = list.contains(document.activeElement) ? document.activeElement.getAttribute("aria-label") : null;
    heading.textContent = items.length ? formatCount(items.length) : "Корзина пока пуста";
    empty.hidden = items.length > 0;
    list.hidden = items.length === 0;
    const totalsByCurrency = new Map();
    let loading = false, failed = false, unpriced = 0, removed = 0;
    const rows = items.map((item) => {
      const key = lineKey(item);
      const row = element("div", "", "request-line");
      const body = element("div");
      const entry = entries.get(key);
      const label = item.article + (item.warehouseSelection ? ", склад " + (item.warehouseName || item.warehouseCode || "не выбран") : "");
      body.append(element("small", item.brand), element("strong", item.article));
      if (unavailable(item)) {
        body.append(element("span", "Позиция недоступна. Удалите её перед отправкой заявки.", "cart-selection-warning"));
      } else {
        body.append(element("span", entry?.product?.title || item.title));
      }
      if (item.warehouseSelection) {
        body.append(element("strong", "Склад: " + item.warehouseName + (item.warehouseCity ? ", " + item.warehouseCity : "")), element("span", "Срок поставки: " + (item.deliveryTerm || "Уточнит менеджер")));
        if (item.selectionInvalid || (entry && !entry.failed && !entry.offer)) {
          body.append(element("span", "Предложение недоступно. Выберите склад заново в каталоге или удалите строку.", "cart-selection-warning"));
        } else if (entry?.offer && entry.offer.offer_version !== item.offerVersion) {
          body.append(element("span", "Условия склада изменились: " + (entry.offer.price === null ? "цена по запросу" : entry.offer.price.replace(".", ",") + " ₽") + " · " + (entry.offer.delivery_term || "Срок уточнит менеджер"), "cart-selection-warning"));
          const accept = quantityButton("Принять новые условия " + label, "Принять новые условия", () => review(item, entry.offer));
          accept.className = "cart-review button secondary";
          accept.disabled = entry.offer.availability_status !== "in_stock";
          if (accept.disabled) accept.textContent = "Нет в наличии — выберите другой склад";
          body.append(accept);
        }
        const link = element("a", "Выбрать склад заново"); link.href = "/catalog?q=" + encodeURIComponent(item.article); body.append(link);
      } else if (entry?.product?.inventory_managed) {
        body.append(element("span", "Склад не выбран. Удалите эту строку после выбора нужного склада.", "cart-selection-warning"));
        const link = element("a", "Выбрать склад"); link.href = "/product/" + encodeURIComponent(entry.product.slug) + "#warehouse-offers"; body.append(link);
      }
      const unit = fixedPrice(item.warehouseSelection ? { price_status: "fixed", price: item.price, currency: item.currency } : entry?.product);
      let priceMessage = "Цена по запросу";
      if (unavailable(item)) { priceMessage = ""; removed++; }
      else if (!entry) { priceMessage = "Загружаем цену…"; loading = true; }
      else if (entry.failed) { priceMessage = "Не удалось проверить условия. Сохранённый выбор остаётся в корзине."; failed = true; unpriced++; }
      else if (unit) {
        const lineMinor = unit.minor * BigInt(item.quantity);
        totalsByCurrency.set(unit.currency, { ...unit, minor: (totalsByCurrency.get(unit.currency)?.minor ?? 0n) + lineMinor });
        priceMessage = money(unit) + " за шт." + (item.quantity > 1 ? " · " + money(unit, lineMinor) + " за " + item.quantity + " шт." : "");
      } else unpriced++;
      if (priceMessage) body.append(element("small", priceMessage, "cart-price-label"));
      const qty = element("div", "", "qty");
      qty.append(quantityButton("Уменьшить количество " + label, "−", () => updateItem(key, -1)), element("b", item.quantity), quantityButton("Увеличить количество " + label, "+", () => updateItem(key, 1)));
      const remove = quantityButton("Удалить " + label, "×", () => updateItem(key, null)); remove.className = "remove";
      row.append(body, qty, remove); return row;
    });
    list.replaceChildren(...rows);
    if (focused) Array.from(list.querySelectorAll("button")).find((button) => button.getAttribute("aria-label") === focused)?.focus({ preventScroll: true });
    totals.hidden = loading || removed === items.length; totals.replaceChildren();
    if (!totals.hidden) {
      totals.append(element("strong", items.some((item) => item.warehouseSelection) ? "Предварительный расчёт по выбранным условиям" : unpriced ? "Расчёт по позициям с ценой" : "Расчёт по ценам каталога"));
      for (const unit of totalsByCurrency.values()) totals.append(element("b", money(unit)));
      if (!totalsByCurrency.size) totals.append(element("span", "Сумма будет рассчитана после уточнения цен"));
      if (unpriced) totals.append(element("span", "Позиции без цены не включены — стоимость уточнит менеджер.", "cart-total-note"));
      if (failed) { const retry = quantityButton("Повторить загрузку цен", "Повторить загрузку цен", () => update(true)); retry.className = "cart-retry"; totals.append(retry); }
    }
  }
  async function loadPrice(item, signal, warehouseLookups, productLookups) {
    try {
      if (item.warehouseSelection) {
        if (item.selectionInvalid) return {};
        const data = await lookup(warehouseLookups, item.productId);
        return { offer: data?.offers?.find((offer) => offer.offer_id === item.offerId) || null };
      }
      if (item.productId) {
        const product = await lookup(productLookups, item.productId);
        return { product: matchesItem(item, product) ? product : null, unavailable: product === null };
      }
      const query = item.article.trim();
      if (query.length < 2 || query.length > 64) return { product: null };
      const response = await fetch("/api/search?q=" + encodeURIComponent(query), { signal });
      if (!response.ok) return { failed: true };
      return { product: exactProduct(item, await response.json()) };
    } catch { return { failed: true }; }
  }
  function update(retry = false) {
    const items = readCart();
    const nextIdentity = JSON.stringify(items.map((item) => [item.article, item.brand, item.productId || null, item.offerId || null, item.offerVersion || null]));
    if (retry || nextIdentity !== identity) {
      identity = nextIdentity; controller?.abort();
      const signal = (controller = new AbortController()).signal; entries = new Map();
      const warehouseIds = new Set(items.filter((item) => item.warehouseSelection && !item.selectionInvalid).map((item) => item.productId));
      const productIds = new Set(items.filter((item) => !item.warehouseSelection && item.productId).map((item) => item.productId));
      const warehouseLookups = batchLookups("/api/warehouse-offers", warehouseIds, signal);
      const productLookups = batchLookups("/api/product-prices", productIds, signal);
      const queue = [...new Map(items.map((item) => [lineKey(item), item])).values()]; let index = 0;
      async function worker() {
        while (!signal.aborted && index < queue.length) {
          const item = queue[index++]; const entry = await loadPrice(item, signal, warehouseLookups, productLookups);
          if (!signal.aborted) entries.set(lineKey(item), entry);
        }
      }
      void Promise.all(Array.from({ length: Math.min(queue.length, 4) }, worker)).then(() => { if (!signal.aborted) render(); });
    }
    render();
  }
  async function verificationIssue(items) {
    if (!showUnavailable) return "";
    const missingMessage = "Удалите недоступные позиции из корзины и повторите отправку.";
    if (items.some(unavailable)) return missingMessage;
    const unverifiedIds = new Set(items.filter((item) => item.productId && !item.warehouseSelection
      && (!entries.has(lineKey(item)) || entries.get(lineKey(item)).failed)).map((item) => item.productId));
    if (!unverifiedIds.size) return "";

    // A submit can arrive before the cart finishes its price lookups.
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 5000);
    const lookups = batchLookups("/api/product-prices", unverifiedIds, controller.signal);
    try {
      const products = await Promise.all([...unverifiedIds].map((id) => lookup(lookups, id)));
      return products.includes(null) ? missingMessage : "";
    } catch {
      return "Не удалось проверить позиции корзины. Повторите отправку.";
    } finally {
      clearTimeout(timeout);
    }
  }

  onCartChange(() => { if (cart.isConnected) update(); });
  return {
    refresh: () => { if (cart.isConnected) update(true); },
    verificationIssue,
  };
}

const names = ["company", "name", "phone", "email", "message"];
const fallback = new Map();
function readSession(key) {
  if (fallback.has(key)) return fallback.get(key);
  try { return sessionStorage.getItem(key); } catch { return null; }
}
function writeSession(key, value) {
  fallback.set(key, value);
  try { sessionStorage.setItem(key, value); } catch { /* Keep this tab's copy. */ }
}
function clearSession(key, expected) {
  if (readSession(key) !== expected) return;
  fallback.set(key, null);
  try { sessionStorage.removeItem(key); } catch { /* The cleared in-page value remains authoritative. */ }
}
function parseSession(key) {
  try { return JSON.parse(readSession(key) || "{}") ?? {}; } catch { return {}; }
}
async function fingerprint(payload) {
  const json = JSON.stringify(payload);
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(json));
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, "0")).join("");
}

function setupLead(form, cartView) {
  const type = form.dataset.lead;
  const rfq = type === "request";
  const legacyPendingKey = "smtechno-pending-lead:" + type;
  const submit = form.querySelector("button[type=submit]");
  const progress = form.querySelector("[data-lead-loading]");
  const empty = form.querySelector("[data-cart-required]");
  const result = form.querySelector("[data-lead-result]");
  const quickContext = form.querySelector("[data-v2-quick-context]");
  const readQuickArticle = () => quickContext
    ? document.querySelector("#main-content [data-v2-quick-article]")?.dataset.v2QuickArticle.trim() || ""
    : "";
  let sending = false;
  let preparingCart = false;
  let draftKey;

  const readFields = () => Object.fromEntries(names.map((name) => [name, form.elements.namedItem(name).value]));
  function syncDraft() {
    const key = "smtechno-form-draft:" + (rfq ? type : type + ":" + location.pathname);
    if (key === draftKey) return;
    const initial = draftKey === undefined;
    draftKey = key;
    const draft = parseSession(key);
    if (!initial) form.reset();
    for (const name of names) {
      const field = form.elements.namedItem(name);
      if ((!initial || !field.value) && typeof draft[name] === "string") field.value = draft[name];
      if (!initial) fieldError(field, "");
    }
    if (!initial) show("", false);
  }
  syncDraft();
  if (type === "quick") {
    const syncContext = () => {
      if (!quickContext) return;
      const article = readQuickArticle();
      quickContext.querySelector("strong").textContent = article;
      quickContext.hidden = !article;
    };
    syncContext();
    document.addEventListener("v2:navigated", () => {
      syncDraft();
      syncContext();
    });
  }
  function sync() {
    const required = rfq && readCart().length === 0;
    if (empty) empty.hidden = !required;
    submit.disabled = preparingCart || sending || required;
  }
  onCartChange(sync);

  function show(message, error) {
    result.textContent = message;
    result.setAttribute("role", error ? "alert" : "status");
    result.classList.toggle("error", error);
    result.hidden = message === "";
  }
  function fieldError(field, message) {
    const error = form.querySelector("#" + type + "-" + field.name + "-error");
    if (!error) return;
    field.setAttribute("aria-invalid", String(Boolean(message)));
    error.textContent = message;
    error.hidden = message === "";
  }
  form.addEventListener("input", (event) => {
    if (!names.includes(event.target.name)) return;
    writeSession(draftKey, JSON.stringify({ ...readFields(), revision: crypto.randomUUID() }));
    fieldError(event.target, "");
  });
  form.addEventListener("invalid", (event) => {
    const field = event.target;
    const reason = field.validity.valueMissing ? "Заполните это поле." : field.validity.typeMismatch ? "Укажите корректный email." : field.validationMessage;
    fieldError(field, reason);
  }, true);

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (preparingCart || sending) return;
    const page_url = location.href;
    let items = [];
    if (rfq) {
      // Queue behind quantity, removal and offer changes already requested.
      preparingCart = true;
      sync();
      try {
        items = await readRequestCart();
        const issue = await cartView?.verificationIssue(items);
        if (issue) { show(issue, true); return; }
      } catch (error) {
        if (!(error instanceof FilterMigrationError)) throw error;
        show(error.message, true);
        return;
      } finally {
        preparingCart = false;
        sync();
      }
    }
    if (rfq && !items.length) {
      show("Добавьте позиции в корзину перед отправкой заявки.", true);
      return;
    }
    if (items.length > 100 || items.some((item) => item.selectionInvalid)) {
      show("Проверьте выбранные склады и оставьте не более 100 позиций.", true); return;
    }
    const warehouse = items.some((item) => item.warehouseSelection);
    const pendingKey = warehouse ? "smtechno-pending-warehouse-lead-v1:" + type : legacyPendingKey;
    const original = readFields();
    const { name, phone, email, company, message } = original;
    if (name.trim().length < 2) {
      fieldError(form.elements.namedItem("name"), "Введите не менее двух символов.");
      form.elements.namedItem("name").focus();
      return;
    }
    const digits = phone.replace(/\D/g, "");
    if (digits.length < 7 || digits.length > 15 || !/^[+\d().\s-]+$/.test(phone)) {
      fieldError(form.elements.namedItem("phone"), "Укажите корректный телефон.");
      form.elements.namedItem("phone").focus();
      return;
    }
    const article = readQuickArticle();
    const submittedMessage = article ? ["Артикул: " + article, message.trim()].filter(Boolean).join("\n\n") : message;
    const note = [company.trim() && "Компания: " + company.trim(), submittedMessage.trim()].filter(Boolean).join("\n\n");
    if (note.length > 3000 || page_url.length > 1000) {
      if (note.length > 3000) {
        const limit = article ? "Сократите комментарий: артикул тоже входит в лимит 3 000 символов."
          : "Сократите компанию и комментарий до 3 000 символов вместе.";
        show(limit, true);
      } else {
        show("Откройте страницу без лишних параметров и повторите отправку.", true);
      }
      return;
    }
    const request_items = items.map(({ article, quantity, productId, offerId, offerVersion }) => ({ article, quantity, ...(productId ? { product_id: productId } : {}), ...(offerId ? { offer_id: offerId, offer_version: offerVersion } : {}) }));
    // Preserve the original field order for pending hashes stored before migration.
    const payload = rfq
      ? { name, phone, email, company, message, request_items, page_url }
      : { company, name, phone, email, message: submittedMessage, request_items, page_url };
    if (type === "quick") payload.intent = "quick";
    const website = form.elements.namedItem("website").value;
    const submittedDraftKey = draftKey;
    const savedDraft = readSession(submittedDraftKey);
    const showSubmission = (message, error) => {
      if (draftKey === submittedDraftKey) show(message, error);
    };
    sending = true;
    sync();
    submit.textContent = "Отправляем…";
    form.setAttribute("aria-busy", "true");
    progress.hidden = false;
    show("", false);
    for (const field of names) form.elements.namedItem(field).readOnly = true;
    try {
      const hash = await fingerprint(payload);
      const previous = parseSession(pendingKey);
      const uuid = /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i;
      const key = previous.fingerprint === hash && uuid.test(previous.key) ? previous.key : crypto.randomUUID();
      const pending = JSON.stringify({ fingerprint: hash, key });
      writeSession(pendingKey, pending);
      const response = await fetch(warehouse ? "/api/lead/warehouse" : "/api/lead", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ request_key: key, ...payload, ...(website ? { website } : {}) }),
      });
      const body = await response.json().catch(() => null);
      if (!response.ok) {
        // A warehouse conflict may be the first notice that the server revised an offer.
        if (rfq && response.status === 409) cartView?.refresh();
        showSubmission(typeof body?.error === "string" && body.error ? body.error : "Не удалось отправить заявку. Попробуйте ещё раз.", true);
        return;
      }
      const id = body?.id;
      if (!((typeof id === "string" && id.trim()) || (Number.isSafeInteger(id) && id > 0)) || typeof body.replayed !== "boolean") {
        showSubmission("Сервис вернул некорректное подтверждение заявки. Попробуйте ещё раз.", true);
        return;
      }
      if (rfq && !await withCartWrite(() => acknowledgeCart(items))) {
        showSubmission("Заявка принята. Номер: " + id + ". Хранилище браузера недоступно: корзина сохранена в этой вкладке, повторная отправка вернёт тот же номер.", false);
        return;
      }
      clearSession(pendingKey, pending);
      if (draftKey === submittedDraftKey && names.every((name) => form.elements.namedItem(name).value === original[name])) form.reset();
      clearSession(submittedDraftKey, savedDraft);
      showSubmission((rfq ? "Заявка принята. Номер: " : type === "quick" ? "Запрос принят: " : "Заявка принята: ") + id + ". Следующий шаг: менеджер свяжется с вами.", false);
    } catch {
      showSubmission("Не удалось получить подтверждение. Попробуйте отправить заявку ещё раз.", true);
    } finally {
      sending = false;
      sync();
      submit.textContent = type === "quick" ? "Отправить запрос" : "Отправить заявку";
      form.setAttribute("aria-busy", "false");
      progress.hidden = true;
      for (const field of names) form.elements.namedItem(field).readOnly = false;
    }
  });
  const preparing = form.querySelector("[data-lead-preparing]");
  if (preparing) preparing.hidden = true;
}

const carts = new WeakMap();
const leads = new WeakSet();

export function mountRequestPage(root, { refresh = false } = {}) {
  const cart = root.querySelector("[data-cart]");
  let cartView = carts.get(cart);
  if (cart && (!cartView || refresh) && document.body.classList.contains("v2-storefront") && readCart().some(retiredFilterRow)) {
    void readRequestCart().catch(() => {}); // Submission retries verification and shows an error if still unavailable.
  }
  if (cart && !cartView) {
    cartView = setupCart(cart);
    carts.set(cart, cartView);
  } else if (refresh) {
    cartView?.refresh();
  }
  for (const form of root.querySelectorAll("[data-lead]")) {
    if (leads.has(form)) continue;
    setupLead(form, cartView);
    leads.add(form);
  }
}

mountRequestPage(document);
