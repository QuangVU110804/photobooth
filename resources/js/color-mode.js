(function () {
  'use strict';

  const key = 'hmu-color-mode';
  const device = window.matchMedia('(prefers-color-scheme: dark)');
  const root = document.documentElement;
  const meta = document.querySelector('meta[name="theme-color"]');
  const lightColor = meta?.content;
  let preference;

  function readPreference() {
    try { preference = localStorage.getItem(key); } catch { /* Storage may be disabled. */ }
  }

  function apply() {
    const dark = preference === 'dark' || (preference !== 'light' && device.matches);
    root.dataset.colorMode = dark ? 'dark' : 'light';
    if (meta) meta.content = dark ? '#101925' : lightColor;
    document.querySelectorAll('.hmu-color-mode').forEach(button => {
      button.setAttribute('aria-checked', String(dark));
      button.hidden = false;
    });
  }

  readPreference();
  apply();
  document.addEventListener('DOMContentLoaded', apply);
  device.addEventListener?.('change', apply);
  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    readPreference();
    apply();
  });
  window.addEventListener('storage', event => {
    if (event.key !== key && event.key !== null) return;
    preference = event.newValue;
    apply();
  });
  document.addEventListener('click', event => {
    if (!event.target.closest?.('.hmu-color-mode')) return;
    preference = root.dataset.colorMode === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(key, preference); } catch { /* The current page can still switch. */ }
    apply();
  });
  document.addEventListener('keyup', event => {
    if ((event.key === 'Enter' || event.key === ' ') && event.target.matches?.('.hmu-color-mode')) {
      event.stopPropagation();
    }
  }, true);
})();
