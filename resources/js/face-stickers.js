(function (root) {
  'use strict';
  const legacy = [33, 263, 93, 323];
  const anchors = {
    eyes: [33, 263], 'left-eye': [33], 'right-eye': [263],
    'left-cheek': [93], 'right-cheek': [323], nose: [1], mouth: [13],
    forehead: [10], chin: [152], face: [10, 152]
  };
  const idPattern = /^[a-z0-9][a-z0-9_-]{0,63}$/;
  const filePattern = /^[a-zA-Z0-9_-]{1,120}\.png$/;
  const number = (v, min, max) => typeof v === 'number' && Number.isFinite(v) && v >= min && v <= max;
  const object = (v, keys) => v && typeof v === 'object' && !Array.isArray(v) && Object.keys(v).every(k => keys.includes(k));
  const pair = (v, min, max) => Array.isArray(v) && v.length === 2 && v.every(n => number(n, min, max));
  function validateCatalog(raw) {
    const invalid = () => { throw new Error('Invalid sticker catalog, effect or part'); };
    if (!object(raw, ['version', 'effects']) || raw.version !== 1 || !Array.isArray(raw.effects) || raw.effects.length > 32) invalid();
    const ids = new Set(['none', 'glasses', 'blush']);
    return raw.effects.map(effect => {
      if (!object(effect, ['id', 'title', 'parts']) || typeof effect.id !== 'string' || !idPattern.test(effect.id) || ids.has(effect.id)
        || typeof effect.title !== 'string' || !effect.title.trim() || Array.from(effect.title).length > 80
        || !Array.isArray(effect.parts) || !effect.parts.length || effect.parts.length > 4) invalid();
      ids.add(effect.id);
      return { id: effect.id, title: effect.title.trim(), parts: effect.parts.map(part => {
        if (!object(part, ['file', 'anchor', 'width', 'origin', 'offset', 'opacity']) || typeof part.file !== 'string' || !filePattern.test(part.file)
          || typeof part.anchor !== 'string' || !Object.hasOwn(anchors, part.anchor) || !number(part.width, .01, 4)) invalid();
        const origin = part.origin === undefined ? [.5, .5] : part.origin;
        const offset = part.offset === undefined ? [0, 0] : part.offset;
        const opacity = part.opacity === undefined ? 1 : part.opacity;
        if (!pair(origin, 0, 1) || !pair(offset, -2, 2) || !number(opacity, 0, 1)) invalid();
        return { file: part.file, anchor: part.anchor, width: part.width, origin, offset, opacity };
      }) };
    });
  }
  function indicesFor(effect) {
    return [...new Set([...legacy, ...effect.parts.flatMap(part => anchors[part.anchor])])];
  }
  function placements(faces, effect, width, height, images) {
    const indices = indicesFor(effect), output = [];
    for (const face of faces.slice(0, 4)) {
      if (face.length < indices.length || face.some(p => !p || !number(p.x, -1, 2) || !number(p.y, -1, 2))) continue;
      const dx = (face[1].x - face[0].x) * width, dy = (face[1].y - face[0].y) * height;
      const distance = Math.hypot(dx, dy);
      if (distance < 2) continue;
      const angle = Math.atan2(dy, dx), cos = Math.cos(angle), sin = Math.sin(angle);
      effect.parts.forEach((part, index) => {
        const image = images.get(part.file), points = anchors[part.anchor].map(i => face[indices.indexOf(i)]);
        const x = points.reduce((sum, p) => sum + p.x, 0) / points.length * width;
        const y = points.reduce((sum, p) => sum + p.y, 0) / points.length * height;
        const w = part.width * distance, h = w * image.height / image.width;
        const localX = part.offset[0] * distance + (.5 - part.origin[0]) * w;
        const localY = part.offset[1] * distance + (.5 - part.origin[1]) * h;
        const center = [(x + cos * localX - sin * localY) / width, (y + sin * localX + cos * localY) / height];
        if (w / width > 2 || !center.every(c => number(c, -1, 2))) return;
        output.push({ type: 'sticker', effect: effect.id, part: index, center, width: w / width, angle });
      });
    }
    return output;
  }
  function draw(context, effect, elements, images, width, height) {
    for (const item of elements) {
      const part = effect.parts[item.part], image = images.get(part.file);
      const w = item.width * width, h = w * image.height / image.width;
      context.save();
      context.translate(item.center[0] * width, item.center[1] * height);
      context.rotate(item.angle); context.globalAlpha = part.opacity;
      context.drawImage(image, -w / 2, -h / 2, w, h); context.restore();
    }
  }
  class AssetCache {
    constructor(loader = loadPng) { this.loader = loader; this.entries = new Map(); this.queue = []; this.active = 0; }
    load(file, signal) {
      return new Promise((resolve, reject) => {
        const job = { file, signal, resolve, reject };
        job.abort = () => {
          const index = this.queue.indexOf(job);
          if (index >= 0) { this.queue.splice(index, 1); signal.removeEventListener('abort', job.abort); }
          reject(new Error('Sticker load cancelled'));
        };
        if (signal.aborted) { job.abort(); return; }
        signal.addEventListener('abort', job.abort, { once: true });
        this.queue.push(job); this.drain();
      });
    }
    drain() {
      while (this.active < 4 && this.queue.length) {
        const job = this.queue.shift();
        if (job.signal.aborted) { job.abort(); continue; }
        this.active++;
        let loading;
        try { loading = this.loader(job.file, { signal: job.signal }); }
        catch (error) { loading = Promise.reject(error); }
        Promise.resolve(loading).then(image => {
          if (job.signal.aborted) { image.close?.(); job.reject(new Error('Sticker load cancelled')); }
          else job.resolve(image);
        }, job.reject).finally(() => {
          job.signal.removeEventListener('abort', job.abort); this.active--; this.drain();
        });
      }
    }
    prepare(effect) {
      if (this.entries.has(effect.id)) {
        const entry = this.entries.get(effect.id); this.entries.delete(effect.id); this.entries.set(effect.id, entry);
        return entry.promise;
      }
      const entry = { images: new Map(), pixels: 0, disposed: false, controller: new AbortController() };
      this.entries.set(effect.id, entry);
      entry.promise = Promise.all([...new Set(effect.parts.map(p => p.file))].map(async file => {
        const image = await this.load(file, entry.controller.signal);
        if (entry.disposed) { image.close?.(); throw new Error('Sticker load cancelled'); }
        if (!Number.isInteger(image.width) || !Number.isInteger(image.height) || image.width < 1 || image.height < 1 || image.width > 1536 || image.height > 1536) {
          image.close?.(); throw new Error('Invalid PNG size');
        }
        entry.images.set(file, image); entry.pixels += image.width * image.height; this.trim();
      })).then(() => {
        if (entry.disposed) throw new Error('Sticker load cancelled');
        this.trim(); return entry.images;
      }).catch(error => {
        if (this.entries.get(effect.id) === entry) this.entries.delete(effect.id);
        this.dispose(entry); throw error;
      });
      this.trim(); return entry.promise;
    }
    dispose(entry) {
      entry.disposed = true;
      entry.controller.abort();
      for (const image of entry.images.values()) image.close?.();
      entry.images.clear(); entry.pixels = 0;
    }
    trim() {
      while (this.entries.size > 2 || [...this.entries.values()].reduce((sum, e) => sum + e.pixels, 0) > 8000000) {
        const [id, entry] = this.entries.entries().next().value;
        this.entries.delete(id); this.dispose(entry);
      }
    }
    clear() { for (const entry of this.entries.values()) this.dispose(entry); this.entries.clear(); }
  }
  async function loadPng(file, { signal } = {}) {
    if (!filePattern.test(file)) throw new Error('Invalid PNG filename');
    const url = new URL('resources/face-effects/stickers/' + encodeURIComponent(file), document.baseURI);
    const response = await fetch(url, { credentials: 'same-origin', signal });
    if (!response.ok || Number(response.headers.get('content-length')) > 2097152) throw new Error('Không tải được sticker PNG.');
    const blob = await response.blob();
    if (blob.size < 24 || blob.size > 2097152) throw new Error('Invalid PNG size');
    const header = new DataView(await blob.slice(0, 24).arrayBuffer());
    if (header.getUint32(0) !== 0x89504e47 || header.getUint32(4) !== 0x0d0a1a0a || header.getUint32(12) !== 0x49484452
      || header.getUint32(16) < 1 || header.getUint32(20) < 1 || header.getUint32(16) > 1536 || header.getUint32(20) > 1536) throw new Error('Invalid PNG header/size');
    if (signal?.aborted) throw new Error('Sticker load cancelled');
    const image = await createImageBitmap(blob);
    if (signal?.aborted) { image.close(); throw new Error('Sticker load cancelled'); }
    return image;
  }
  async function loadCatalog() {
    // This editable catalog must not reuse a fresh HTTP cache entry after adding effects.
    const response = await fetch(new URL('resources/face-effects/catalog.json', document.baseURI), { credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok || Number(response.headers.get('content-length')) > 65536) throw new Error('Invalid sticker catalog');
    const body = await response.text();
    if (new TextEncoder().encode(body).length > 65536) throw new Error('Invalid sticker catalog size');
    return validateCatalog(JSON.parse(body));
  }
  const api = { validateCatalog, indicesFor, placements, draw, AssetCache, loadCatalog, loadPng };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.HmuFaceStickers = api;
})(typeof globalThis === 'object' ? globalThis : this);
