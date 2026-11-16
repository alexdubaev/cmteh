// Legacy asset name retained for the V2 overlay installer. The drawing is static;
// only a soft mask reveals a little more detail near a mouse pointer.
const stage = document.querySelector('.v2-stage');
const layer = stage?.querySelector('.v2-excavator-layer');
const drawing = layer?.querySelector('.v2-excavator');

if (drawing && (CSS.supports('mask-image', 'radial-gradient(black, transparent)')
    || CSS.supports('-webkit-mask-image', 'radial-gradient(black, transparent)'))) {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const mouse = matchMedia('(min-width: 641px) and (hover: hover) and (pointer: fine)');
  const detail = document.createElement('div');
  detail.className = 'v2-excavator-detail';
  detail.append(drawing.cloneNode(true));
  layer.append(detail); // Both images share precisely the same geometry and source.

  let x = 0, y = 0, targetX = 0, targetY = 0, frame = 0, previousTime = 0, active = false;
  const enabled = () => mouse.matches && !reduced.matches && !document.hidden;

  function reset() {
    cancelAnimationFrame(frame);
    frame = previousTime = 0;
    active = false;
    detail.classList.remove('is-active');
  }

  function render(time) {
    frame = 0;
    if (!enabled() || !active) return reset();
    const delta = previousTime ? Math.min(time - previousTime, 64) : 16;
    const ease = 1 - Math.exp(-delta / 100);
    x += (targetX - x) * ease;
    y += (targetY - y) * ease;
    const moving = Math.abs(targetX - x) + Math.abs(targetY - y) > 0.2;
    if (!moving) { x = targetX; y = targetY; }
    detail.style.setProperty('--detail-x', `${x.toFixed(1)}px`);
    detail.style.setProperty('--detail-y', `${y.toFixed(1)}px`);
    previousTime = moving ? time : 0;
    if (moving) frame = requestAnimationFrame(render);
  }

  stage.addEventListener('pointermove', event => {
    if (!enabled() || event.pointerType !== 'mouse'
        || event.target.closest('a,button,input,textarea,select,summary,[role="option"]')) return reset();
    const bounds = layer.getBoundingClientRect();
    targetX = event.clientX - bounds.left;
    targetY = event.clientY - bounds.top;
    if (targetX < 0 || targetY < 0 || targetX > bounds.width || targetY > bounds.height) return reset();
    if (!active) {
      x = targetX; y = targetY;
      detail.style.setProperty('--detail-x', `${x.toFixed(1)}px`);
      detail.style.setProperty('--detail-y', `${y.toFixed(1)}px`);
      active = true;
      detail.classList.add('is-active');
    }
    if (!frame) frame = requestAnimationFrame(render);
  }, { passive: true });
  stage.addEventListener('pointerleave', reset);
  stage.addEventListener('focusin', event => {
    if (event.target.matches('a,button,input,textarea,select,summary,[role="option"]')) reset();
  });
  document.addEventListener('keydown', reset, true);
  document.addEventListener('v2:navigated', reset);
  document.addEventListener('visibilitychange', reset);
  window.addEventListener('scroll', reset, { passive: true });
  window.addEventListener('resize', reset, { passive: true });
  window.addEventListener('blur', reset);
  window.addEventListener('pagehide', reset);
  reduced.addEventListener('change', reset);
  mouse.addEventListener('change', reset);
}
