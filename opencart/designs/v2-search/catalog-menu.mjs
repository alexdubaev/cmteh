// V2 header presentation. Native details and the shared storefront module still
// own opening, outside clicks and Escape. No catalog data or links are rewritten.
const menu = document.querySelector('.v2-header .catalog-menu');
const tabs = menu?.querySelector('.v2-catalog-tabs');
if (tabs) {
  const buttons = [...tabs.querySelectorAll('[role="tab"]')];
  const panels = buttons.map(button => document.getElementById(button.getAttribute('aria-controls')));
  const viewport = menu.querySelector('.v2-catalog-viewport');
  const indicator = tabs.querySelector('.v2-catalog-indicator');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const compact = matchMedia('(max-width: 820px), (pointer: coarse)');
  const animations = new Set();
  let selected = 0, pointer = false, clearHeight, outgoing;
  const easing = 'cubic-bezier(.2,.75,.25,1)';

  function stop() {
    for (const animation of animations) animation.cancel();
    animations.clear();
    clearHeight?.();
    outgoing?.remove();
    outgoing = null;
  }
  function animate(element, frames, options) {
    if (reduced.matches || !element.animate) return;
    const animation = element.animate(frames, options);
    animations.add(animation);
    animation.finished.catch(() => {}).finally(() => animations.delete(animation));
    return animation;
  }
  function select(index, motion = false) {
    if (index === selected && !tabs.hidden) return;
    const previous = selected;
    const moving = motion && menu.open && !reduced.matches && Boolean(viewport.animate);
    const compactMotion = compact.matches;
    const height = moving && !compactMotion ? viewport.getBoundingClientRect().height : 0;
    const marker = moving ? getComputedStyle(indicator).transform : null;
    stop();
    if (moving && !compactMotion) {
      // Visual-only outgoing copy: keep the previous list present while the new
      // one fades in, without duplicate IDs, focus targets or accessible panels.
      outgoing = panels[previous].cloneNode(true);
      outgoing.removeAttribute('id');
      outgoing.removeAttribute('role');
      outgoing.removeAttribute('aria-labelledby');
      outgoing.setAttribute('aria-hidden', 'true');
      outgoing.inert = true;
      outgoing.classList.add('v2-catalog-outgoing');
      viewport.append(outgoing);
    }
    selected = index;
    for (let i = 0; i < buttons.length; i++) {
      buttons[i].setAttribute('aria-selected', String(i === index));
      buttons[i].tabIndex = i === index ? 0 : -1;
      panels[i].hidden = i !== index;
    }
    tabs.dataset.active = String(index);
    viewport.scrollTop = 0;
    if (!moving) return;
    animate(indicator, [{ transform: marker }, { transform: getComputedStyle(indicator).transform }],
      { duration: compactMotion ? 170 : 300, easing });
    if (compactMotion) {
      // Paint the new links directly at their final height on mobile.
      animate(panels[index], [{ opacity: 0.5 }, { opacity: 1 }], { duration: 170, easing });
      return;
    }
    const target = viewport.getBoundingClientRect().height;
    viewport.classList.add('is-switching');
    const finish = () => {
      if (clearHeight !== finish) return;
      clearHeight = null;
      viewport.classList.remove('is-switching');
      outgoing?.remove();
      outgoing = null;
      sizing.cancel();
    };
    clearHeight = finish;
    const sizing = animate(viewport, [{ height: `${height}px` }, { height: `${target}px` }], { duration: 300, easing, fill: 'both' });
    sizing.finished.then(finish, finish);
    animate(outgoing, [{ opacity: 1 }, { opacity: 0 }], { duration: 160, easing: 'ease-out', fill: 'forwards' });
    animate(panels[index], [{ opacity: 0 }, { opacity: 1 }], { duration: 220, easing: 'ease-out', fill: 'backwards' });
  }

  for (let i = 0; i < buttons.length; i++) {
    panels[i].setAttribute('role', 'tabpanel');
    panels[i].setAttribute('aria-labelledby', buttons[i].id);
    buttons[i].addEventListener('click', () => select(i, pointer));
  }
  menu.classList.add('is-enhanced');
  select(0);
  tabs.hidden = false;
  menu.addEventListener('pointerdown', () => { pointer = true; menu.classList.add('v2-menu-motion'); }, { passive: true });
  tabs.addEventListener('keydown', event => {
    const current = buttons.indexOf(event.target);
    if (current < 0) return;
    let next;
    if (event.key === 'ArrowRight') next = (current + 1) % buttons.length;
    else if (event.key === 'ArrowLeft') next = (current + buttons.length - 1) % buttons.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = buttons.length - 1;
    else return;
    event.preventDefault();
    select(next);
    buttons[next].focus();
  });
  // Opening is a CSS animation applied with [open], before its first paint.
  // The native toggle event is deferred and is too late to start a fade-in.
  menu.addEventListener('toggle', stop);
  document.addEventListener('keydown', () => { pointer = false; menu.classList.remove('v2-menu-motion'); stop(); }, true);
  window.addEventListener('resize', stop, { passive: true });
  window.addEventListener('pagehide', stop);
  document.addEventListener('v2:navigated', stop);
  reduced.addEventListener('change', stop);
}

// The right-hand navigation reuses the same before-paint reveal. Its existing
// controller continues to own toggling, focus, Escape and outside clicks.
const navigation = document.querySelector('.v2-header .main-nav');
const navigationButton = document.querySelector('.v2-header .menu-toggle');
if (navigation && navigationButton) {
  const instant = () => navigation.classList.remove('v2-menu-motion');
  navigationButton.addEventListener('pointerdown', () => navigation.classList.add('v2-menu-motion'), { passive: true });
  document.addEventListener('keydown', instant, true);
  window.addEventListener('resize', instant, { passive: true });
  document.addEventListener('v2:navigated', instant);
}
