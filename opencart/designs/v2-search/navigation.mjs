import { activateView } from './view-controls.mjs?v=20261120-4';
import { mountRequestPage } from '../request.mjs?v=20261120-2';
import { setupPartsList } from '../parts-list.mjs?v=20261118-1';

// A V2 presentation enhancement over normal, server-rendered storefront URLs.
// No response scripts are executed, and commerce uses the existing shared modules.
const main = document.querySelector('#main-content');
const search = document.querySelector('#article-search');
const input = search?.querySelector('input[name=q]');
const dock = document.querySelector('#v2-search-dock');
const status = document.querySelector('#v2-navigation-status');
const headSelector = 'meta[name="description"],meta[name="robots"],link[rel="canonical"],meta[property^="og:"],script[type="application/ld+json"]';
const isContacts = url => url.pathname === '/contacts';
const isPartsList = url => url.pathname === '/parts-list' && [...url.searchParams.keys()].every(key => key === 'article');
const supported = url => url.origin === location.origin && !url.username && !url.password
  && (url.pathname === '/' || ['/catalog', '/brands', '/articles', '/about', '/delivery', '/payment'].includes(url.pathname) || isContacts(url)
    || /^\/(?:brand|category|articles)\/[^/]+$/.test(url.pathname)
    || /^\/(?!admin(?:\/|$)|api(?:\/|$)|account(?:\/|$)|request(?:\/|$)|parts-list(?:\/|$)|contacts(?:\/|$)|cart(?:\/|$)|checkout(?:\/|$)|extension(?:\/|$)|image(?:\/|$)|information(?:\/|$)|install(?:\/|$)|product(?:\/|$)|sitemap(?:s)?(?:\/|$)|system(?:\/|$)|_cmteh_platform(?:\/|$))[^/]+$/.test(url.pathname)
    || /^\/product\/[^/]+$/.test(url.pathname)
    // The public article handoff is a normal view. Other parameters keep native recovery.
    || isPartsList(url)
    || (url.pathname === '/request' && !url.search && !['#import', '#request-import'].includes(url.hash)));
const pageKind = url => url.pathname === '/' ? 'home' : url.pathname === '/catalog' ? 'catalog'
  : url.pathname === '/brands' ? 'brands' : url.pathname === '/articles' ? 'articles'
    : url.pathname === '/parts-list' ? 'parts-list'
      : url.pathname === '/request' ? 'request'
      : /^\/brand\//.test(url.pathname) ? 'brand' : /^\/category\//.test(url.pathname) ? 'category'
        : /^\/articles\//.test(url.pathname) ? 'article'
          : /^\/product\//.test(url.pathname) ? 'product' : 'page';
const routeKey = url => url.pathname + url.search;
const reusableKey = url => isContacts(url) ? '/contacts' : routeKey(url);
const reusableRoute = view => (view.kind === 'home' && routeKey(view.url) === '/')
  || (view.kind === 'parts-list' && routeKey(view.url) === '/parts-list')
  || (view.kind === 'request' && routeKey(view.url) === '/request')
  || isContacts(view.url);

if (main && search && input && dock && status && supported(new URL(location.href))) {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const compact = matchMedia('(max-width: 820px), (pointer: coarse)');
  const views = new Map();
  const reusable = new Map();
  const animations = new Set();
  let sequence = 0, pending, scrollTimer, pointerAction = false;
  const nextId = () => `${Date.now()}-${++sequence}`;
  const metadata = root => [...root.querySelectorAll(headSelector)].map(node => node.cloneNode(true));
  const viewState = view => ({ v2View: view.id, v2Navigation: { query: view.query, scroll: view.scroll, focus: view.focus } });
  const currentState = view => ({ ...(history.state && typeof history.state === 'object' ? history.state : {}), ...viewState(view) });
  let current = {
    id: nextId(), url: new URL(location.href), kind: document.body.dataset.v2Page,
    nodes: [...main.childNodes], title: document.title, head: metadata(document.head),
    query: input.value, scroll: window.scrollY, focus: null, initial: true,
  };
  if (reusableRoute(current)) reusable.set(reusableKey(current.url), current);
  history.replaceState(currentState(current), '', location.href);
  history.scrollRestoration = 'manual';
  views.set(current.id, current);
  activateView(main, { bindControls: false });
  updateArticleLinks(current.kind);

  // Keep the original form alive, including FileList, results and in-flight work.
  // The shared module owns validation, draft storage and quote idempotency.
  const partsParking = document.createElement('div');
  partsParking.hidden = true;
  partsParking.inert = true;
  main.after(partsParking);
  let partsNodes = current.kind === 'parts-list' ? current.nodes : null;
  function updateArticleLinks(kind) {
    if (kind !== 'article') return;
    for (const link of main.querySelectorAll('a[href="/request?import=1"],a[href="/request#import"],a[href="/request#request-import"]')) {
      link.setAttribute('href', '/parts-list');
    }
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

  function reveal(view, previousSearch) {
    const bounds = search.getBoundingClientRect();
    const duration = 250;
    const easing = 'cubic-bezier(.22,1,.36,1)';
    const moved = bounds.height && bounds.width && previousSearch.height && previousSearch.width
      && (Math.abs(previousSearch.left - bounds.left) >= 1 || Math.abs(previousSearch.top - bounds.top) >= 1
        || Math.abs(previousSearch.width - bounds.width) >= 1 || Math.abs(previousSearch.height - bounds.height) >= 1);
    const searchMotion = moved ? animate(search, [
      { transformOrigin: '0 0', transform: `translate(${previousSearch.left - bounds.left}px, ${previousSearch.top - bounds.top}px) scale(${previousSearch.width / bounds.width},${previousSearch.height / bounds.height})` },
      { transformOrigin: '0 0', transform: 'none' },
    ], { duration, easing }) : null;

    // The page is already laid out at its final size. Keep motion on small elements.
    const title = main.querySelector('h1');
    const intro = title?.closest('.v2-product-heading, .page-title, .collection-heading') || title;
    const logo = view.kind === 'home' ? main.querySelector('.v2-home-brand') : null;
    const introMotion = [intro, logo].filter(Boolean).map(element => animate(element, [
      { opacity: 0.72, transform: 'translateY(6px)' },
      { opacity: 1, transform: 'none' },
    ], { duration, easing }));
    playAfterPaint([searchMotion, ...introMotion].filter(Boolean));
  }

  function clearLoading() {
    main.removeAttribute('aria-busy');
    status.hidden = true;
    status.replaceChildren();
  }

  function cancelNavigation() {
    pending?.request.abort();
    pending = null;
    clearLoading();
  }

  function closeMenu() {
    const menu = document.querySelector('.catalog-menu');
    if (menu) menu.open = false;
    document.querySelector('.main-nav')?.classList.remove('is-open');
    const button = document.querySelector('.menu-toggle');
    button?.setAttribute('aria-expanded', 'false');
    button?.setAttribute('aria-label', 'Открыть меню');
  }

  function show(view, { restore = false, motion = false } = {}) {
    const revealMotion = motion && !reduced.matches && (!restore || view.scroll === 0);
    const previousSearch = revealMotion ? search.getBoundingClientRect() : null;
    input.blur(); // Closes existing suggestions and aborts their outstanding request.
    stopAnimations();
    if (current.kind === 'parts-list') partsParking.replaceChildren(...partsNodes);
    if (view.kind === 'parts-list') {
      partsNodes ||= view.nodes;
      view.nodes = partsNodes;
    }
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
    if (view.kind === 'request' || isContacts(view.url)) mountRequestPage(main, { refresh: true });
    if (view.kind === 'parts-list') {
      const prefill = view.url.searchParams.get('article') || '';
      const updatePrefill = setupPartsList(main.querySelector('[data-parts-list]'), { prefill });
      updatePrefill(prefill);
    }
    closeMenu();
    clearLoading();
    current = view;
    views.set(view.id, view);
    if (reusableRoute(view)) reusable.set(reusableKey(view.url), view);
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
    if (previousSearch) reveal(view, previousSearch);
    updateArticleLinks(view.kind);
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
    // Reuse the already initialized cart and the simple pages used on mobile.
    // The cart module refreshes live prices and offers every time it opens.
    const cached = !restoreId && (compact.matches || url.pathname === '/request' || isContacts(url)) && reusable.get(reusableKey(url));
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
    const deadline = setTimeout(() => {
      if (pending !== transition) return;
      cancelNavigation();
      showFailure(url, options);
    }, 15000);
    try {
      const headers = { Accept: 'text/html' };
      if (openSingleResult) headers['X-Cmteh-Navigation'] = 'single-product';
      const response = await fetch(url.href, { signal: request.signal, credentials: 'same-origin', cache: 'no-store', headers });
      if (pending !== transition) return;
      const responseUrl = new URL(response.url);
      const productPath = openSingleResult && responseUrl.pathname === '/catalog' ? response.headers.get('Content-Location') : null;
      const productUrl = productPath?.startsWith('/product/') ? new URL(productPath, responseUrl) : null;
      const finalUrl = productUrl && supported(productUrl) && pageKind(productUrl) === 'product' && !productUrl.search && !productUrl.hash
        ? productUrl : responseUrl;
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
          || (kind === 'parts-list' && !content.querySelector('[data-parts-list]'))
          || (kind === 'request' && (!content.querySelector('[data-cart]') || !content.querySelector('[data-lead="request"]')))
          || (isContacts(finalUrl) && !content.querySelector('[data-lead="contact"]'))) {
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
      if (kind === 'request' || isContacts(finalUrl)) {
        view.nodes = reusable.get(reusableKey(finalUrl))?.nodes || view.nodes;
      }
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
        main.removeAttribute('aria-busy');
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
    if (event.defaultPrevented) return;
    const form = event.target;
    if (form === input.form) {
      event.preventDefault();
      event.stopImmediatePropagation();
      searchFor(input.value);
      return;
    }

    // Apply the server-rendered sort and facets using the browser's successful controls.
    if (current.kind !== 'catalog' || !main.contains(form) || !form.matches('.facet-form, .catalog-toolbar-right form')) return;
    const submitter = event.submitter;
    const method = submitter?.getAttribute('formmethod') || form.method;
    const target = submitter?.getAttribute('formtarget') || form.target;
    const url = new URL(submitter?.getAttribute('formaction') || form.action, location.href);
    if (method.toLowerCase() !== 'get' || (target && target !== '_self') || !supported(url) || url.pathname !== '/catalog') return;
    url.search = new URLSearchParams(new FormData(form, submitter));
    event.preventDefault();
    event.stopImmediatePropagation();
    navigate(url, { motion: pointerAction });
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
    if (!link || link.hasAttribute('download') || link.hasAttribute('data-open-quick')
        || link.dataset.v2Native !== undefined || (link.target && link.target !== '_self')) return;
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
    if (!compact.matches && link.closest('.v2-header .main-nav')) {
      closeMenu();
      document.querySelector('.v2-header .menu-toggle')?.focus({ preventScroll: true });
    }
    const motion = pointerAction;
    const quick = link.closest('dialog[data-quick-dialog][open]');
    if (quick) {
      // Let the dialog finish restoring focus before the destination takes it.
      const source = current, previousRequest = pending;
      quick.addEventListener('close', () => {
        if (current === source && pending === previousRequest) navigate(url, { motion });
      }, { once: true });
      quick.close();
      return;
    }
    navigate(url, { motion });
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
