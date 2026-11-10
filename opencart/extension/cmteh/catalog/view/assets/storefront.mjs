import { MAX_ARTICLE_LENGTH, articleKey, mergeCart, onCartChange, readCart } from "./cart.mjs?v=20261110-8";

function showCartCount() {
  const count = readCart().reduce((sum, item) => sum + item.quantity, 0);
  for (const badge of document.querySelectorAll("[data-cart-count]")) {
    badge.hidden = count === 0;
    badge.textContent = count;
  }
  for (const link of document.querySelectorAll("[data-cart-link]")) {
    link.setAttribute("aria-label", link.dataset.cartLink + (count ? ", позиций: " + count : ""));
  }
}
onCartChange(showCartCount);

const timers = new WeakMap();
function temporaryFeedback(button, label, message) {
  const announcement = button.nextElementSibling;
  if (announcement?.classList.contains("request-announcement")) announcement.textContent = message;
  button.textContent = label;
  clearTimeout(timers.get(button));
  timers.set(button, setTimeout(() => {
    button.textContent = "В корзину";
    if (announcement?.classList.contains("request-announcement")) announcement.textContent = "";
  }, 1700));
}

for (const button of document.querySelectorAll("[data-add-article]")) {
  button.addEventListener("click", () => {
    const { addArticle: article, title, brand, productId } = button.dataset;
    if (!productId || !articleKey(article) || [...article].length > MAX_ARTICLE_LENGTH) {
      temporaryFeedback(button, "Не добавлено", "Некорректный артикул. Выберите товар в каталоге.");
      return;
    }
    const result = mergeCart([{ article, title, brand, productId, quantity: 1 }]);
    if (result.overflow) {
      temporaryFeedback(button, "Корзина заполнена", "Достигнут лимит 100 позиций.");
    } else {
      temporaryFeedback(button, "В корзине ✓", article + ": добавлено в корзину");
    }
  });
}

function focusSearch() {
  const input = document.querySelector("#header-article-search");
  if (!input) return;
  input.focus({ preventScroll: true });
  const reduced = matchMedia("(prefers-reduced-motion: reduce)").matches;
  input.closest("#article-search")?.scrollIntoView({ block: "center", behavior: reduced ? "auto" : "smooth" });
}
for (const trigger of document.querySelectorAll("[data-focus-search]")) {
  trigger.addEventListener("click", (event) => {
    event.preventDefault();
    focusSearch();
  });
}

function setupMenu() {
  const menu = document.querySelector(".catalog-menu");
  const button = document.querySelector(".menu-toggle");
  const navigation = document.querySelector("#main-navigation");
  if (!menu || !button || !navigation) return;

  function toggle(open, focus = false) {
    if (open) menu.open = false;
    navigation.classList.toggle("is-open", open);
    button.setAttribute("aria-expanded", String(open));
    button.setAttribute("aria-label", open ? "Закрыть меню" : "Открыть меню");
    if (focus) requestAnimationFrame(() => (open ? navigation.querySelector("a") : button)?.focus());
  }
  button.addEventListener("click", () => toggle(!navigation.classList.contains("is-open"), true));
  document.addEventListener("pointerdown", (event) => {
    if (!menu.contains(event.target)) menu.open = false;
    if (!navigation.contains(event.target) && !button.contains(event.target)) toggle(false);
  });
  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape" || event.defaultPrevented) return;
    if (navigation.classList.contains("is-open")) {
      toggle(false, true);
    } else if (menu.open) {
      menu.open = false;
      menu.querySelector("summary")?.focus();
    }
  });
  menu.addEventListener("toggle", () => {
    if (menu.open) toggle(false);
  });
}
setupMenu();

const fieldSelector = 'input:not([type="hidden"]), select, textarea, [contenteditable="true"]';
const mobileActions = document.querySelector(".mobile-actions");
if (mobileActions) {
  const update = () => mobileActions.classList.toggle("is-field-focused", Boolean(document.activeElement?.matches(fieldSelector)));
  document.addEventListener("focusin", update);
  document.addEventListener("focusout", () => requestAnimationFrame(update));
}

const filter = document.querySelector(".filter-disclosure");
if (filter) {
  const mobile = matchMedia("(max-width: 820px)");
  const sync = () => { filter.open = !mobile.matches; };
  sync();
  mobile.addEventListener("change", sync);
}

for (const button of document.querySelectorAll("[data-gallery-src]")) {
  button.addEventListener("click", () => {
    const gallery = button.closest(".product-gallery");
    const image = gallery.querySelector(".product-main-image");
    image.src = button.dataset.gallerySrc;
    image.alt = button.querySelector("img").alt;
    for (const sibling of gallery.querySelectorAll("[data-gallery-src]")) {
      sibling.classList.toggle("active", sibling === button);
      sibling.setAttribute("aria-pressed", String(sibling === button));
    }
  });
}

for (const button of document.querySelectorAll("[data-copy]")) {
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
    clearTimeout(timers.get(button));
    timers.set(button, setTimeout(() => { feedback.textContent = ""; }, 2200));
  });
}
document.querySelector("[data-reload]")?.addEventListener("click", () => location.reload());

const quickDialog = document.querySelector("[data-quick-dialog]");
let quickTrigger;
for (const trigger of document.querySelectorAll("[data-open-quick]")) {
  trigger.addEventListener("click", (event) => {
    if (!quickDialog?.showModal) return;
    event.preventDefault();
    quickTrigger = trigger;
    quickDialog.showModal();
    quickDialog.querySelector('[name="name"]')?.focus({ preventScroll: true });
  });
}
document.querySelector("[data-close-quick]")?.addEventListener("click", () => quickDialog?.close());
quickDialog?.addEventListener("click", (event) => {
  if (event.target === quickDialog) quickDialog.close();
});
quickDialog?.addEventListener("close", () => {
  // The opener must be visible before focus can leave a validated dialog field.
  mobileActions?.classList.remove("is-field-focused");
  quickTrigger?.focus({ preventScroll: true });
});

const backToTop = document.querySelector("[data-back-to-top]");
if (backToTop) {
  const visibleSubmits = new Set();
  const updateBackToTop = () => { backToTop.hidden = window.scrollY < 360 || visibleSubmits.size > 0; };
  window.addEventListener("scroll", updateBackToTop, { passive: true });
  updateBackToTop();
  if ("IntersectionObserver" in window) {
    const submitObserver = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) visibleSubmits.add(entry.target);
        else visibleSubmits.delete(entry.target);
      }
      updateBackToTop();
    }, { threshold: 0.1 });
    for (const submit of document.querySelectorAll(".lead-form button[type=submit]")) submitObserver.observe(submit);
  }
  backToTop.addEventListener("click", (event) => {
    event.preventDefault();
    const reduced = matchMedia("(prefers-reduced-motion: reduce)").matches;
    window.scrollTo({ top: 0, behavior: reduced ? "auto" : "smooth" });
    document.querySelector("#site-header")?.focus({ preventScroll: true });
  });
}

const HISTORY_KEY = "smtechno-search-history";
function historyItems() {
  try {
    const history = JSON.parse(localStorage.getItem(HISTORY_KEY) || "[]");
    return Array.isArray(history) ? history.filter((item) => typeof item === "string" && item.length <= 64).slice(0, 6) : [];
  } catch {
    return [];
  }
}
function remember(query) {
  if (!query || query.length > 64) return;
  const items = [query, ...historyItems().filter((item) => item.toLowerCase() !== query.toLowerCase())].slice(0, 6);
  try {
    localStorage.setItem(HISTORY_KEY, JSON.stringify(items));
  } catch {
    // Search works with private browsing settings that disable storage.
  }
}

function setupSearch() {
  const input = document.querySelector("#header-article-search");
  if (!input) return;
  const form = input.form;
  const panel = input.closest(".search-wrap").querySelector(".search-popover");
  const list = panel.querySelector("[role=listbox]");
  const label = panel.querySelector(".search-popover-title");
  const showAll = panel.querySelector(".search-all");
  let timer;
  let controller;
  let active = -1;
  let engaged = false;
  let options = [];

  function close() {
    panel.hidden = true;
    input.setAttribute("aria-expanded", "false");
    input.removeAttribute("aria-activedescendant");
    options = [];
    list.replaceChildren();
    active = -1;
  }
  function go(value) {
    const query = value.trim();
    remember(query);
    location.assign(query ? "/catalog?q=" + encodeURIComponent(query) : "/catalog");
  }
  function show(values, recent) {
    close();
    if (!engaged || !values.length) return;
    const title = recent ? "Недавние запросы" : "Подсказки";
    label.textContent = title;
    list.setAttribute("aria-label", recent ? title : "Поисковые подсказки");
    for (const value of values) {
      const option = document.createElement(recent ? "button" : "a");
      option.className = recent ? "history-item" : "suggestion-item";
      if (recent) {
        option.type = "button";
        const icon = document.createElement("span");
        icon.textContent = "↺";
        const text = document.createElement("strong");
        text.textContent = value;
        option.append(icon, text);
        option.addEventListener("click", () => go(value));
      } else {
        option.href = "/product/" + encodeURIComponent(value.slug);
        const code = document.createElement("span");
        code.className = "suggestion-code";
        code.textContent = value.sku;
        const text = document.createElement("span");
        const title = document.createElement("strong");
        const brand = document.createElement("small");
        title.textContent = value.title;
        brand.textContent = value.brand;
        text.append(title, brand);
        option.append(code, text);
      }
      option.id = "search-option-" + options.length;
      option.tabIndex = -1;
      option.setAttribute("role", "option");
      option.setAttribute("aria-selected", "false");
      option.addEventListener("pointerdown", (event) => event.preventDefault());
      options.push(option);
      list.append(option);
    }
    showAll.hidden = recent;
    panel.hidden = false;
    input.setAttribute("aria-expanded", "true");
  }
  function refresh() {
    close();
    clearTimeout(timer);
    controller?.abort();
    if (!engaged) return;
    const value = input.value.trim();
    if (!value) {
      show(historyItems(), true);
      return;
    }
    if (value.length < 2 || value.length > 64) return;
    const request = new AbortController();
    controller = request;
    timer = setTimeout(async () => {
      try {
        const response = await fetch("/api/search?q=" + encodeURIComponent(value) + "&mode=suggestions", { signal: request.signal });
        if (!response.ok) return;
        const result = await response.json();
        if (!request.signal.aborted && engaged && input.value.trim() === value && Array.isArray(result.data)) {
          show(result.data.filter((item) => item && typeof item.slug === "string").slice(0, 6), false);
        }
      } catch {
        if (!request.signal.aborted) close();
      }
    }, 180);
  }
  input.addEventListener("focus", () => { engaged = true; refresh(); });
  input.addEventListener("input", () => { engaged = true; refresh(); });
  input.addEventListener("blur", () => { engaged = false; refresh(); });
  input.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !panel.hidden) {
      event.preventDefault();
      engaged = false;
      refresh();
      return;
    }
    if ((event.key === "ArrowDown" || event.key === "ArrowUp") && options.length) {
      event.preventDefault();
      const direction = event.key === "ArrowDown" ? 1 : -1;
      active = active < 0 ? (direction === 1 ? 0 : options.length - 1) : (active + direction + options.length) % options.length;
      options.forEach((option, index) => option.setAttribute("aria-selected", String(index === active)));
      input.setAttribute("aria-activedescendant", options[active].id);
      options[active].scrollIntoView({ block: "nearest" });
    }
    if (event.key === "Enter" && active >= 0) {
      event.preventDefault();
      options[active]?.click();
    }
  });
  showAll.addEventListener("pointerdown", (event) => event.preventDefault());
  showAll.addEventListener("click", () => go(input.value));
  form.addEventListener("submit", (event) => { event.preventDefault(); go(input.value); });
}
setupSearch();

// Keep old deep links usable without treating pasted rows as chosen catalogue products.
if (location.pathname === '/request' && (location.hash === '#import' || location.hash === '#request-import' || new URLSearchParams(location.search).has('import'))) {
  const link = document.querySelector('#import a');
  link?.scrollIntoView({ block: 'center' });
}
