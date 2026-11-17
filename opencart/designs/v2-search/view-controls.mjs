import { MAX_ARTICLE_LENGTH, articleKey, mergeCart, selectedLine } from "../cart.mjs?v=20261111-1";

const bound = new WeakSet();
const feedbackTimers = new WeakMap();
const filterLayout = new WeakMap();
const mobileCatalog = matchMedia("(max-width: 820px)");
let activeFilters = [];

function temporaryFeedback(button, label, message) {
  const announcement = button.nextElementSibling;
  if (announcement?.classList.contains("request-announcement")) announcement.textContent = message;
  button.textContent = label;
  clearTimeout(feedbackTimers.get(button));
  feedbackTimers.set(button, setTimeout(() => {
    button.textContent = "В корзину";
    if (announcement?.classList.contains("request-announcement")) announcement.textContent = "";
  }, 1700));
}

function bindAddToCart(button) {
  if (bound.has(button)) return;
  bound.add(button);
  button.addEventListener("click", () => {
    const { addArticle: article, title, brand, productId } = button.dataset;
    if (!productId || !articleKey(article) || [...article].length > MAX_ARTICLE_LENGTH) {
      temporaryFeedback(button, "Не добавлено", "Некорректный артикул. Выберите товар в каталоге.");
      return;
    }

    const data = button.dataset;
    const line = data.offerId ? selectedLine({ id: productId, sku: article, title, brand }, {
      offer_id: data.offerId,
      offer_version: data.offerVersion,
      warehouse: { name: data.warehouseName, code: data.warehouseCode, city: data.warehouseCity },
      delivery_term: data.deliveryTerm,
      price: data.price || null,
      currency: "RUB",
    }) : { article, title, brand, productId, quantity: 1 };
    const result = mergeCart([line]);
    if (result.requiresReview) {
      temporaryFeedback(button, "Проверьте корзину", "Условия склада изменились. Проверьте выбранную строку в корзине.");
    } else if (!result.persisted) {
      temporaryFeedback(button, "Сохранено в этой вкладке", "Хранилище браузера недоступно. Не закрывайте вкладку до отправки.");
    } else if (result.overflow) {
      temporaryFeedback(button, "Корзина заполнена", "Достигнут лимит 100 позиций.");
    } else {
      temporaryFeedback(button, "В корзине ✓", article + ": добавлено в корзину");
    }
  });
}

function bindGallery(button) {
  if (bound.has(button)) return;
  bound.add(button);
  button.addEventListener("click", () => {
    const gallery = button.closest(".product-gallery");
    const image = gallery?.querySelector(".product-main-image");
    const thumbnail = button.querySelector("img");
    if (!gallery || !image || !thumbnail) return;
    image.src = button.dataset.gallerySrc;
    image.alt = thumbnail.alt;
    for (const sibling of gallery.querySelectorAll("[data-gallery-src]")) {
      sibling.classList.toggle("active", sibling === button);
      sibling.setAttribute("aria-pressed", String(sibling === button));
    }
  });
}

function bindCopy(button) {
  if (bound.has(button)) return;
  bound.add(button);
  button.addEventListener("click", async () => {
    const feedback = button.nextElementSibling;
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
      feedback.classList.remove("copy-article-error");
      feedback.textContent = "Артикул скопирован";
    } catch {
      feedback.classList.add("copy-article-error");
      feedback.textContent = "Не удалось скопировать. Выделите артикул и скопируйте вручную.";
    }
    clearTimeout(feedbackTimers.get(button));
    feedbackTimers.set(button, setTimeout(() => { feedback.textContent = ""; }, 2200));
  });
}

function syncFilters() {
  for (const filter of activeFilters) {
    if (filterLayout.get(filter) === mobileCatalog.matches) continue;
    filterLayout.set(filter, mobileCatalog.matches);
    filter.open = !mobileCatalog.matches;
  }
}
mobileCatalog.addEventListener("change", syncFilters);

function within(root, selector) {
  const nodes = [...root.querySelectorAll(selector)];
  if (root.matches?.(selector)) nodes.unshift(root);
  return nodes;
}

/** Bind shared storefront behavior on a freshly adopted V2 page fragment. */
export function activateView(root, { bindControls = true } = {}) {
  if (!root?.querySelectorAll) return;
  activeFilters = within(root, ".filter-disclosure");
  syncFilters();
  if (!bindControls) return;
  for (const button of within(root, "[data-add-article]")) bindAddToCart(button);
  for (const button of within(root, "[data-gallery-src]")) bindGallery(button);
  for (const button of within(root, "[data-copy]")) bindCopy(button);
}
