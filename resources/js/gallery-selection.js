(function () {
  'use strict';
  const grid = document.getElementById('galimages');
  const start = document.getElementById('hmu-gallery-select');
  if (!grid || !start || !config.gallery.allow_delete) return;
  const all = document.getElementById('hmu-gallery-all');
  const clear = document.getElementById('hmu-gallery-clear');
  const remove = document.getElementById('hmu-gallery-delete');
  const cancel = document.getElementById('hmu-gallery-cancel');
  const status = document.getElementById('hmu-gallery-status');
  const selected = new Set();
  let active = false, busy = false;
  const links = () => [...grid.querySelectorAll(':scope > a.gallery__img')];
  const filename = (link) => decodeURIComponent(new URL(link.href, document.baseURI).pathname.split('/').pop());
  const api = window.hmuGallerySelection = {
    get active() { return active || busy; },
    cancel() { active = false; if (!busy) selected.clear(); update(); }
  };
  function update() {
    grid.classList.toggle('hmu-gallery-selecting', active);
    start.hidden = active;
    start.disabled = busy;
    for (const button of [all, clear, remove, cancel]) { button.hidden = !active; button.disabled = busy; }
    remove.disabled = busy || !selected.size;
    remove.textContent = busy ? 'Đang xóa ảnh…' : `Xóa ảnh đã chọn (${selected.size})`;
    grid.setAttribute('aria-busy', String(busy));
    for (const link of links()) {
      if (active) {
        link.setAttribute('role', 'checkbox');
        link.setAttribute('aria-checked', String(selected.has(filename(link))));
        link.setAttribute('aria-label', filename(link));
      } else {
        link.removeAttribute('role'); link.removeAttribute('aria-checked'); link.removeAttribute('aria-label');
      }
    }
  }
  start.addEventListener('click', () => { active = true; status.textContent = 'Chọn các ảnh cần xóa.'; update(); all.focus(); });
  all.addEventListener('click', () => { if (!busy) { links().forEach(link => selected.add(filename(link))); update(); } });
  clear.addEventListener('click', () => { if (!busy) { selected.clear(); update(); } });
  cancel.addEventListener('click', () => { api.cancel(); status.textContent = ''; start.focus(); });
  grid.addEventListener('click', (event) => {
    if (!api.active) return;
    const link = event.target.closest('a.gallery__img');
    if (!link || !grid.contains(link)) return;
    event.preventDefault(); event.stopImmediatePropagation();
    if (busy) return;
    const name = filename(link);
    selected.has(name) ? selected.delete(name) : selected.add(name);
    update();
  }, true);
  grid.addEventListener('keydown', (event) => {
    if (active && event.code === 'Space' && event.target.matches('a.gallery__img')) { event.preventDefault(); event.target.click(); }
  });
  document.addEventListener('keydown', (event) => {
    if (active && !busy && event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); cancel.click(); }
  }, true);
  remove.addEventListener('click', async () => {
    if (busy || !selected.size || !confirm(`Xóa ${selected.size} ảnh đã chọn? Thao tác này không thể hoàn tác.`)) return;
    busy = true; update();
    let deletedCount = 0;
    const failures = [];
    const pending = [...selected];
    // Bound server work while permitting any number of selected photos.
    for (let offset = 0; offset < pending.length; offset += 100) {
      const batch = pending.slice(offset, offset + 100);
      try {
        const body = new URLSearchParams();
        batch.forEach(name => body.append('files[]', name));
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 30000);
        let result;
        try {
          const response = await fetch('api/deletePhoto.php', { method: 'POST', body, credentials: 'same-origin', signal: controller.signal });
          result = await response.json();
        } finally { clearTimeout(timeout); }
        if (!Array.isArray(result.deleted)) throw new Error(result.error || 'Invalid deletion response');
        const deleted = new Set(result.deleted.filter(name => batch.includes(name)));
        for (const link of links()) if (deleted.has(filename(link))) link.remove();
        for (const name of batch) {
          if (deleted.has(name)) { selected.delete(name); deletedCount++; }
          else failures.push(name);
        }
      } catch (error) { failures.push(...batch); }
    }
    busy = false;
    if (!active) selected.clear();
    update();
    status.textContent = `Đã xóa ${deletedCount} ảnh.` + (failures.length ? ` Chưa xóa được ${failures.length} ảnh: ${failures.join(', ')}. Hãy thử lại.` : '');
    if (!links().length) {
      const empty = document.createElement('p'); empty.className = 'hmu-gallery-empty'; empty.textContent = 'Thư viện chưa có ảnh.'; grid.append(empty);
    }
  });
})();
