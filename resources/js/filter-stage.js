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
  const beauty = window.HmuSkinBeauty;
  const beautySurface = document.getElementById('hmu-beauty-surface');
  const beautySettings = window.HmuBeautySettings;
  const beautyPanel = new window.HmuBeautyPanel(document.getElementById('hmu-beauty-controls'), changeBeauty);
  const beautyCompare = document.getElementById('hmu-beauty-compare');
  const beautyReset = document.getElementById('hmu-beauty-reset');
  const beautyHelp = document.getElementById('hmu-beauty-help');
  const beautySplit = document.getElementById('hmu-beauty-split');
  const beautyMask = document.getElementById('hmu-beauty-mask');
  const beautyMirror = document.getElementById('hmu-beauty-mirror');
  const beautySplitRange = document.getElementById('hmu-beauty-split-range');
  const status = document.getElementById('hmu-filter-status');
  const shoot = document.getElementById('hmu-filter-shoot');
  const back = document.getElementById('hmu-filter-back');
  const camera = document.getElementById('hmu-filter-camera');
  const cameraHome = camera.parentElement;
  const countdown = document.getElementById('hmu-countdown-preview');
  const frameImage = document.getElementById('hmu-preview-frame');
  const sourceSelect = document.getElementById('hmu-camera-source');
  const deviceSelect = document.getElementById('hmu-camera-device');
  const externalPreviewMode = config.preview.mode;
  let source = config.preview.camTakesPic ? 'browser' : 'external';
  try {
    const saved = sessionStorage.getItem('hmu-camera-source');
    if (saved === 'browser' || saved === 'external') source = saved;
  } catch (error) { /* Camera selection still works without browser storage. */ }
  sourceSelect.value = source;
  function applySource() {
    config.preview.camTakesPic = source === 'browser';
    config.preview.mode = source === 'browser' ? 'device_cam' : externalPreviewMode;
    document.getElementById('hmu-camera-help').textContent = source === 'browser'
      ? 'Ảnh được chụp từ camera đang xem trước. Có thể chọn webcam hoặc camera USB.'
      : 'Ảnh chụp bằng máy ảnh nối với máy chạy booth. Cần cấu hình lệnh digiCamControl/gphoto2 trong quản trị; camera xem trước có thể là webcam riêng.';
  }
  applySource();
  const palette = { ink: '#12243a', pink: '#f294ac', white: '#ffffff' };
  let revision = 0, cameraReady = false, frameReady = false, faceLoading = false;
  let faceAvailable = false, timer = null, worker = null, workerReady = null, nextId = 0;
  let previewPending = null, lastVideoTime = -1, lastFaces = [], finalEffects = null;
  let faceChoiceRevision = 0;
  let beautyRenderer = null, beautyLoading = false, beautyAvailable = !!beauty;
  let beautyFrame = null, beautyTime = -1, beautyCompared = false, finalBeauty = null;
  let finalBeautyImage = null, splitEnabled = false, maskEnabled = false;
  const requests = new Map();
  const stickers = window.HmuFaceStickers;
  const stickerEffects = new Map(), unavailableStickers = new Set();
  const stickerCache = stickers ? new stickers.AssetCache() : null;
  let catalogPromise = null, stickerImages = null;

  function hasBeauty() { return beautySettings.isActive(session.beauty); }
  function detectionIndices() {
    const effect = stickerEffects.get(session.face);
    const prefix = effect ? stickers.indicesFor(effect) : [33, 263, 93, 323];
    return hasBeauty() ? beauty.indices(prefix) : prefix;
  }
  function stopBeautyPreview() {
    if (beautyFrame !== null) cancelAnimationFrame(beautyFrame);
    beautyFrame = null; beautyTime = -1; beautySurface.hidden = true;
  }
  function releaseBeauty() { stopBeautyPreview(); beautyRenderer?.dispose(); beautyRenderer = null; }
  function failBeauty() {
    releaseBeauty(); beautyAvailable = false; beautyLoading = false;
    // Fail closed for beauty; sticker and camera state remain usable.
    if (session.phase === 'selecting') session.selectBeauty(beautySettings.preset('off'));
    beautyHelp.textContent = 'Làm đẹp chưa khả dụng trên thiết bị này. Bạn vẫn có thể chọn màu ảnh và sticker.';
    update();
  }
  function startBeautyPreview() {
    if (beautyFrame !== null || !beautyRenderer || !hasBeauty() || beautyLoading || !cameraReady || document.hidden || (stage.hidden && countdown.hidden)) return;
    const tick = timestamp => {
      beautyFrame = null;
      if (!beautyRenderer || !hasBeauty() || beautyLoading || !cameraReady || document.hidden || (stage.hidden && countdown.hidden)) { stopBeautyPreview(); return; }
      try {
        // The raw video remains the capture source; only this preview is retouched.
        if (timestamp - beautyTime >= 33 && video.currentTime !== beautyRenderer.lastVideoTime) {
          beautyRenderer.view.compare = beautyCompared ? 1.2 : splitEnabled ? (config.preview.flip === 'flip-horizontal' ? 1-Number(beautySplitRange.value)/100 : Number(beautySplitRange.value)/100) : -1;
          beautyRenderer.view.showMask = maskEnabled && !beautyCompared;
          beautyRenderer.render(video, session.beauty); beautyRenderer.lastVideoTime = video.currentTime; beautyTime = timestamp;
          beautySurface.hidden = false;
        }
      } catch (error) { failBeauty(); return; }
      beautyFrame = requestAnimationFrame(tick);
    };
    beautyFrame = requestAnimationFrame(tick);
  }
  function updateBeautyMask(anchors) {
    if (beautyRenderer) beautyRenderer.setFaces(hasBeauty() ? anchors : [], detectionIndices());
  }
  async function changeBeauty(settings) {
    if (session.phase !== 'selecting') return;
    if (beautyRenderer && hasBeauty() && beautySettings.isActive(settings) && !beautyLoading) {
      session.selectBeauty(settings); finalBeautyImage = null; finalEffects = null; beautyCompared = false;
      beautyRenderer.lastVideoTime = -1; update(); startBeautyPreview(); return;
    }
    const token = revision, choiceToken = ++faceChoiceRevision;
    stopLoop(); stopBeautyPreview(); lastFaces = []; finalBeauty = null;
    beautyCompared = false; session.selectBeauty(settings);
    updateBeautyMask([]); beautyLoading = hasBeauty(); update();
    if (!hasBeauty()) { releaseBeauty(); beautyHelp.textContent = 'Mặc định tắt. Có thể dùng cùng màu ảnh và sticker.'; schedulePreview(); return; }
    beautyHelp.textContent = 'Đang chuẩn bị làm đẹp da…';
    try {
      await ensureWorker();
      if (token !== revision || choiceToken !== faceChoiceRevision) return;
      if (!beautyRenderer) {
        const renderer = await beauty.createRenderer(beautySurface, failBeauty);
        if (token !== revision || choiceToken !== faceChoiceRevision) { renderer.dispose(); return; }
        beautyRenderer = renderer;
      }
      beautyLoading = false; beautyHelp.textContent = 'Tự điều chỉnh từng nhóm. Các lựa chọn sẽ được áp dụng vào ảnh nhận về.';
      update(); schedulePreview(); startBeautyPreview();
    } catch (error) { if (token === revision && choiceToken === faceChoiceRevision) failBeauty(); }
  }

  function ensureStickerCatalog() {
    if (!stickers || catalogPromise) return;
    catalogPromise = stickers.loadCatalog().then((effects) => {
      const items = effects.map((effect) => {
        stickerEffects.set(effect.id, effect);
        const item = [effect.id, effect.title, 'Sticker theo khuôn mặt']; faces.push(item); return item;
      });
      makeButtons(items, document.getElementById('hmu-face-filters'), 'face'); update();
    }).catch(() => { catalogPromise = null; /* Existing effects remain available. */ });
  }

  function update() {
    surface.className = 'hmu-filter-surface ' + (colors.find(([id]) => id === session.color)?.[3] || '');
    if (config.preview.flip === 'flip-horizontal') surface.classList.add('mirror-x');
    if (config.preview.flip === 'flip-vertical') surface.classList.add('mirror-y');
    for (const button of stage.querySelectorAll('[data-color]')) button.setAttribute('aria-pressed', String(button.dataset.color === session.color));
    for (const button of stage.querySelectorAll('[data-face]')) {
      button.setAttribute('aria-pressed', String(button.dataset.face === session.face));
      button.disabled = beautyLoading || (button.dataset.face !== 'none' && (!faceAvailable || faceLoading || unavailableStickers.has(button.dataset.face)));
    }
    shoot.disabled = !frameReady || faceLoading || beautyLoading || (config.preview.camTakesPic && !config.dev.demo_images && !cameraReady);
    beautyPanel.render(session.beauty,!faceAvailable || !beautyAvailable || beautyLoading || faceLoading);
    beautyCompare.disabled = !hasBeauty() || beautyLoading;
    beautyReset.disabled = faceLoading || (!hasBeauty() && !beautyLoading);
    beautySplit.disabled = beautyMask.disabled = beautyCompare.disabled;
    beautySplit.setAttribute('aria-pressed',String(splitEnabled));
    beautyMask.setAttribute('aria-pressed',String(maskEnabled));
    beautyMirror.setAttribute('aria-pressed',String(config.preview.flip === 'flip-horizontal'));
    beautyCompare.setAttribute('aria-pressed', String(beautyCompared));
    beautyCompare.textContent = 'Giữ để xem ảnh trước';
    for (const button of stage.querySelectorAll('button, select')) button.classList.toggle('rotaryfocus', !button.disabled);
    document.getElementById('hmu-filter-choice').textContent = `${colors.find(([id]) => id === session.color)?.[1]} · ${faces.find(([id]) => id === session.face)?.[1]}` + (hasBeauty() ? ` · ${beautySettings.PRESETS.find(p=>p.id===session.beauty.preset)?.name || 'Làm đẹp tùy chỉnh'}` : '');
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
        stopLoop(); lastFaces = []; updateBeautyMask([]); draw([]);
        if (id === 'none') { stickerCache?.clear(); stickerImages = null; }
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
          const effect = stickerEffects.get(id);
          if (effect) {
            try {
              const images = await stickerCache.prepare(effect);
              if (token !== revision || choiceToken !== faceChoiceRevision) return;
              stickerImages = images;
            } catch (error) {
              if (token !== revision || choiceToken !== faceChoiceRevision) return;
              unavailableStickers.add(id); faceLoading = false; session.select(session.color, 'none');
              status.textContent = 'Không tải được sticker này. Hãy chọn hiệu ứng khác.';
              update(); schedulePreview(); return;
            }
          }
          faceLoading = false;
        }
        session.select(session.color, id);
        status.textContent = id === 'none' ? 'Camera đã sẵn sàng. Chọn màu ảnh và tạo dáng nhé.' : 'Hiệu ứng đang theo khuôn mặt của bạn.';
        update(); schedulePreview(); startBeautyPreview();
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
      try { worker.postMessage({ id, type, bitmap, timestamp: performance.now(), indices: detectionIndices(), skin: hasBeauty() }, bitmap ? [bitmap] : []); }
      catch (error) { requests.delete(id); clearTimeout(timeout); bitmap?.close(); reject(error); }
    });
  }
  function ensureWorker() {
    if (!workerReady) {
      const activeWorker = worker = new Worker(new URL('resources/js/face-effects-worker.js?v=4', document.baseURI));
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
    const effect = stickerEffects.get(session.face);
    if (hasBeauty() && beautyRenderer && !beautyCompared && !splitEnabled && !maskEnabled) anchors = beautyRenderer.mapFaces(anchors,effect ? stickers.indicesFor(effect).length : 4,session.beauty,overlay.width,overlay.height);
    overlay.hidden = hasBeauty() && (beautyCompared || splitEnabled || maskEnabled);
    if (effect) {
      if (stickerImages?.size) stickers.draw(context, effect, stickers.placements(anchors, effect, overlay.width, overlay.height, stickerImages), stickerImages, overlay.width, overlay.height);
      return;
    }
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
    return (!stage.hidden || !countdown.hidden) && (session.face !== 'none' || hasBeauty()) && !faceLoading && !beautyLoading && cameraReady && !document.hidden;
  }
  function schedulePreview() {
    stopLoop();
    if (!canPreview()) { lastFaces = []; updateBeautyMask([]); draw([]); return; }
    // Button and visibility events may reschedule while a worker reply is pending.
    // Let that request finish and schedule the next frame instead of queueing work.
    if (previewPending) return;
    timer = setTimeout(async () => {
      timer = null;
      if (!canPreview() || previewPending) return;
      const token = revision;
      const choiceToken = faceChoiceRevision;
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
          if (anchors && token === revision && choiceToken === faceChoiceRevision && canPreview()) { lastFaces = anchors; updateBeautyMask(anchors); draw(anchors); status.textContent = anchors.length ? 'Hiệu ứng đang theo khuôn mặt của bạn.' : 'Chưa thấy khuôn mặt. Hãy nhìn vào camera.'; }
        }
      } catch (error) {
        if (token === revision && canPreview()) { faceAvailable = false; session.select(session.color, 'none'); if (hasBeauty()) failBeauty(); status.textContent = 'Hiệu ứng khuôn mặt chưa khả dụng. Màu ảnh vẫn hoạt động.'; update(); }
      } finally {
        if (previewPending === pending) previewPending = null;
        schedulePreview();
      }
    }, 125);
  }
  function hide(keepPreview = false) {
    revision++; stopLoop(); stage.hidden = true; document.body.classList.remove('hmu-filter-open');
    if (!keepPreview) { stopBeautyPreview(); video.pause(); video.srcObject = null; ipcam.hidden = true; ipcam.removeAttribute('src'); }
  }
  async function open() {
    const token = ++revision;
    stage.hidden = false; document.body.classList.add('hmu-filter-open');
    ensureStickerCatalog();
    finalEffects = null; finalBeauty = null; finalBeautyImage = null; lastFaces = []; lastVideoTime = -1; cameraReady = false; frameReady = false; faceAvailable = false; faceLoading = false; beautyLoading = false; beautyCompared = false; splitEnabled = false; maskEnabled = false;
    beautySplitRange.parentElement.hidden = true;
    stopBeautyPreview(); updateBeautyMask([]);
    document.getElementById('hmu-filter-title').focus(); update();
    window.rotaryController?.focusSet('#hmu-filter-stage');
    status.textContent = 'Đang đồng bộ khung và chuẩn bị camera…';
    try {
      await window.hmuFrameSelection?.ready();
      if (token !== revision) return;
      api.frame = window.hmuFrameSelection?.filename || '';
      frameImage.hidden = !api.frame;
      if (api.frame) {
        frameImage.src = new URL('resources/img/frames/' + encodeURIComponent(api.frame), document.baseURI).href;
        await frameImage.decode();
        if (token !== revision) return;
      }
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
        const devices = await photoboothPreview.listDevices();
        if (token !== revision) return;
        const activeId = stream.getVideoTracks()[0]?.getSettings().deviceId;
        deviceSelect.replaceChildren(new Option('Camera mặc định', ''));
        devices.forEach((device, i) => deviceSelect.add(new Option(device.label || `Camera ${i + 1}`, device.deviceId)));
        deviceSelect.value = activeId || '';
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
    if (token === revision) {
      update();
      if (hasBeauty() && faceAvailable) await changeBeauty(session.beauty);
      else if (!faceAvailable) beautyHelp.textContent = 'Làm đẹp cần camera xem trước trên thiết bị.';
    }
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
    get hasEffects() { return session.face !== 'none' || hasBeauty(); },
    get preparedBeauty() { return finalBeauty; },
    get preparedBeautyImage() { return finalBeautyImage; },
    get active() { return !stage.hidden; },
    get captureSource() { return source; },
    get captureVideo() { return video; },
    get countingDown() { return !countdown.hidden; },
    async startCountdown(retry = 0) {
      const live = photoboothPreview.stream?.getVideoTracks().some(track => track.readyState === 'live');
      if (!config.dev.demo_images && ((source === 'browser' && !live) || (source === 'external' && retry > 0))) {
        try {
          const stream = await photoboothPreview.prepareFilterPreview();
          if (stream) { video.srcObject = stream; await video.play(); }
        } catch (error) {
          if (source === 'browser') throw error;
          status.textContent = 'Chưa mở được camera xem trước. Ảnh sẽ được chụp bằng máy ảnh ngoài.';
        }
      }
      countdown.append(camera); countdown.hidden = false;
      document.body.classList.add('hmu-counting-down');
      beautyCompared = false; splitEnabled = false; maskEnabled = false; overlay.hidden = false; schedulePreview(); startBeautyPreview();
    },
    endCountdown() {
      countdown.hidden = true; cameraHome.prepend(camera);
      document.body.classList.remove('hmu-counting-down');
      stopLoop(); stopBeautyPreview();
    },
    finish() { api.endCountdown(); session.finish(); video.pause(); video.srcObject = null; stickerCache?.clear(); stickerImages = null; finalBeautyImage = null; releaseBeauty(); },
    async prepareEffects(style, file) {
      if (!api.hasEffects) return {};
      if (finalEffects?.file === file) return finalEffects.value;
      stopLoop();
      if (previewPending) await previewPending.catch(() => {});
      await ensureWorker();
      const effect = stickerEffects.get(session.face);
      const images = effect ? await stickerCache.prepare(effect) : null;
      const value = {};
      for (const name of [file]) {
        const image = new Image(); image.src = `${config.foldersJS.tmp}/${encodeURIComponent(name)}?effects=${Date.now()}`;
        await image.decode();
        let anchors = await askWorker('image', await bitmapFor(image, 960));
        if (hasBeauty()) {
          if (!beautyRenderer) beautyRenderer = await beauty.createRenderer(beautySurface,failBeauty);
          beautyRenderer.setFaces(anchors,detectionIndices(),true);
          anchors = beautyRenderer.mapFaces(anchors,effect ? stickers.indicesFor(effect).length : 4,session.beauty,image.naturalWidth,image.naturalHeight);
          // Freeze the still's anchors before async encoding can pause tracking.
          finalBeautyImage = await beautyRenderer.export(image,session.beauty);
        }
        value[name] = effect
          ? stickers.placements(anchors, effect, image.naturalWidth, image.naturalHeight, images)
          : polygons(anchors, session.face, image.naturalWidth, image.naturalHeight);
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
  beautyReset.addEventListener('click', () => changeBeauty({values:beautySettings.defaults(),colors:{...beautySettings.DEFAULT_COLORS},preset:'custom'}));
  const compareState = value => {
    beautyCompared=value;beautyCompare.setAttribute('aria-pressed',String(value));
    if(beautyRenderer)beautyRenderer.lastVideoTime=-1;draw(lastFaces);
  };
  beautyCompare.addEventListener('pointerdown',event=>{event.preventDefault();beautyCompare.setPointerCapture(event.pointerId);compareState(true);});
  for(const type of ['pointerup','pointercancel','lostpointercapture','blur'])beautyCompare.addEventListener(type,()=>compareState(false));
  beautyCompare.addEventListener('keydown',event=>{if(event.key===' '||event.key==='Enter'){event.preventDefault();compareState(true);}});
  beautyCompare.addEventListener('keyup',()=>compareState(false));
  beautySplit.addEventListener('click',()=>{splitEnabled=!splitEnabled;maskEnabled=false;beautySplitRange.parentElement.hidden=!splitEnabled;if(beautyRenderer)beautyRenderer.lastVideoTime=-1;update();});
  beautyMask.addEventListener('click',()=>{maskEnabled=!maskEnabled;splitEnabled=false;beautySplitRange.parentElement.hidden=true;if(beautyRenderer)beautyRenderer.lastVideoTime=-1;update();});
  beautyMirror.addEventListener('click',()=>{config.preview.flip=config.preview.flip==='flip-horizontal'?'off':'flip-horizontal';if(beautyRenderer)beautyRenderer.lastVideoTime=-1;update();});
  beautySplitRange.addEventListener('input',()=>{if(beautyRenderer)beautyRenderer.lastVideoTime=-1;});
  shoot.addEventListener('click', () => {
    if (shoot.disabled || session.phase !== 'selecting') return;
    session.confirm(); hide(true);
    photoBooth.thrill(session.style);
  });
  sourceSelect.addEventListener('change', async () => {
    if (session.phase !== 'selecting') return;
    source = sourceSelect.value; applySource();
    try { sessionStorage.setItem('hmu-camera-source', source); } catch (error) { /* Optional persistence. */ }
    hide(); photoboothPreview.stopVideo();
    await open();
  });
  deviceSelect.addEventListener('change', async () => {
    if (session.phase !== 'selecting') return;
    const deviceId = deviceSelect.value;
    const token = ++revision; stopLoop(); cameraReady = false; update();
    await photoboothPreview.selectDevice(deviceId).catch(() => {});
    if (session.phase === 'selecting' && token === revision) await open();
  });
  back.addEventListener('click', () => {
    session.cancel(); hide(); disposeWorker();
    stickerCache?.clear(); stickerImages = null; releaseBeauty();
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
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { stopLoop(); stopBeautyPreview(); lastFaces = []; updateBeautyMask([]); }
    else { schedulePreview(); startBeautyPreview(); }
  });
  document.addEventListener('keydown', (event) => {
    if (stage.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); back.click(); return; }
    if (event.key !== 'Tab') return;
    const buttons = [...stage.querySelectorAll('button:not(:disabled), select:not(:disabled), input:not(:disabled)')];
    const first = buttons[0], last = buttons.at(-1);
    if (event.shiftKey && (document.activeElement === first || document.activeElement.id === 'hmu-filter-title')) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  });
  window.addEventListener('pagehide', () => { stopLoop(); releaseBeauty(); disposeWorker(); stickerCache?.clear(); photoboothPreview.stopVideo(); });
})();
