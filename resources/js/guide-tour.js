(function () {
  'use strict';
  const start = document.getElementById('hmu-guide-start');
  if (!start) return;
  const frames = document.getElementById('kioskFrameSelector');
  const stage = document.getElementById('hmu-filter-stage');
  const shoot = document.getElementById('hmu-filter-shoot');
  const result = document.getElementById('result');
  const loader = document.getElementById('loader');
  const qr = document.getElementById('qrCode');
  const status = document.getElementById('hmu-guide-status');
  const waiting = document.getElementById('hmu-guide-wait');
  const waitStop = document.getElementById('hmu-guide-stop');
  stage.append(waiting);
  waitStop.disabled = true;
  const focusSelector = 'button:not(:disabled), a[href], select:not(:disabled), input:not(:disabled), summary';
  let running = false, phase = 'idle', spotlight = null, observer = null;
  let internalClose = false, selectionRevision = 0, captureBefore = null, noticeTimer;
  const guideShortcuts = new Set();

  const visible = element => !!element && element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden';
  const controls = element => !element ? [] : [element, ...element.querySelectorAll(focusSelector)]
    .filter(item => item.matches(focusSelector) && visible(item));
  function notice(message) {
    clearTimeout(noticeTimer);
    status.textContent = message;
    if (message) noticeTimer = setTimeout(() => { status.textContent = ''; }, 7000);
  }
  function setPhase(next) {
    phase = next;
    document.body.dataset.hmuGuideStep = next;
    waiting.hidden = next !== 'camera';
    waitStop.disabled = next !== 'camera';
  }
  function release() {
    internalClose = true;
    spotlight?.destroy();
    spotlight = null;
    internalClose = false;
  }
  function stop(message = '') {
    if (!running) return;
    const active = spotlight?.getActiveElement();
    const focus = (qr?.classList.contains('modal--show') ? qr.querySelector('button') : null)
      || controls(active)[0] || (visible(shoot) && !shoot.disabled ? shoot : null)
      || (visible(qr?.querySelector('button')) ? qr.querySelector('button') : null)
      || (visible(result.querySelector('.qrbtn')) ? result.querySelector('.qrbtn') : null)
      || (visible(stage) ? document.getElementById('hmu-filter-back') : start);
    running = false;
    selectionRevision++;
    observer?.disconnect(); observer = null;
    document.removeEventListener('click', onAction);
    document.removeEventListener('keydown', onKey, true);
    release();
    setPhase('idle');
    start.disabled = false;
    notice(message);
    queueMicrotask(() => { if (visible(focus)) focus.focus({ preventScroll: true }); });
  }
  function paint(next, target, title, description) {
    if (!running || !visible(target)) return;
    setPhase(next);
    target.scrollIntoView({block:'start', inline:'nearest', behavior:'instant'});
    if (!spotlight) {
      spotlight = window.driver.js.driver({
        animate: false,
        smoothScroll: false,
        overlayOpacity: .62,
        overlayClickBehavior: 'none',
        allowKeyboardControl: false,
        stagePadding: 8,
        stageRadius: 12,
        popoverClass: 'hmu-guide-popover',
        onPopoverRender(popover) {
          popover.closeButton.setAttribute('aria-label', 'Dừng hướng dẫn');
          if (!stage.hidden) stage.append(popover.wrapper);
        },
        onHighlighted() {
          // Driver creates the overlay on its first animation frame, after the popover.
          const overlay = document.querySelector('.driver-overlay');
          const popover = document.querySelector('.hmu-guide-popover');
          if (!stage.hidden && overlay && popover) { stage.append(overlay, popover); spotlight?.refresh(); }
        },
        onDestroyStarted() { if (!internalClose) stop(); },
        onDestroyed() { if (!internalClose) stop(); }
      });
    }
    spotlight.highlight({element: target, popover: {title, description, side:'bottom', align:'center', showButtons:['close']}});
    controls(target)[0]?.focus({ preventScroll: true });
  }
  function showFrames() {
    paint('frames', frames, '1 · Chọn khung ảnh', 'Chạm vào một khung bạn thích. Bạn có thể cuộn danh sách để xem thêm. Chỉ khi khung được lưu thành công, hướng dẫn mới tiếp tục.');
  }
  function showCameraEntry() {
    paint('open-camera', document.querySelector('.hmu-actions .takePic'), '2 · Mở camera', 'Chạm “Chọn hiệu ứng và chụp”. Nếu trình duyệt hỏi quyền camera, hãy chọn Cho phép / Allow.');
  }
  function showShoot() {
    paint('shoot', shoot, '3 · Tạo dáng và chụp', 'Chạm “Bắt đầu chụp ảnh”, nhìn vào camera và tạo dáng theo đếm ngược. Hướng dẫn sẽ ẩn khi chụp và tiếp tục khi ảnh sẵn sàng.');
  }
  function sync() {
    if (!running) return;
    if (phase === 'open-camera' && !stage.hidden) {
      release(); setPhase('camera');
    }
    if (phase === 'camera') {
      if (stage.hidden) return stop();
      if (!shoot.disabled) showShoot();
    } else if (phase === 'shoot') {
      if (window.photoBooth?.takingPic) {
        captureBefore = result.getAttribute('data-img');
        release(); setPhase('capturing');
      } else if (stage.hidden) stop();
      else if (shoot.disabled) { release(); setPhase('camera'); }
    } else if (phase === 'capturing') {
      if (loader.classList.contains('error')) return stop('Lần chụp chưa thành công. Hãy xử lý thông báo trên màn hình và thử lại.');
      if (visible(result) && result.getAttribute('data-img') && result.getAttribute('data-img') !== captureBefore) {
        const target = result.querySelector('.qrbtn');
        if (!target || !config.qr.enabled || !config.ui.result_buttons) return stop('Ảnh đã sẵn sàng. Hướng dẫn chụp ảnh đã hoàn tất.');
        if (result.querySelector('.resultInner').classList.contains('show')) {
          paint('qr', target, '4 · Nhận ảnh trên điện thoại', 'Chạm nút QR để mở mã nhận ảnh.');
        }
      }
    } else if (phase === 'qr' && qr?.classList.contains('modal--show')) stop();
    else if (phase === 'qr' && (!visible(result) || !stage.hidden)) stop();
  }
  function onAction(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (phase === 'frames' && target.closest('.hmu-frame-card')) {
      const revision = ++selectionRevision;
      window.hmuFrameSelection.ready().then(() => {
        if (running && phase === 'frames' && revision === selectionRevision) showCameraEntry();
      }).catch(() => {
        if (running && phase === 'frames' && revision === selectionRevision) {
          const description = document.querySelector('.hmu-guide-popover .driver-popover-description');
          if (description) description.textContent = 'Chưa lưu được khung. Hãy chạm chọn lại khung hoặc dùng nút × để dừng hướng dẫn.';
        }
      });
    }
    sync();
  }
  function rememberShortcut(event) {
    if ((config.picture.key && Number(config.picture.key) === event.keyCode)
      || (config.print.key && Number(config.print.key) === event.keyCode)) guideShortcuts.add(event.code || event.key);
  }
  function onKey(event) {
    if (!running) return;
    // Keep Enter/Space on real controls from also invoking booth-wide shutter/print shortcuts.
    rememberShortcut(event);
    if (event.key === 'Escape') {
      event.preventDefault(); event.stopImmediatePropagation(); stop();
    } else if (event.key === 'Tab' && spotlight) {
      const items = [...controls(spotlight.getActiveElement()), ...controls(document.querySelector('.hmu-guide-popover'))];
      if (!items.length) return;
      const index = items.indexOf(document.activeElement);
      const next = (index + (event.shiftKey ? -1 : 1) + items.length) % items.length;
      event.preventDefault(); event.stopImmediatePropagation(); items[next].focus();
    }
  }
  start.addEventListener('keydown', rememberShortcut);
  start.addEventListener('click', () => {
    if (running) return;
    if (!window.driver?.js?.driver) return notice('Chưa tải được hướng dẫn. Hãy tải lại trang và thử lại.');
    if (!frames.querySelector('.hmu-frame-card')) return notice('Chưa tải được danh sách khung ảnh. Hãy tải lại trang và thử lại.');
    running = true;
    start.disabled = true;
    notice('');
    document.addEventListener('click', onAction);
    document.addEventListener('keydown', onKey, true);
    observer = new MutationObserver(sync);
    for (const node of [stage, result, result.querySelector('.resultInner'), loader, shoot, qr]) {
      if (node) observer.observe(node, {attributes:true, attributeFilter:['hidden','style','class','disabled','data-img','open']});
    }
    showFrames();
  });
  waitStop.addEventListener('click', () => stop());
  document.addEventListener('keyup', event => {
    if (guideShortcuts.delete(event.code || event.key)) event.stopImmediatePropagation();
  }, true);
  window.addEventListener('pagehide', () => { stop(); guideShortcuts.clear(); clearTimeout(noticeTimer); });
})();
