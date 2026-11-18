import { activateView } from './view-controls.mjs?v=20261117-2';

// A V2 presentation enhancement over normal, server-rendered storefront URLs.
// No response scripts are executed, and commerce uses the existing shared modules.
const main = document.querySelector('#main-content');
const search = document.querySelector('#article-search');
const input = search?.querySelector('input[name=q]');
const dock = document.querySelector('#v2-search-dock');
const status = document.querySelector('#v2-navigation-status');
const headSelector = 'meta[name="description"],meta[name="robots"],link[rel="canonical"],meta[property^="og:"],script[type="application/ld+json"]';
const supported = url => url.origin === location.origin && !url.username && !url.password
  && (url.pathname === '/' || ['/catalog', '/brands', '/articles', '/about', '/delivery', '/payment'].includes(url.pathname)
    || /^\/(?:brand|category|articles)\/[^/]+$/.test(url.pathname)
    || /^\/(?!admin(?:\/|$)|api(?:\/|$)|account(?:\/|$)|request(?:\/|$)|parts-list(?:\/|$)|contacts(?:\/|$)|cart(?:\/|$)|checkout(?:\/|$)|extension(?:\/|$)|image(?:\/|$)|information(?:\/|$)|install(?:\/|$)|product(?:\/|$)|sitemap(?:s)?(?:\/|$)|system(?:\/|$)|_cmteh_platform(?:\/|$))[^/]+$/.test(url.pathname)
    || /^\/product\/[^/]+$/.test(url.pathname)
    // Parameterized links keep the existing server/module prefill and recovery behavior.
    || (url.pathname === '/parts-list' && !url.search));
const pageKind = url => url.pathname === '/' ? 'home' : url.pathname === '/catalog' ? 'catalog'
  : url.pathname === '/brands' ? 'brands' : url.pathname === '/articles' ? 'articles'
    : url.pathname === '/parts-list' ? 'parts-list'
      : /^\/brand\//.test(url.pathname) ? 'brand' : /^\/category\//.test(url.pathname) ? 'category'
        : /^\/articles\//.test(url.pathname) ? 'article'
          : /^\/product\//.test(url.pathname) ? 'product' : 'page';
const routeKey = url => url.pathname + url.search;
const reusableRoute = view => (view.kind === 'home' && routeKey(view.url) === '/')
  || (view.kind === 'parts-list' && routeKey(view.url) === '/parts-list');

if (main && search && input && dock && status && supported(new URL(location.href))) {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const compact = matchMedia('(max-width: 820px), (pointer: coarse)');
  const views = new Map();
  const reusable = new Map();
  const animations = new Set();
  let sequence = 0, pending, loadingTimer, scrollTimer, clearReveal, pointerAction = false;
  const nextId = () => `${Date.now()}-${++sequence}`;
  const metadata = root => [...root.querySelectorAll(headSelector)].map(node => node.cloneNode(true));
  const viewState = view => ({ v2View: view.id, v2Navigation: { query: view.query, scroll: view.scroll, focus: view.focus } });
  const currentState = view => ({ ...(history.state && typeof history.state === 'object' ? history.state : {}), ...viewState(view) });
  let current = {
    id: nextId(), url: new URL(location.href), kind: document.body.dataset.v2Page,
    nodes: [...main.childNodes], title: document.title, head: metadata(document.head),
    query: input.value, scroll: window.scrollY, focus: null, initial: true,
  };
  if (reusableRoute(current)) reusable.set(routeKey(current.url), current);
  history.replaceState(currentState(current), '', location.href);
  history.scrollRestoration = 'manual';
  views.set(current.id, current);
  activateView(main, { bindControls: false });

  // Keep the original form alive, including FileList, results and in-flight work.
  // Its unchanged module owns validation, draft storage and quote idempotency.
  const partsParking = document.createElement('div');
  partsParking.hidden = true;
  partsParking.inert = true;
  main.after(partsParking);
  let partsNodes = current.kind === 'parts-list' ? current.nodes : null;
  let partsReady = partsNodes ? Promise.resolve() : null;
  async function preparePartsList(nodes) {
    if (!partsNodes) {
      partsNodes = nodes;
      // Initialize the clean route using its own prefill, even when the fetch
      // or module import finishes while another URL is still visible.
      partsReady = import('../parts-list.mjs?v=20261117-2').then(({ setupPartsList }) => {
        partsParking.replaceChildren(...partsNodes);
        setupPartsList(partsParking.querySelector('[data-parts-list]'), { prefill: '' });
      });
    }
    await partsReady;
    return partsNodes;
  }

  function rememberQuery(value) {
    if (!value || value.length > 64) return;
    try {
      const key = 'smtechno-search-history';
      const previous = JSON.parse(localStorage.getItem(key) || '[]');
      const items = Array.isArray(previous) ? previous.filter(item => typeof item === 'string' && item.length <= 64) : [];
      localStorage.setItem(key, JSON.stringify([value, ...items.filter(item => item.toLowerCase() !== value.toLowerCase())].slice(0, 6)));
    } catch { /* The same search works without browser storage. */ }
  }

  function saveCurrent() {
    current.query = input.value;
    current.scroll = window.scrollY;
    current.focus = document.activeElement === input ? 'search' : null;
    current.nodes = [...main.childNodes];
    views.set(current.id, current);
    // A popstate has already changed the address: never overwrite its destination entry.
    if (history.state?.v2View === current.id && routeKey(new URL(location.href)) === routeKey(current.url)) {
      history.replaceState(currentState(current), '', location.href);
    }
    // Keep a bounded live-DOM history; older entries can be fetched normally.
    while (views.size > 12) views.delete(views.keys().next().value);
  }

  function stopAnimations() {
    for (const animation of animations) animation.cancel();
    animations.clear();
    clearReveal?.();
  }
  reduced.addEventListener('change', () => { if (reduced.matches) stopAnimations(); });

  function animate(element, frames, options) {
    if (!element.animate || reduced.matches) return;
    const animation = element.animate(frames, options);
    animations.add(animation);
    animation.finished.catch(() => {}).finally(() => animations.delete(animation));
    return animation;
  }

  function playAfterPaint(batch) {
    for (const animation of batch) {
      animation.pause();
      animation.currentTime = 0;
    }
    // A costly first paint should not consume the movement's timeline.
    requestAnimationFrame(() => requestAnimationFrame(() => {
      for (const animation of batch) {
        if (animations.has(animation)) animation.play();
      }
    }));
  }

  function revealFrame(opening, previousSearch, previousHeight, fromHome, bounds, options) {
    const height = main.getBoundingClientRect().height;
    if (!height) return;
    // On larger screens the height participates in flow so the footer follows the sheet.
    const startHeight = opening && fromHome ? 64 : previousHeight;
    const side = opening && fromHome ? Math.max(0, (main.clientWidth - Math.min(previousSearch.width, bounds.width)) / 2) : 0;
    main.style.setProperty('--v2-reveal-height', `${height}px`);
    main.classList.add('v2-expanding');
    const finish = () => {
      if (clearReveal !== finish) return;
      clearReveal = null;
      main.classList.remove('v2-expanding');
      main.style.removeProperty('--v2-reveal-height');
      frame?.cancel();
    };
    clearReveal = finish;
    const frame = animate(main, [
      { height: `${startHeight}px`, clipPath: opening ? `inset(0px ${side}px 0px ${side}px round 16px)` : 'none' },
      { height: `${height}px`, clipPath: opening ? 'inset(0px 0px 0px 0px round 0px)' : 'none' },
    ], { ...options, fill: 'both' });
    frame.finished.then(finish, finish);
  }

  function reveal(view, previousSearch, previousHeight, fromHome) {
    const bounds = search.getBoundingClientRect();
    if (!main.animate || !bounds.width) return;

    const opening = view.kind !== 'home';
    const compactMotion = compact.matches;
    const easing = 'cubic-bezier(.22,1,.36,1)';
    const duration = compactMotion ? 220 : 500;
    // Leave the new page at its final size on touch and narrow screens.
    if (!compactMotion) revealFrame(opening, previousSearch, previousHeight, fromHome, bounds, { duration, easing });

    const searchMotion = bounds.height ? animate(search, [
      { transformOrigin: '0 0', transform: `translate(${previousSearch.left - bounds.left}px, ${previousSearch.top - bounds.top}px) scale(${previousSearch.width / bounds.width},${previousSearch.height / bounds.height})` },
      { transformOrigin: '0 0', transform: 'none' },
    ], { duration, easing }) : null;

    if (compactMotion) {
      // Keep the long content and the backdrop out of the animation.
      const title = main.querySelector('h1');
      const titleMotion = title && animate(title, [{ opacity: 0.65 }, { opacity: 1 }], { duration, easing });
      playAfterPaint([searchMotion, titleMotion].filter(Boolean));
      return;
    }

    const content = opening ? [...main.children] : [...main.querySelector('.v2-home-content').children].filter(node => !node.matches('[data-v2-search-home]'));
    for (const element of content) animate(element,
      [{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
      { duration: 320, delay: 70, fill: 'backwards', easing });
  }

  function clearLoading() {
    clearTimeout(loadingTimer);
    main.removeAttribute('aria-busy');
    main.classList.remove('v2-waiting');
    status.hidden = true;
    status.replaceChildren();
  }

  function cancelNavigation() {
    pending?.request.abort();
    pending = null;
    clearLoading();
  }

  function show(view, { restore = false, motion = false } = {}) {
    const previousBounds = search.getBoundingClientRect();
    const previousHeight = compact.matches ? 0 : main.getBoundingClientRect().height;
    const fromHome = current.kind === 'home';
    input.blur(); // Closes existing suggestions and aborts their outstanding request.
    stopAnimations();
    if (current.kind === 'parts-list') partsParking.replaceChildren(...partsNodes);
    if (view.kind === 'parts-list') view.nodes = partsNodes;
    main.replaceChildren(...view.nodes);
    for (const className of [...document.body.classList]) {
      if (className.startsWith('v2-') && className !== 'v2-storefront') document.body.classList.remove(className);
    }
    document.body.classList.add(`v2-${view.kind}`);
    document.body.dataset.v2Page = view.kind;
    const homeSlot = main.querySelector('[data-v2-search-home]');
    (view.kind === 'home' ? homeSlot : dock).append(search);
    dock.hidden = view.kind === 'home';
    input.value = view.query;
    document.title = view.title;
    document.head.querySelectorAll(headSelector).forEach(node => node.remove());
    document.head.append(...view.head.map(node => node.cloneNode(true)));
    activateView(main, { bindControls: !view.initial });
    const menu = document.querySelector('.catalog-menu');
    if (menu) menu.open = false;
    document.querySelector('.main-nav')?.classList.remove('is-open');
    const menuButton = document.querySelector('.menu-toggle');
    menuButton?.setAttribute('aria-expanded', 'false');
    menuButton?.setAttribute('aria-label', 'Открыть меню');
    clearLoading();
    current = view;
    views.set(view.id, view);
    if (reusableRoute(view)) reusable.set(routeKey(view.url), view);
    window.scrollTo({ top: restore ? view.scroll : 0, behavior: 'instant' });
    if (!restore && view.url.hash) {
      let anchor = view.url.hash.slice(1);
      try { anchor = decodeURIComponent(anchor); } catch { /* Invalid fragments do not break a page. */ }
      document.getElementById(anchor)?.scrollIntoView();
    }
    const target = restore && view.focus === 'search' ? input : main.querySelector('h1') || main;
    if (target !== input) target.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
    if (target === input) input.setSelectionRange(input.value.length, input.value.length);
    // Restored deep scroll positions stay immediate rather than being clipped.
    if (motion && !reduced.matches && (!restore || view.scroll === 0)) reveal(view, previousBounds, previousHeight, fromHome);
    document.dispatchEvent(new CustomEvent('v2:navigated', { detail: { url: view.url.href } }));
  }

  function showFailure(url, options) {
    clearLoading();
    status.textContent = 'Не удалось открыть страницу.';
    const retry = document.createElement('a');
    retry.href = url.href;
    retry.textContent = 'Попробовать ещё раз';
    retry.dataset.v2Native = '';
    if (options.restoreId) {
      retry.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        navigate(url, options);
      });
    }
    status.append(retry);
    status.hidden = false;
  }

  async function navigate(url, options = {}) {
    const { restoreId, restoreState, motion = false, openSingleResult = false } = options;
    cancelNavigation();
    saveCurrent();
    const old = restoreId && views.get(restoreId);
    // Fetch product pages again on Back/Forward: warehouse prices/offer versions are live.
    if (old && old.kind !== 'product' && routeKey(old.url) === routeKey(url)) {
      show({ ...old, url }, { restore: true, motion });
      return;
    }
    // Keep the already opened home and clean list available for ordinary mobile taps.
    // Product, offer and catalogue responses still come from the server.
    const cached = compact.matches && !restoreId && reusable.get(routeKey(url));
    if (cached) {
      if (current.url.href === url.href && location.href === url.href) return;
      const view = { ...cached, url, id: nextId(), query: input.value, scroll: 0, focus: null };
      history.pushState(viewState(view), '', url.href);
      show(view, { motion });
      return;
    }
    const request = new AbortController();
    const transition = { request, restoring: Boolean(restoreId) };
    pending = transition;
    const openNatively = () => {
      // A history traversal already occupies its destination entry.
      if (restoreId) location.replace(url.href);
      else location.assign(url.href);
    };
    main.setAttribute('aria-busy', 'true');
    loadingTimer = setTimeout(() => {
      if (pending !== transition) return;
      status.textContent = pageKind(url) === 'product' ? 'Открываем товар…'
        : pageKind(url) === 'parts-list' ? 'Открываем список или файл…' : 'Загружаем результаты…';
      status.hidden = false;
      if (!compact.matches) main.classList.add('v2-waiting');
    }, 160);
    const deadline = setTimeout(() => {
      if (pending !== transition) return;
      cancelNavigation();
      showFailure(url, options);
    }, 15000);
    try {
      const response = await fetch(url.href, { signal: request.signal, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
      if (pending !== transition) return;
      const finalUrl = new URL(response.url);
      finalUrl.hash = url.hash;
      if (!response.ok || !supported(finalUrl) || !response.headers.get('content-type')?.includes('text/html')) {
        openNatively(); // Normal server redirects/404/410 remain authoritative.
        return;
      }
      const html = await response.text();
      if (pending !== transition) return;
      const page = new DOMParser().parseFromString(html, 'text/html');
      const kind = page.body.dataset.v2Page;
      const content = page.querySelector('#main-content');
      if (!content || !page.body.classList.contains('v2-storefront') || kind !== pageKind(finalUrl)
          || (kind === 'home' && !content.querySelector('[data-v2-search-home]'))
          || (kind === 'parts-list' && !content.querySelector('[data-parts-list]'))) {
        openNatively();
        return;
      }
      content.querySelector('#article-search')?.remove();
      // A submitted search with one complete result opens the same V2 product
      // sheet as a suggestion. Never choose from a truncated/ambiguous result set.
      if (openSingleResult && kind === 'catalog' && content.dataset.v2SingleResult) {
        const productUrl = new URL(content.dataset.v2SingleResult, finalUrl);
        if (supported(productUrl) && pageKind(productUrl) === 'product') {
          return navigate(productUrl, { motion });
        }
      }
      const saved = old || restoreState || {};
      const view = {
        id: restoreId || nextId(), url: finalUrl, kind, nodes: [...content.childNodes],
        title: page.title, head: metadata(page.head), query: typeof saved.query === 'string' ? saved.query.slice(0, 64) : input.value,
        scroll: Number.isFinite(saved.scroll) ? Math.max(0, saved.scroll) : 0,
        focus: saved.focus === 'search' ? 'search' : null, initial: false,
      };
      if (kind === 'parts-list') view.nodes = await preparePartsList(view.nodes);
      if (pending !== transition) return;
      if (kind === 'catalog') view.query = finalUrl.searchParams.get('q') || '';
      if (restoreId) history.replaceState(currentState(view), '', finalUrl.href);
      else history.pushState(viewState(view), '', finalUrl.href);
      show(view, { restore: Boolean(restoreId), motion });
    } catch {
      if (pending === transition) showFailure(url, options);
    } finally {
      clearTimeout(deadline);
      if (pending === transition) {
        pending = null;
        clearTimeout(loadingTimer);
        main.removeAttribute('aria-busy');
        main.classList.remove('v2-waiting');
      }
    }
  }

  function searchFor(value, { allResults = false } = {}) {
    const query = value.trim();
    rememberQuery(query);
    const url = new URL('/catalog', location.origin);
    if (query) url.searchParams.set('q', query);
    navigate(url, { motion: pointerAction, openSingleResult: Boolean(query) && !allResults });
  }

  document.addEventListener('pointerdown', () => { pointerAction = true; }, { capture: true, passive: true });
  document.addEventListener('keydown', () => { pointerAction = false; stopAnimations(); }, true);
  window.addEventListener('resize', stopAnimations, { passive: true });
  window.addEventListener('wheel', stopAnimations, { passive: true });
  window.addEventListener('touchstart', stopAnimations, { passive: true });
  input.addEventListener('input', () => {
    // Editing a new query supersedes any product still loading from an earlier choice.
    // The address bar already moved during a history traversal.
    if (pending && !pending.restoring) cancelNavigation();
    saveCurrent();
  });
  window.addEventListener('scroll', () => {
    clearTimeout(scrollTimer);
    scrollTimer = setTimeout(saveCurrent, 180);
  }, { passive: true });
  input.addEventListener('focus', saveCurrent);
  input.addEventListener('blur', saveCurrent);
  document.addEventListener('submit', event => {
    if (event.target !== input.form) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    searchFor(input.value);
  }, true);
  document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const queryButton = event.target.closest('.search-all,.history-item');
    if (queryButton && search.contains(queryButton)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      searchFor(queryButton.matches('.history-item') ? queryButton.querySelector('strong').textContent : input.value,
        { allResults: queryButton.matches('.search-all') });
      return;
    }
    const link = event.target.closest('a[href]');
    if (!link || link.hasAttribute('download') || link.dataset.v2Native !== undefined || (link.target && link.target !== '_self')) return;
    const url = new URL(link.href, location.href);
    if (!supported(url) || (url.hash && routeKey(url) === routeKey(new URL(location.href)))) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (link.hasAttribute('data-v2-search-example')) {
      input.value = url.searchParams.get('q') || '';
      searchFor(input.value);
      return;
    }
    if (link.closest('.search-popover')) rememberQuery(input.value.trim());
    navigate(url, { motion: pointerAction });
  }, true);
  window.addEventListener('popstate', event => {
    const url = new URL(location.href);
    if (!supported(url)) { location.reload(); return; }
    if (routeKey(url) === routeKey(current.url) && url.hash !== current.url.hash) {
      cancelNavigation();
      current.url = url;
      history.replaceState(currentState(current), '', url.href);
      return;
    }
    navigate(url, { restoreId: event.state?.v2View || nextId(), restoreState: event.state?.v2Navigation, motion: pointerAction });
  });
  window.addEventListener('pagehide', () => { cancelNavigation(); stopAnimations(); });
}
