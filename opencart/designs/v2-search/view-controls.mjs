import { MAX_ARTICLE_LENGTH, articleKey, mergeCart, selectedLine, withCartWrite } from "../cart.mjs?v=20261117-2";

const bound = new WeakSet();
const feedbackTimers = new WeakMap();
const filterLayout = new WeakMap();
const mobileCatalog = matchMedia("(max-width: 820px)");
let activeFilters = [];
let activePhotos = [];
let openViewer;

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
  const fit = () => requestAnimationFrame(() => {
    if (mobileCatalog.matches) fitProductPhoto(image);
  });
  if (!bound.has(image)) {
    bound.add(image);
    image.addEventListener("load", fit);
    image.addEventListener("error", fit);
  }
  if (image.complete) fit();
}

function bindPhotoViewer(gallery) {
  if (bound.has(gallery)) return;
  const dialog = gallery.querySelector(".v2-photo-viewer");
  const photos = JSON.parse(gallery.dataset.v2PhotoGallery).flatMap((photo) => {
    try {
      const url = new URL(photo.url, location.href);
      return ["http:", "https:"].includes(url.protocol) ? [{ ...photo, url: url.href }] : [];
    } catch { return []; }
  });
  if (!photos.length) return;
  const opener = gallery.querySelector("[data-photo-open]");
  const preview = opener.querySelector("img");
  bound.add(gallery);
  gallery.addEventListener("click", (event) => {
    const thumb = event.target.closest("[data-gallery-src]");
    const photo = thumb && photos.find((item) => item.url === thumb.querySelector("img")?.src);
    if (photo) opener.href = photo.url;
  });
  if (typeof dialog?.showModal !== "function") return;
  const image = dialog.querySelector("[data-photo-full]");
  const original = dialog.querySelector("[data-photo-original]");
  const count = dialog.querySelector("[data-photo-count]");
  const thumbnails = [...gallery.querySelectorAll("[data-gallery-src]")];
  let position = 0;
  let touchStart;
  opener.setAttribute("aria-haspopup", "dialog");

  function show(offset) {
    position = (position + offset + photos.length) % photos.length;
    const photo = photos[position];
    image.style.removeProperty("--v2-photo-transform");
    image.alt = photo.alt_text || preview.alt;
    image.src = photo.url;
    original.href = photo.url;
    if (count) count.textContent = (position + 1) + " / " + photos.length;
    if (image.complete) fitProductPhoto(image);
  }

  opener.addEventListener("click", (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (dialog.open) return;
    position = Math.max(photos.findIndex((photo) => photo.url === preview.src), 0);
    show(0);
    dialog.showModal();
    openViewer = dialog;
  });
  dialog.querySelector("[data-photo-close]").addEventListener("click", () => dialog.close());
  dialog.querySelector("[data-photo-previous]")?.addEventListener("click", () => show(-1));
  dialog.querySelector("[data-photo-next]")?.addEventListener("click", () => show(1));
  image.addEventListener("load", () => fitProductPhoto(image));
  dialog.addEventListener("close", () => {
    if (dialog.open) return;
    if (openViewer === dialog) openViewer = undefined;
    if (!opener.isConnected) return;
    const photo = photos[position];
    preview.alt = image.alt;
    preview.src = photo.url;
    opener.href = photo.url;
    for (const thumb of thumbnails) {
      const selected = thumb.querySelector("img")?.src === photo.url;
      thumb.classList.toggle("active", selected);
      thumb.setAttribute("aria-pressed", String(selected));
    }
    opener.focus({ preventScroll: true });
  });
  if (photos.length < 2) return;
  dialog.addEventListener("keydown", (event) => {
    if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
    event.preventDefault();
    show(event.key === "ArrowLeft" ? -1 : 1);
  });
  const stage = image.parentElement;
  stage.addEventListener("touchstart", (event) => {
    touchStart = event.touches.length === 1 ? event.touches[0] : undefined;
  }, { passive: true });
  stage.addEventListener("touchcancel", () => { touchStart = undefined; }, { passive: true });
  stage.addEventListener("touchend", (event) => {
    const start = touchStart;
    touchStart = undefined;
    const end = event.changedTouches[0];
    if (!start || !end || start.identifier !== end.identifier || event.touches.length) return;
    const x = end.clientX - start.clientX;
    const y = end.clientY - start.clientY;
    if (Math.abs(x) >= 45 && Math.abs(x) > 1.4 * Math.abs(y)) show(x < 0 ? 1 : -1);
  }, { passive: true });
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
  activePhotos = within(root, ".v2-product-image-slot .product-main-image");
  if (openViewer && !root.contains(openViewer)) {
    openViewer.close();
    openViewer = undefined;
  }
  for (const gallery of within(root, "[data-v2-photo-gallery]")) bindPhotoViewer(gallery);
  syncViewLayout();
  if (!bindControls) return;
  for (const button of within(root, "[data-add-article]")) bindAddToCart(button);
  for (const button of within(root, "[data-gallery-src]")) bindGallery(button);
  for (const button of within(root, "[data-copy]")) bindCopy(button);
}
