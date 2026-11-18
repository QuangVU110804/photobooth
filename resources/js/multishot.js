(function () {
  'use strict';

  async function collect(count, flow) {
    async function attempt(work, slot) {
      for (;;) {
        try { return { value: await work() }; }
        catch (error) { if (!await flow.retry(error, slot)) return null; }
      }
    }
    for (let slot = 0; slot < count; slot++) {
      const id = flow.requestId(slot);
      const raw = await attempt(() => flow.capture(slot, id), slot);
      if (!raw) return null;
      if (!await attempt(() => flow.process(slot, raw.value), slot)) return null;
      flow.progress(slot);
    }
    return (await attempt(flow.complete, count))?.value ?? null;
  }

  if (typeof module === 'object' && module.exports) { module.exports = collect; return; }
  const progressPanel = document.getElementById('hmu-multishot-progress');
  if (!progressPanel) return;
  const progressText = progressPanel.querySelector('[role="status"]');
  const sheet = progressPanel.querySelector('.hmu-multishot-sheet');
  const retryButton = progressPanel.querySelector('[data-multishot-retry]');
  const cancelButton = progressPanel.querySelector('[data-multishot-cancel]');
  const stage = window.hmuFilterStage;
  const camera = document.getElementById('hmu-filter-camera');
  const loader = document.getElementById('loader');
  const counter = document.getElementById('counter');
  loader.append(progressPanel);
  const wait = (ms) => new Promise(resolve => setTimeout(resolve, ms));
  const endpoint = 'api/multishot.php';

  function url(action, values = {}) {
    return endpoint + '?' + new URLSearchParams({ action, ...values });
  }
  async function request(action, values, write = false) {
    const response = write
      ? await fetch(url(action), { method: 'POST', body: new URLSearchParams(values), signal: AbortSignal.timeout(150000) })
      : await fetch(url(action, values), { signal: AbortSignal.timeout(150000) });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.error) {
      const error = new Error(result.error || 'Mất kết nối với Photobooth. Hãy thử lại.');
      error.status = response.status;
      throw error;
    }
    return result;
  }
  function fitCamera(rect) {
    camera.style.setProperty('--hmu-multishot-ratio', rect[2] / rect[3]);
  }


  function makeSheet(frame) {
    sheet.replaceChildren();
    sheet.style.aspectRatio = frame.width + ' / ' + frame.height;
    for (const [x, y, w, h] of frame.slots) {
      const cell = document.createElement('span');
      Object.assign(cell.style, {
        left: (100 * x / frame.width) + '%', top: (100 * y / frame.height) + '%',
        width: (100 * w / frame.width) + '%', height: (100 * h / frame.height) + '%',
      });
      cell.textContent = String(sheet.childElementCount + 1);
      sheet.append(cell);
    }
    const framePreview = new Image();
    framePreview.src = frame.preview;
    framePreview.alt = '';
    sheet.append(framePreview);
  }
  function retry(error, slot, count, allowCancel = true) {
    progressText.textContent = (slot < count ? 'Ảnh ' + (slot + 1) + '/' + count + ': ' : '') + error.message;
    retryButton.hidden = false;
    cancelButton.hidden = !allowCancel;
    retryButton.focus();
    return new Promise(resolve => {
      const finish = (choice) => {
        retryButton.onclick = cancelButton.onclick = null;
        retryButton.hidden = cancelButton.hidden = true;
        progressPanel.focus();
        resolve(choice);
      };
      retryButton.onclick = () => finish(true);
      cancelButton.onclick = () => finish(false);
    });
  }
  async function shoot(slot, count, source, repeat) {
    progressText.textContent = 'Ảnh ' + (slot + 1) + '/' + count;
    await stage.startCountdown(slot || repeat ? 1 : 0);
    const seconds = stage.countdownSeconds;
    const trigger = Math.max(0, seconds - Number(config.picture.cntdwn_offset || 0));
    const stopLead = Number(config.preview.stop_time || 0);
    const stopAt = seconds > stopLead ? seconds - stopLead : seconds;
    let stopPreview;
    if (config.get_request.countdown) photoboothTools.getRequest(config.get_request.server + '/' + config.get_request.picture);
    try {
      for (let time = 0; time <= trigger; time++) {
        counter.textContent = String(seconds - time);
        if (source === 'external' && config.preview.killcmd && (time >= stopAt || time === trigger) && !stopPreview) {
          stopPreview = Promise.resolve(photoboothPreview.runCmd('stop')).then(() => null, error => error || new Error('Preview stop failed'));
        }
        if (time < trigger) await wait(1000);
      }
      if (stopPreview && await stopPreview) throw new Error('Không dừng được chế độ xem trước của máy ảnh ngoài.');
      if (config.ui.shutter_animation) photoBooth.shutter.start();
      if (source !== 'browser' || config.dev.demo_images) return '';
      const video = stage.captureVideo;
      if (!video.videoWidth || !video.videoHeight || !photoboothPreview.stream?.getVideoTracks().some(track => track.readyState === 'live')) {
        throw new Error('Camera đã ngắt kết nối.');
      }
      const canvas = document.createElement('canvas');
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      canvas.getContext('2d').drawImage(video, 0, 0);
      return canvas.toDataURL('image/jpeg');
    } finally {
      counter.textContent = '';
      stage.endCountdown();
      if (source === 'external' && config.preview.killcmd) photoboothPreview.stopVideo();
    }
  }
  async function begin(frame) {
    if (!photoBooth.beginMultishot()) return;
    progressPanel.hidden = false;
    progressPanel.focus();
    progressText.textContent = 'Đang chuẩn bị bộ ảnh…';
    makeSheet(frame);
    loader.classList.add('open');
    const source = stage.captureSource;
    let batch;
    let result = null;
    let prepared;
    const submitted = new Set();
    try {
      for (;;) {
        try {
          batch = await request('start', { frame: frame.id, source, flip: config.preview.flip }, true);
          break;
        } catch (error) { if (!await retry(error, 0, frame.slots.length)) return; }
      }
      const id = batch.id;
      result = await collect(batch.count, {
        requestId: () => Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join(''),
        retry: (error, slot) => retry(error, slot, batch.count),
        capture: async (slot, key) => {
          const values = { id, slot, request: key };
          const repeated = submitted.has(slot);
          if (repeated) {
            const state = (await request('status', { id })).shots[slot];
            if (state === 'raw' || state === 'edited') return (await request('capture', values, true)).file;
          }
          fitCamera(frame.slots[slot]);
          const canvasimg = await shoot(slot, batch.count, source, repeated);
          // A lost response is reconciled with the server before any second exposure.
          submitted.add(slot);
          progressText.textContent = 'Đang lưu ảnh ' + (slot + 1) + '/' + batch.count + '…';
          return (await request('capture', { ...values, canvasimg }, true)).file;
        },
        process: async (slot, file) => {
          progressText.textContent = 'Đang xử lý ảnh ' + (slot + 1) + '/' + batch.count + '…';
          if (!prepared) {
            const payload = { id, slot, file };
            if (stage.hasEffects) {
              const response = await fetch(url('raw', { id, slot }), { signal: AbortSignal.timeout(150000) });
              if (!response.ok) throw new Error('Không đọc được ảnh nguồn. Hãy thử lại.');
              const imageUrl = URL.createObjectURL(await response.blob());
              try {
                const effects = await stage.prepareEffects('photo', file, imageUrl);
                if (Object.keys(effects).length) payload.faceEffects = JSON.stringify(effects);
                if (stage.preparedBeauty) payload.beautyEffects = JSON.stringify(stage.preparedBeauty);
                if (stage.preparedBeautyImage) payload.beautyImage = stage.preparedBeautyImage;
              } catch (error) {
                console.warn('Multishot: applying the existing plain-photo fallback', error);
              } finally {
                URL.revokeObjectURL(imageUrl);
              }
            }
            prepared = payload;
          }
          await request('process', prepared, true);
          prepared = undefined;
          submitted.delete(slot);
        },
        progress: (slot) => {
          const img = new Image();
          img.src = url('edited', { id, slot });
          img.alt = '';
          sheet.children[slot].replaceChildren(img);
        },
        complete: async () => {
          progressText.textContent = 'Đang ghép bộ ảnh…';
          return (await request('complete', { id }, true)).file;
        },
      });
      if (!result) {
        for (;;) {
          progressText.textContent = 'Đang hủy bộ ảnh…';
          try {
            result = (await request('cancel', { id }, true)).file || null;
            break;
          } catch (error) {
            if (error.status === 404 || error.status === 410) break;
            await retry(new Error('Chưa xác nhận được thao tác hủy. Hãy thử lại khi có kết nối.'), batch.count, batch.count, false);
          }
        }
      }
    } catch (error) {
      window.alert(error.message);
    } finally {
      progressPanel.hidden = true;
      sheet.replaceChildren();
      document.body.classList.remove('hmu-multishot-mode');
      camera.style.removeProperty('--hmu-multishot-ratio');
      photoboothPreview.stopVideo();
      photoBooth.endMultishot(result);
    }
  }

  window.hmuMultishot = {
    open(frame) {
      fitCamera(frame.slots[0]);
      document.body.classList.add('hmu-multishot-mode');
      stage.selectMultishot(frame);
    },
    begin,
    back() {
      document.body.classList.remove('hmu-multishot-mode');
      camera.style.removeProperty('--hmu-multishot-ratio');
      window.hmuFrameMode.focusSelection();
    },
  };
})();
