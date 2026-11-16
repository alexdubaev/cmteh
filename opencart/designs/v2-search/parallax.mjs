// Decorative V2 background only: no perpetual animation, device sensors or UI movement.
const hero = document.querySelector('.v2-stage');
const drawing = hero?.querySelector('.v2-excavator');

if (drawing) {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const mouse = matchMedia('(hover: hover) and (pointer: fine)');
  const clamp = (value, limit) => Math.max(-limit, Math.min(limit, value));
  let pointerX = 0, pointerY = 0, scrollY = 0;
  let x = 0, y = 0, frame = 0, previousTime = 0, visible = true;
  const enabled = () => !reduced.matches && !document.hidden && visible;

  function reset() {
    cancelAnimationFrame(frame);
    frame = previousTime = x = y = 0;
    drawing.style.removeProperty('transform');
  }

  function render(time) {
    frame = 0;
    if (!enabled()) return reset();
    const targetX = pointerX;
    const targetY = pointerY + scrollY;
    const delta = previousTime ? Math.min(time - previousTime, 64) : 16;
    const ease = 1 - Math.exp(-delta / 160);
    x += (targetX - x) * ease;
    y += (targetY - y) * ease;
    const moving = Math.abs(targetX - x) + Math.abs(targetY - y) > 0.05;
    if (!moving) { x = targetX; y = targetY; }
    drawing.style.transform = `translate3d(${x.toFixed(2)}px, ${y.toFixed(2)}px, 0)`;
    previousTime = moving ? time : 0;
    if (moving) frame = requestAnimationFrame(render);
  }

  function schedule() {
    if (!enabled()) return reset();
    if (!frame) frame = requestAnimationFrame(render);
  }

  function updateScroll() {
    const bounds = hero.getBoundingClientRect();
    visible = bounds.bottom > 0 && bounds.top < innerHeight;
    scrollY = clamp(window.scrollY * 0.05, 10);
    schedule();
  }

  hero.addEventListener('pointermove', event => {
    if (!mouse.matches || event.pointerType !== 'mouse' || !enabled()) return;
    const bounds = hero.getBoundingClientRect();
    pointerX = clamp((event.clientX - bounds.left) / bounds.width - 0.5, 0.5) * -18;
    pointerY = clamp((event.clientY - bounds.top) / bounds.height - 0.5, 0.5) * -12;
    schedule();
  }, { passive: true });
  hero.addEventListener('pointerleave', () => {
    pointerX = pointerY = 0;
    schedule();
  });
  const updatePreferences = () => {
    pointerX = pointerY = 0;
    updateScroll();
  };
  reduced.addEventListener('change', updatePreferences);
  mouse.addEventListener('change', updatePreferences);
  window.addEventListener('scroll', updateScroll, { passive: true });
  window.addEventListener('resize', updatePreferences, { passive: true });
  document.addEventListener('visibilitychange', updateScroll);
  window.addEventListener('pagehide', reset);
  updateScroll();
}
