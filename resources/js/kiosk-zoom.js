(function () {
  'use strict';
  const pointers = new Set();
  let pinching = false;
  window.addEventListener('pointerdown', function (event) {
    if (event.pointerType !== 'touch') return;
    pointers.add(event.pointerId);
    if (pointers.size > 1) pinching = true;
    if (pinching) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, { capture: true, passive: false });
  window.addEventListener('pointermove', function (event) {
    if (event.pointerType === 'touch' && pinching) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, { capture: true, passive: false });
  for (const name of ['pointerup', 'pointercancel']) {
    window.addEventListener(name, function (event) {
      pointers.delete(event.pointerId);
      if (!pointers.size) pinching = false;
      // Releases still reach the viewer so it can clean up its first pointer.
    }, true);
  }
  window.addEventListener('blur', function () { pointers.clear(); pinching = false; });
  document.addEventListener('wheel', function (event) {
    if (event.ctrlKey || event.metaKey) event.preventDefault();
  }, { passive: false });
  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && ['+', '=', '-', '_', '0'].includes(event.key)) event.preventDefault();
  });
  for (const name of ['gesturestart', 'gesturechange', 'gestureend']) {
    document.addEventListener(name, function (event) { event.preventDefault(); }, { passive: false });
  }
  document.addEventListener('touchmove', function (event) {
    if (event.touches.length > 1) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, { passive: false, capture: true });
  document.addEventListener('touchstart', function (event) {
    if (event.touches.length > 1) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, { passive: false, capture: true });
})();
