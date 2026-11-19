// The V2 header has a single menu. Keep the pointer reveal in CSS so it
// starts before the navigation's first paint.
const navigation = document.querySelector('.v2-header .main-nav');
const button = document.querySelector('.v2-header .menu-toggle');

if (navigation && button) {
  const isOpen = () => navigation.classList.contains('is-open');
  const instant = () => navigation.classList.remove('v2-menu-motion');

  function toggle(open, restoreFocus = false) {
    navigation.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', String(open));
    button.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
    if (restoreFocus) {
      requestAnimationFrame(() => {
        if (isOpen() === open) (open ? navigation.querySelector('a') : button)?.focus();
      });
    }
  }

  button.addEventListener('pointerdown', () => navigation.classList.add('v2-menu-motion'), { passive: true });
  button.addEventListener('click', () => toggle(!isOpen(), true));
  document.addEventListener('pointerdown', event => {
    if (isOpen() && !navigation.contains(event.target) && !button.contains(event.target)) toggle(false);
  });
  document.addEventListener('keydown', instant, true);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !event.defaultPrevented && isOpen()) toggle(false, true);
  });
  window.addEventListener('resize', instant, { passive: true });
  document.addEventListener('v2:navigated', instant);
}
