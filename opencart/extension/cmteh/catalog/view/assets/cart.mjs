export const CART_KEY = "smtechno-request";
export const WAREHOUSE_CART_KEY = "smtechno-warehouse-request-v1";
export const CART_EVENT = "request-updated";
export const MAX_ITEMS = 100;
export const MAX_QUANTITY = 100_000;
export const MAX_ARTICLE_LENGTH = 128;
const UNKNOWN_BRAND = "Не указан";
let cart = [];
let warehouseCart = [];
let persistent = true;

export const articleKey = (value) => value.trim().toUpperCase().replace(/[^A-Z0-9А-ЯЁ]+/g, "");
const brandKey = (value) => value.trim().toLocaleLowerCase("ru");
export const hasBrand = (value) => Boolean(value.trim()) && brandKey(value) !== brandKey(UNKNOWN_BRAND);
export const lineKey = (item) => JSON.stringify(item.warehouseSelection || item.offerId ? ["warehouse", item.productId, item.offerId, item.offerVersion] : item.productId ? ["product", item.productId] : [articleKey(item.article), brandKey(item.brand)]);
export function normalizeQuantity(value) {
  const number = Number(value);
  return Number.isFinite(number) ? Math.max(1, Math.min(MAX_QUANTITY, Math.floor(number))) : 1;
}

function normalize(items, limit = MAX_ITEMS) {
  if (!Array.isArray(items)) return [];
  return items.filter((item) => item && typeof item === "object" && ["string", "number"].includes(typeof item.article))
    .map((item) => ({
      article: String(item.article).trim(),
      title: String(item.title ?? "Позиция из списка"),
      brand: String(item.brand ?? UNKNOWN_BRAND),
      quantity: normalizeQuantity(item.quantity),
      ...(typeof item.productId === "string" && item.productId.trim() ? { productId: item.productId.trim() } : {}),
    }))
    .filter((item) => articleKey(item.article) && [...item.article].length <= MAX_ARTICLE_LENGTH)
    .slice(0, limit);
}

function normalizeWarehouse(items) {
  if (!Array.isArray(items)) return [];
  return items.filter((item) => item && typeof item === "object").map((item) => {
    const base = normalize([{ ...item, article: item.article || "[Артикул недоступен]" }])[0] || { article: "[Артикул недоступен]", brand: UNKNOWN_BRAND, title: "Выберите товар заново", quantity: 1 };
    const text = (key, max) => typeof item[key] === "string" ? item[key].slice(0, max) : "";
    const offerId = text("offerId", 40);
    const offerVersion = text("offerVersion", 64);
    return { ...base, warehouseSelection: true, offerId, offerVersion,
      warehouseName: text("warehouseName", 160), warehouseCode: text("warehouseCode", 64), warehouseCity: text("warehouseCity", 160),
      deliveryTerm: text("deliveryTerm", 255), price: typeof item.price === "string" && /^\d{1,13}\.\d{2}$/.test(item.price) ? item.price : null, currency: "RUB",
      selectionInvalid: !base.productId || !/^[1-9]\d{0,19}$/.test(offerId) || !/^[a-f0-9]{64}$/.test(offerVersion) };
  }).slice(0, MAX_ITEMS);
}

export function selectedLine(product, offer, quantity = 1) {
  return { article: product.sku, title: product.title, brand: product.brand, productId: product.id, quantity,
    offerId: offer.offer_id, offerVersion: offer.offer_version, warehouseName: offer.warehouse.name,
    warehouseCode: offer.warehouse.code, warehouseCity: offer.warehouse.city, deliveryTerm: offer.delivery_term,
    price: offer.price, currency: offer.currency, warehouseSelection: true };
}

export function readCart() {
  if (persistent) {
    try {
      const legacy = JSON.parse(localStorage.getItem(CART_KEY) || "[]");
      const selected = JSON.parse(localStorage.getItem(WAREHOUSE_CART_KEY) || "[]");
      cart = normalize(legacy);
      warehouseCart = normalizeWarehouse(selected);
    } catch { persistent = false; }
  }
  return [...cart, ...warehouseCart].map((item) => ({ ...item }));
}

export function writeCart(items, acknowledgement = false) {
  const legacy = normalize(items.filter((item) => !item.offerId && !item.warehouseSelection));
  const selected = normalizeWarehouse(items.filter((item) => item.offerId || item.warehouseSelection));
  if (acknowledgement && !persistent) return false;
  if (persistent) {
    const previousLegacy = JSON.stringify(cart), previousSelected = JSON.stringify(warehouseCart);
    try {
      localStorage.setItem(WAREHOUSE_CART_KEY, JSON.stringify(selected));
      localStorage.setItem(CART_KEY, JSON.stringify(legacy));
    } catch {
      try { localStorage.setItem(WAREHOUSE_CART_KEY, previousSelected); localStorage.setItem(CART_KEY, previousLegacy); } catch { /* Keep the current tab's recovery copy. */ }
      persistent = false;
      if (acknowledgement) return false;
    }
  }
  cart = legacy;
  warehouseCart = selected;
  window.dispatchEvent(new Event(CART_EVENT));
  return persistent;
}

export function mergeCart(incoming) {
  const current = readCart();
  const result = current.map((item) => ({ ...item }));
  let overflow = false;
  let requiresReview = false;
  const normalized = incoming.flatMap((item) => item.offerId || item.warehouseSelection ? normalizeWarehouse([item]) : normalize([item]));
  for (const item of normalized) {
    const sameArticle = result.filter((candidate) => articleKey(candidate.article) === articleKey(item.article));
    const selected = item.warehouseSelection;
    const sameOffer = selected && result.find((candidate) => candidate.warehouseSelection && candidate.productId === item.productId && candidate.offerId === item.offerId);
    if (sameOffer && sameOffer.offerVersion !== item.offerVersion) { requiresReview = true; continue; }
    const existing = selected ? sameOffer : item.productId
      ? result.find((candidate) => !candidate.warehouseSelection && candidate.productId === item.productId)
      : hasBrand(item.brand)
      ? sameArticle.find((candidate) => !candidate.productId && brandKey(candidate.brand) === brandKey(item.brand))
      : sameArticle.filter((candidate) => !candidate.productId).length === 1 ? sameArticle.find((candidate) => !candidate.productId) : sameArticle.find((candidate) => !candidate.productId && !hasBrand(candidate.brand));
    if (existing) {
      existing.quantity = normalizeQuantity(existing.quantity + item.quantity);
    } else if (result.length < MAX_ITEMS) {
      result.push(item);
    } else {
      overflow = true;
    }
  }
  const persisted = writeCart(result);
  return {
    persisted, requiresReview,
    added: result.length - current.length,
    updated: current.filter((item, index) => item.quantity !== result[index].quantity).length,
    total: result.length,
    overflow,
  };
}

export function acknowledgeCart(submitted) {
  const remaining = new Map();
  for (const item of submitted) {
    const key = lineKey(item);
    remaining.set(key, (remaining.get(key) ?? 0) + item.quantity);
  }
  const items = readCart().flatMap((item) => {
    const key = lineKey(item);
    const available = remaining.get(key) ?? 0;
    const removed = Math.min(item.quantity, available);
    remaining.set(key, available - removed);
    return item.quantity > removed ? [{ ...item, quantity: item.quantity - removed }] : [];
  });
  return writeCart(items, true);
}

export function onCartChange(update) {
  window.addEventListener(CART_EVENT, update);
  window.addEventListener("storage", (event) => {
    if (event.key === CART_KEY || event.key === WAREHOUSE_CART_KEY || event.key === null) update();
  });
  window.addEventListener("pageshow", update);
  update();
}
