import { acknowledgeCart, articleKey, hasBrand, lineKey, normalizeQuantity, onCartChange, readCart, writeCart } from "./cart.mjs?v=20261111-1";

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
  const sku = articleKey(item.article);
  const candidates = result.filter((product) => {
    if (item.productId && product?.id !== item.productId) return false;
    if (typeof product?.sku !== "string" || articleKey(product.sku) !== sku) return false;
    if (!hasBrand(item.brand)) return true;
    return typeof product.brand === "string" && product.brand.trim().toLocaleLowerCase("ru") === item.brand.trim().toLocaleLowerCase("ru");
  });
  return candidates.length === 1 ? candidates[0] : null;
}

function fixedPrice(product) {
  if (!product || product.price_status !== "fixed") return null;
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
  let entries = new Map();
  let identity;
  let controller;

  function updateItem(key, delta) {
    const items = readCart();
    const index = items.findIndex((item) => lineKey(item) === key);
    if (index < 0) return;
    if (delta === null) items.splice(index, 1);
    else items[index].quantity = normalizeQuantity(items[index].quantity + delta);
    writeCart(items);
  }
  function review(item, offer) {
    const items = readCart();
    const current = items.find((candidate) => lineKey(candidate) === lineKey(item));
    if (!current) return;
    Object.assign(current, { offerVersion: offer.offer_version, price: offer.price, currency: offer.currency,
      warehouseName: offer.warehouse.name, warehouseCode: offer.warehouse.code, warehouseCity: offer.warehouse.city,
      deliveryTerm: offer.delivery_term, selectionInvalid: false });
    writeCart(items);
  }
  function render() {
    const items = readCart();
    const focused = list.contains(document.activeElement) ? document.activeElement.getAttribute("aria-label") : null;
    heading.textContent = items.length ? formatCount(items.length) : "Корзина пока пуста";
    empty.hidden = items.length > 0;
    list.hidden = items.length === 0;
    const totalsByCurrency = new Map();
    let loading = false, failed = false, unpriced = 0;
    const rows = items.map((item) => {
      const key = lineKey(item);
      const row = element("div", "", "request-line");
      const body = element("div");
      const entry = entries.get(key);
      const label = item.article + (item.warehouseSelection ? ", склад " + (item.warehouseName || item.warehouseCode || "не выбран") : "");
      body.append(element("small", item.brand), element("strong", item.article), element("span", entry?.product?.title || item.title));
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
      if (!entry) { priceMessage = "Загружаем цену…"; loading = true; }
      else if (entry.failed) { priceMessage = "Не удалось проверить условия. Сохранённый выбор остаётся в корзине."; failed = true; unpriced++; }
      else if (unit) {
        const lineMinor = unit.minor * BigInt(item.quantity);
        totalsByCurrency.set(unit.currency, { ...unit, minor: (totalsByCurrency.get(unit.currency)?.minor ?? 0n) + lineMinor });
        priceMessage = money(unit) + " за шт. · " + money(unit, lineMinor) + " за " + item.quantity + " шт.";
      } else unpriced++;
      body.append(element("small", priceMessage, "cart-price-label"));
      const qty = element("div", "", "qty");
      qty.append(quantityButton("Уменьшить количество " + label, "−", () => updateItem(key, -1)), element("b", item.quantity), quantityButton("Увеличить количество " + label, "+", () => updateItem(key, 1)));
      const remove = quantityButton("Удалить " + label, "×", () => updateItem(key, null)); remove.className = "remove";
      row.append(body, qty, remove); return row;
    });
    list.replaceChildren(...rows);
    if (focused) Array.from(list.querySelectorAll("button")).find((button) => button.getAttribute("aria-label") === focused)?.focus({ preventScroll: true });
    totals.hidden = !items.length || loading; totals.replaceChildren();
    if (!totals.hidden) {
      totals.append(element("strong", items.some((item) => item.warehouseSelection) ? "Предварительный расчёт по выбранным условиям" : unpriced ? "Расчёт по позициям с ценой" : "Расчёт по ценам каталога"));
      for (const unit of totalsByCurrency.values()) totals.append(element("b", money(unit)));
      if (!totalsByCurrency.size) totals.append(element("span", "Сумма будет рассчитана после уточнения цен"));
      if (unpriced) totals.append(element("span", "Позиции без цены не включены — стоимость уточнит менеджер.", "cart-total-note"));
      if (failed) { const retry = quantityButton("Повторить загрузку цен", "Повторить загрузку цен", () => update(true)); retry.className = "cart-retry"; totals.append(retry); }
    }
  }
  async function loadPrice(item, signal, warehouseData) {
    try {
      if (item.warehouseSelection) {
        if (item.selectionInvalid) return {};
        const data = await warehouseData;
        return { offer: data?.[item.productId]?.offers?.find((offer) => offer.offer_id === item.offerId) || null };
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
      const ids = [...new Set(items.filter((item) => item.warehouseSelection && !item.selectionInvalid).map((item) => item.productId))];
      const query = new URLSearchParams(); for (const id of ids) query.append("ids[]", id);
      const warehouseData = ids.length ? fetch("/api/warehouse-offers?" + query, { signal, cache: "no-store" }).then(async (response) => { if (!response.ok) throw new Error("Unavailable"); return (await response.json()).data; }).catch(() => null) : Promise.resolve({});
      const queue = [...new Map(items.map((item) => [lineKey(item), item])).values()]; let index = 0;
      async function worker() {
        while (!signal.aborted && index < queue.length) {
          const item = queue[index++]; const entry = await loadPrice(item, signal, warehouseData);
          if (item.warehouseSelection && !item.selectionInvalid && await warehouseData === null) entry.failed = true;
          if (!signal.aborted) entries.set(lineKey(item), entry);
        }
      }
      void Promise.all(Array.from({ length: Math.min(queue.length, 4) }, worker)).then(() => { if (!signal.aborted) render(); });
    }
    render();
  }
  onCartChange(() => update());
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

function setupLead(form) {
  const type = form.dataset.lead;
  const rfq = type === "request";
  const draftKey = "smtechno-form-draft:" + (rfq ? type : type + ":" + location.pathname);
  const legacyPendingKey = "smtechno-pending-lead:" + type;
  const submit = form.querySelector("button[type=submit]");
  const progress = form.querySelector("[data-lead-loading]");
  const empty = form.querySelector("[data-cart-required]");
  const result = form.querySelector("[data-lead-result]");
  let sending = false;

  const readFields = () => Object.fromEntries(names.map((name) => [name, form.elements.namedItem(name).value]));
  const draft = parseSession(draftKey);
  for (const name of names) {
    const field = form.elements.namedItem(name);
    if (!field.value && typeof draft[name] === "string") field.value = draft[name];
  }
  function sync() {
    const required = rfq && readCart().length === 0;
    if (empty) empty.hidden = !required;
    submit.disabled = sending || required;
  }
  onCartChange(sync);

  function show(message, error) {
    result.textContent = message;
    result.setAttribute("role", error ? "alert" : "status");
    result.classList.toggle("error", error);
    result.hidden = message === "";
  }
  function fieldError(field, message) {
    const error = document.getElementById(type + "-" + field.name + "-error");
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
    if (sending) return;
    const items = rfq ? readCart() : [];
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
    const note = [company.trim() && "Компания: " + company.trim(), message.trim()].filter(Boolean).join("\n\n");
    if (note.length > 3000 || location.href.length > 1000) {
      show(note.length > 3000 ? "Сократите компанию и комментарий до 3 000 символов вместе." : "Откройте страницу без лишних параметров и повторите отправку.", true);
      return;
    }
    const request_items = items.map(({ article, quantity, productId, offerId, offerVersion }) => ({ article, quantity, ...(productId ? { product_id: productId } : {}), ...(offerId ? { offer_id: offerId, offer_version: offerVersion } : {}) }));
    const page_url = location.href;
    // Preserve the original field order for pending hashes stored before migration.
    const payload = rfq
      ? { name, phone, email, company, message, request_items, page_url }
      : { company, name, phone, email, message, request_items, page_url };
    if (type === "quick") payload.intent = "quick";
    const website = form.elements.namedItem("website").value;
    const savedDraft = readSession(draftKey);
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
        show(typeof body?.error === "string" && body.error ? body.error : "Не удалось отправить заявку. Попробуйте ещё раз.", true);
        return;
      }
      const id = body?.id;
      if (!((typeof id === "string" && id.trim()) || (Number.isSafeInteger(id) && id > 0)) || typeof body.replayed !== "boolean") {
        show("Сервис вернул некорректное подтверждение заявки. Попробуйте ещё раз.", true);
        return;
      }
      if (rfq && !acknowledgeCart(items)) {
        show("Заявка принята. Номер: " + id + ". Хранилище браузера недоступно: корзина сохранена в этой вкладке, повторная отправка вернёт тот же номер.", false);
        return;
      }
      clearSession(pendingKey, pending);
      if (names.every((name) => form.elements.namedItem(name).value === original[name])) form.reset();
      clearSession(draftKey, savedDraft);
      show((rfq ? "Заявка принята. Номер: " : type === "quick" ? "Запрос принят: " : "Заявка принята: ") + id + ". Следующий шаг: менеджер свяжется с вами.", false);
    } catch {
      show("Не удалось получить подтверждение. Попробуйте отправить заявку ещё раз.", true);
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

const cart = document.querySelector("[data-cart]");
if (cart) setupCart(cart);
for (const form of document.querySelectorAll("[data-lead]")) setupLead(form);
