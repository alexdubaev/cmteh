import { MAX_ARTICLE_LENGTH, articleKey, mergeCart, selectedLine, withCartWrite } from "../cart.mjs?v=20261117-2";

const bound = new WeakSet();
const feedbackTimers = new WeakMap();
const filterLayout = new WeakMap();
const mobileCatalog = matchMedia("(max-width: 820px)");
let activeFilters = [];
let activePhotos = [];

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
  button.addEventListener("click", async () => {
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
    const result = await withCartWrite(() => mergeCart([line]));
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

function fitProductPhoto(image) {
  if (!mobileCatalog.matches) return;
  image.style.removeProperty("--v2-photo-origin");
  image.style.removeProperty("--v2-photo-transform");
  const { naturalWidth, naturalHeight } = image;
  if (!image.isConnected || !image.complete || !naturalWidth || !naturalHeight
      || !image.currentSrc.startsWith(location.origin + "/")) return;

  const sampleSize = 96;
  const longest = Math.max(naturalWidth, naturalHeight);
  const canvas = document.createElement("canvas");
  const width = canvas.width = Math.max(1, Math.round(sampleSize * naturalWidth / longest));
  const height = canvas.height = Math.max(1, Math.round(sampleSize * naturalHeight / longest));
  const sample = canvas.getContext("2d", { willReadFrequently: true });
  if (!sample) return;
  let pixels;
  try {
    sample.drawImage(image, 0, 0, width, height);
    pixels = sample.getImageData(0, 0, width, height).data;
  } catch { return; } // An inaccessible or undecodable image keeps its default framing.

  let left = width, top = height, right = -1, bottom = -1;
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      if (pixels[(y * width + x) * 4 + 3] <= 8) continue;
      left = Math.min(left, x);
      top = Math.min(top, y);
      right = Math.max(right, x);
      bottom = Math.max(bottom, y);
    }
  }
  // Reframe only an object surrounded by transparent margins.
  if (right < left || left === 0 || top === 0 || right === width - 1 || bottom === height - 1) return;
  const scale = Math.min(3, .82 * sampleSize / Math.max(right - left + 1, bottom - top + 1));
  if (scale < 1.1) return;
  const x = 50 + 50 * (left + right + 1 - width) / sampleSize;
  const y = 50 + 50 * (top + bottom + 1 - height) / sampleSize;
  image.style.setProperty("--v2-photo-origin", `${x}% ${y}%`);
  image.style.setProperty("--v2-photo-transform", `translate(${50 - x}%, ${50 - y}%) scale(${scale})`);
}

function bindProductPhoto(image) {
  const fit = () => requestAnimationFrame(() => fitProductPhoto(image));
  if (!bound.has(image)) {
    bound.add(image);
    image.addEventListener("load", fit);
    image.addEventListener("error", fit);
  }
  if (image.complete) fit();
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
function syncViewLayout() {
  syncFilters();
  if (mobileCatalog.matches) activePhotos.forEach(bindProductPhoto);
}
mobileCatalog.addEventListener("change", syncViewLayout);

function within(root, selector) {
  const nodes = [...root.querySelectorAll(selector)];
  if (root.matches?.(selector)) nodes.unshift(root);
  return nodes;
}

/** Bind shared storefront behavior on a freshly adopted V2 page fragment. */
export function activateView(root, { bindControls = true } = {}) {
  if (!root?.querySelectorAll) return;
  activeFilters = within(root, ".filter-disclosure, .v2-category-disclosure");
  activePhotos = within(root, ".v2-product-image-slot > img");
  syncViewLayout();
  if (!bindControls) return;
  for (const button of within(root, "[data-add-article]")) bindAddToCart(button);
  for (const button of within(root, "[data-gallery-src]")) bindGallery(button);
  for (const button of within(root, "[data-copy]")) bindCopy(button);
}
