export const CART_KEY = "smtechno-request";
export const CART_EVENT = "request-updated";
export const MAX_ITEMS = 100;
export const MAX_QUANTITY = 100_000;
export const MAX_ARTICLE_LENGTH = 128;
const UNKNOWN_BRAND = "Не указан";
let cart = [];
let persistent = true;

export const articleKey = (value) => value.trim().toUpperCase().replace(/[^A-Z0-9А-ЯЁ]+/g, "");
const brandKey = (value) => value.trim().toLocaleLowerCase("ru");
export const hasBrand = (value) => Boolean(value.trim()) && brandKey(value) !== brandKey(UNKNOWN_BRAND);
export const lineKey = (item) => JSON.stringify([articleKey(item.article), brandKey(item.brand)]);
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
    }))
    .filter((item) => articleKey(item.article) && [...item.article].length <= MAX_ARTICLE_LENGTH)
    .slice(0, limit);
}

export function readCart() {
  if (persistent) {
    try {
      cart = normalize(JSON.parse(localStorage.getItem(CART_KEY) || "[]"));
    } catch {
      // Last readable copy remains usable if this browser denies storage.
    }
  }
  return cart.map((item) => ({ ...item }));
}

export function writeCart(items) {
  cart = normalize(items);
  if (persistent) {
    try {
      localStorage.setItem(CART_KEY, JSON.stringify(cart));
    } catch {
      persistent = false;
    }
  }
  window.dispatchEvent(new Event(CART_EVENT));
}

export function mergeCart(incoming) {
  const current = readCart();
  const result = current.map((item) => ({ ...item }));
  let overflow = false;
  for (const item of normalize(incoming, incoming.length)) {
    const sameArticle = result.filter((candidate) => articleKey(candidate.article) === articleKey(item.article));
    const existing = hasBrand(item.brand)
      ? sameArticle.find((candidate) => brandKey(candidate.brand) === brandKey(item.brand))
      : sameArticle.length === 1 ? sameArticle[0] : sameArticle.find((candidate) => !hasBrand(candidate.brand));
    if (existing) {
      existing.quantity = normalizeQuantity(existing.quantity + item.quantity);
    } else if (result.length < MAX_ITEMS) {
      result.push(item);
    } else {
      overflow = true;
    }
  }
  writeCart(result);
  return {
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
  writeCart(items);
}

export function onCartChange(update) {
  window.addEventListener(CART_EVENT, update);
  window.addEventListener("storage", (event) => {
    if (event.key === CART_KEY || event.key === null) update();
  });
  window.addEventListener("pageshow", update);
  update();
}
