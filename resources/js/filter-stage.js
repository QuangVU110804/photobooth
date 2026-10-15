(function () {
  'use strict';
  const stage = document.getElementById('hmu-filter-stage');
  if (!stage || !document.getElementById('kioskFrameSelector')) return;
  const colors = [
    ['plain', 'Ảnh gốc', 'Tự nhiên', ''],
    ['cssgram-inkwell', 'Inkwell', 'Đen trắng', 'inkwell'],
    ['cssgram-reyes', 'Reyes', 'Ấm nhẹ', 'reyes'],
    ['cssgram-moon', 'Moon', 'Hoài niệm', 'moon']
  ].filter(([id]) => id === 'plain' || (config.filters.enabled && !config.filters.disabled.includes(id)));
  const faces = [['none', 'Không hiệu ứng', 'Tự nhiên'], ['glasses', 'Kính thời trang', 'Theo khuôn mặt'], ['blush', 'Má hồng', 'Theo khuôn mặt']];
  const defaultColor = colors.some(([id]) => id === config.filters.defaults) ? config.filters.defaults : 'plain';
  const session = new window.HmuCaptureSession(defaultColor);
  const video = document.getElementById('hmu-filter-video');
  const ipcam = document.getElementById('hmu-filter-ipcam');
  const surface = document.getElementById('hmu-filter-surface');
  const overlay = document.getElementById('hmu-filter-overlay');
  const status = document.getElementById('hmu-filter-status');
  const shoot = document.getElementById('hmu-filter-shoot');
  const back = document.getElementById('hmu-filter-back');
  const palette = { ink: '#12243a', pink: '#f294ac', white: '#ffffff' };
  let revision = 0, cameraReady = false, frameReady = false, faceLoading = false;
  let faceAvailable = false, timer = null, worker = null, workerReady = null, nextId = 0;
  let previewPending = null, lastVideoTime = -1, lastFaces = [], finalEffects = null;
  let faceChoiceRevision = 0;
  const requests = new Map();

  function update() {
    surface.className = 'hmu-filter-surface ' + (colors.find(([id]) => id === session.color)?.[3] || '');
    if (config.preview.flip === 'flip-horizontal') surface.classList.add('mirror-x');
    if (config.preview.flip === 'flip-vertical') surface.classList.add('mirror-y');
    for (const button of stage.querySelectorAll('[data-color]')) button.setAttribute('aria-pressed', String(button.dataset.color === session.color));
    for (const button of stage.querySelectorAll('[data-face]')) {
      button.setAttribute('aria-pressed', String(button.dataset.face === session.face));
      button.disabled = button.dataset.face !== 'none' && (!faceAvailable || faceLoading);
    }
    shoot.disabled = !frameReady || faceLoading || (config.preview.camTakesPic && !config.dev.demo_images && !cameraReady);
    for (const button of stage.querySelectorAll('button')) button.classList.toggle('rotaryfocus', !button.disabled);
    document.getElementById('hmu-filter-choice').textContent = `${colors.find(([id]) => id === session.color)?.[1]} · ${faces.find(([id]) => id === session.face)?.[1]}`;
    draw(lastFaces);
  }
  function makeButtons(items, container, kind) {
    for (const [id, title, description] of items) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'hmu-filter-option';
      button.dataset[kind] = id;
      const label = document.createElement('strong'); label.textContent = title;
      const detail = document.createElement('span'); detail.textContent = description;
      button.append(label, detail);
      button.addEventListener('click', async () => {
        if (kind === 'color') { session.select(id, session.face); update(); return; }
        const choiceToken = ++faceChoiceRevision;
        faceLoading = id !== 'none';
        if (id !== 'none') {
          faceLoading = true; update(); status.textContent = 'Đang chuẩn bị hiệu ứng khuôn mặt…';
          const token = revision;
          try { await ensureWorker(); }
          catch (error) {
            if (token !== revision || choiceToken !== faceChoiceRevision) return;
            faceAvailable = false; status.textContent = 'Chưa thể bật hiệu ứng khuôn mặt. Bạn vẫn có thể chọn màu ảnh.';
            faceLoading = false; update(); return;
          }
          if (token !== revision || choiceToken !== faceChoiceRevision) return;
          faceLoading = false;
        }
        session.select(session.color, id);
        status.textContent = id === 'none' ? 'Camera đã sẵn sàng. Chọn màu ảnh và tạo dáng nhé.' : 'Hiệu ứng đang theo khuôn mặt của bạn.';
        update(); schedulePreview();
      });
      container.append(button);
    }
  }

  function disposeWorker(error = new Error('Face effects cancelled')) {
    worker?.terminate(); worker = null; workerReady = null;
    for (const item of requests.values()) { clearTimeout(item.timeout); item.reject(error); }
    requests.clear();
  }
  function askWorker(type, bitmap) {
    return new Promise((resolve, reject) => {
      const id = ++nextId;
      const timeout = setTimeout(() => { disposeWorker(new Error('Face detection timed out')); }, 30000);
      requests.set(id, { resolve, reject, timeout });
      try { worker.postMessage({ id, type, bitmap, timestamp: performance.now() }, bitmap ? [bitmap] : []); }
      catch (error) { requests.delete(id); clearTimeout(timeout); bitmap?.close(); reject(error); }
    });
  }
  function ensureWorker() {
    if (!workerReady) {
      const activeWorker = worker = new Worker(new URL('resources/js/face-effects-worker.js?v=2', document.baseURI));
      activeWorker.onmessage = ({ data }) => {
        const request = requests.get(data.id);
        if (!request) return;
        clearTimeout(request.timeout); requests.delete(data.id);
        data.error ? request.reject(new Error(data.error)) : request.resolve(data.faces || []);
      };
      activeWorker.onerror = () => { if (worker === activeWorker) disposeWorker(new Error('Face worker unavailable')); };
      workerReady = askWorker('init').catch((error) => { if (worker === activeWorker) disposeWorker(error); throw error; });
    }
    return workerReady;
  }

  // Geometry is normalized to the raw image, before camera flip or frame composition.
  function polygons(anchors, effect, width, height) {
    const output = [];
    for (const [left, right, cheekLeft, cheekRight] of anchors) {
      const dx = (right.x - left.x) * width, dy = (right.y - left.y) * height;
      const distance = Math.hypot(dx, dy);
      if (distance < 2) continue;
      const angle = Math.atan2(dy, dx);
      const shape = (cx, cy, local, color) => output.push({ color, points: local.map(([x, y]) => [
        (cx * width + (x * Math.cos(angle) - y * Math.sin(angle)) * distance) / width,
        (cy * height + (x * Math.sin(angle) + y * Math.cos(angle)) * distance) / height
      ]) });
      if (effect === 'glasses') {
        for (const fraction of [.22, .78]) {
          const cx = left.x + (right.x - left.x) * fraction, cy = left.y + (right.y - left.y) * fraction;
          shape(cx, cy, [[-.22,-.15],[.22,-.15],[.2,.13],[.12,.19],[-.12,.19],[-.2,.13]], 'ink');
          shape(cx, cy, [[-.14,-.08],[.06,-.08],[-.1,.03]], 'white');
        }
        shape((left.x + right.x) / 2, (left.y + right.y) / 2, [[-.12,-.04],[.12,-.04],[.12,.035],[-.12,.035]], 'ink');
      } else if (effect === 'blush') {
        const oval = Array.from({ length: 16 }, (_, i) => [.13 * Math.cos(i * Math.PI / 8), .07 * Math.sin(i * Math.PI / 8)]);
        for (const cheek of [cheekLeft, cheekRight]) shape(cheek.x, cheek.y, oval, 'pink');
      }
    }
    return output;
  }
  function draw(anchors) {
    if (!overlay.width || !overlay.height) return;
    const context = overlay.getContext('2d');
    context.clearRect(0, 0, overlay.width, overlay.height);
    for (const polygon of polygons(anchors, session.face, overlay.width, overlay.height)) {
      context.beginPath();
      polygon.points.forEach(([x, y], i) => i === 0 ? context.moveTo(x * overlay.width, y * overlay.height) : context.lineTo(x * overlay.width, y * overlay.height));
      context.closePath(); context.fillStyle = palette[polygon.color]; context.fill();
    }
  }
  async function bitmapFor(source, maxWidth) {
    const width = source.videoWidth || source.naturalWidth;
    const height = source.videoHeight || source.naturalHeight;
    if (!width || !height) throw new Error('Image not ready');
    return createImageBitmap(source, { resizeWidth: Math.min(maxWidth, width), resizeHeight: Math.max(1, Math.round(height * Math.min(maxWidth, width) / width)) });
  }
  function stopLoop() { clearTimeout(timer); timer = null; }
  function canPreview() {
    return !stage.hidden && session.face !== 'none' && cameraReady && !document.hidden;
  }
  function schedulePreview() {
    stopLoop();
    if (!canPreview()) { lastFaces = []; draw([]); return; }
    // Button and visibility events may reschedule while a worker reply is pending.
    // Let that request finish and schedule the next frame instead of queueing work.
    if (previewPending) return;
    timer = setTimeout(async () => {
      timer = null;
      if (!canPreview() || previewPending) return;
      const token = revision;
      let pending = null;
      try {
        if (video.currentTime !== lastVideoTime) {
          lastVideoTime = video.currentTime;
          // One inference in flight, at most ~8fps; the UI keeps native video FPS.
          pending = bitmapFor(video, 480).then((bitmap) => {
            if (token !== revision || !canPreview()) { bitmap.close(); return null; }
            return askWorker('video', bitmap);
          });
          previewPending = pending;
          const anchors = await pending;
          if (anchors && token === revision && canPreview()) { lastFaces = anchors; draw(anchors); status.textContent = anchors.length ? 'Hiệu ứng đang theo khuôn mặt của bạn.' : 'Chưa thấy khuôn mặt. Hãy nhìn vào camera.'; }
        }
      } catch (error) {
        if (token === revision && canPreview()) { faceAvailable = false; session.select(session.color, 'none'); status.textContent = 'Hiệu ứng khuôn mặt chưa khả dụng. Màu ảnh vẫn hoạt động.'; update(); }
      } finally {
        if (previewPending === pending) previewPending = null;
        schedulePreview();
      }
    }, 125);
  }
  function hide() {
    revision++; stopLoop(); stage.hidden = true; document.body.classList.remove('hmu-filter-open');
    video.pause(); video.srcObject = null; ipcam.hidden = true; ipcam.removeAttribute('src');
  }
  async function open() {
    const token = ++revision;
    stage.hidden = false; document.body.classList.add('hmu-filter-open');
    finalEffects = null; lastFaces = []; lastVideoTime = -1; cameraReady = false; frameReady = false; faceAvailable = false; faceLoading = false;
    document.getElementById('hmu-filter-title').focus(); update();
    window.rotaryController?.focusSet('#hmu-filter-stage');
    status.textContent = 'Đang đồng bộ khung và chuẩn bị camera…';
    try {
      await window.hmuFrameSelection?.ready();
      if (token !== revision) return;
      api.frame = window.hmuFrameSelection?.filename || '';
      frameReady = true; update();
    } catch (error) {
      if (token === revision) { status.textContent = 'Chưa lưu được khung. Hãy quay lại và chọn lại khung ảnh.'; update(); }
      return;
    }
    try {
      const stream = await photoboothPreview.prepareFilterPreview();
      if (token !== revision) return;
      if (stream) {
        video.srcObject = stream;
        await video.play();
        if (token !== revision) return;
        cameraReady = video.videoWidth > 0;
        // Overlay geometry is normalized; a bounded surface also fits 4K cameras.
        overlay.width = Math.min(960, video.videoWidth);
        overlay.height = Math.max(1, Math.round(video.videoHeight * overlay.width / video.videoWidth));
        document.getElementById('hmu-filter-camera').style.aspectRatio = `${video.videoWidth} / ${video.videoHeight}`;
        document.getElementById('hmu-filter-camera').style.setProperty('--hmu-camera-ratio', video.videoWidth / video.videoHeight);
        faceAvailable = cameraReady && !!window.Worker && !!window.OffscreenCanvas && !!window.createImageBitmap;
        status.textContent = 'Camera đã sẵn sàng. Chọn hiệu ứng và tạo dáng nhé.';
      } else {
        ipcam.src = config.preview.url; ipcam.hidden = false;
        status.textContent = 'Xem trước camera mạng. Hiệu ứng khuôn mặt cần camera trên thiết bị.';
      }
    } catch (error) {
      if (token !== revision) return;
      status.textContent = config.preview.camTakesPic && !config.dev.demo_images
        ? 'Không mở được camera. Kiểm tra quyền camera rồi quay lại để thử lại.'
        : 'Chưa mở được camera xem trước. Bạn vẫn có thể chọn màu và chụp bằng máy ảnh đã cấu hình.';
    }
    if (token === revision) update();
  }
  const api = window.hmuFilterStage = {
    frame: '',
    request(style, continuation) {
      const previousPhase = session.phase;
      const permitted = session.request(style, continuation);
      if (!permitted && previousPhase === 'idle' && session.phase === 'selecting') open();
      return !permitted;
    },
    get color() { return session.color; },
    get face() { return session.face; },
    get active() { return !stage.hidden; },
    finish() { session.finish(); },
    async prepareEffects(style, file) {
      if (session.face === 'none') return {};
      if (finalEffects?.file === file) return finalEffects.value;
      stopLoop();
      if (previewPending) await previewPending.catch(() => {});
      await ensureWorker();
      const value = {};
      for (const name of [file]) {
        const image = new Image(); image.src = `${config.foldersJS.tmp}/${encodeURIComponent(name)}?effects=${Date.now()}`;
        await image.decode();
        const anchors = await askWorker('image', await bitmapFor(image, 960));
        value[name] = polygons(anchors, session.face, image.naturalWidth, image.naturalHeight);
      }
      finalEffects = { file, value };
      return value;
    }
  };
  if (window.ResizeObserver) new ResizeObserver(() => {
    if (!stage.hidden) stage.style.paddingBottom = `${stage.querySelector('.hmu-filter-confirm').getBoundingClientRect().height + 24}px`;
  }).observe(stage.querySelector('.hmu-filter-confirm'));
  makeButtons(colors, document.getElementById('hmu-color-filters'), 'color');
  makeButtons(faces, document.getElementById('hmu-face-filters'), 'face');
  shoot.addEventListener('click', () => {
    if (shoot.disabled || session.phase !== 'selecting') return;
    session.confirm(); hide();
    if (config.preview.mode === 'none') photoboothPreview.stopVideo();
    photoBooth.thrill(session.style);
  });
  back.addEventListener('click', () => {
    session.cancel(); hide(); disposeWorker();
    window.remoteBuzzerClient?.inProgress(false);
    photoboothPreview.stopVideo();
    if (config.preview.killcmd) photoboothPreview.runCmd('stop');
    const wrapper = document.getElementById('wrapper');
    wrapper.style.removeProperty('background');
    wrapper.style.removeProperty('background-color');
    wrapper.style.removeProperty('background-image');
    document.getElementById('result').style.display = 'none';
    const startPage = document.getElementById('start');
    startPage.classList.add('open');
    startPage.style.removeProperty('display');
    window.rotaryController?.focusSet('#start');
    document.querySelector('.hmu-frame-card[aria-pressed="true"]')?.focus();
  });
  document.addEventListener('visibilitychange', () => { if (document.hidden) stopLoop(); else schedulePreview(); });
  document.addEventListener('keydown', (event) => {
    if (stage.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); back.click(); return; }
    if (event.key !== 'Tab') return;
    const buttons = [...stage.querySelectorAll('button:not(:disabled)')];
    const first = buttons[0], last = buttons.at(-1);
    if (event.shiftKey && (document.activeElement === first || document.activeElement.id === 'hmu-filter-title')) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  });
  window.addEventListener('pagehide', () => { stopLoop(); disposeWorker(); photoboothPreview.stopVideo(); });
})();
