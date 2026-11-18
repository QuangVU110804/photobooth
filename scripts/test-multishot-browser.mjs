import path from 'node:path';
import { createHash } from 'node:crypto';

export async function checkMultishot({ cdp, check, waitFor, logs, fixture, fs, base }) {
  const evalPage = expression => cdp.evaluate(expression);
  const click = selector => evalPage('document.querySelector(' + JSON.stringify(selector) + ').click(); true');
  const choose = async (layout) => {
    await click('[data-capture-mode="multi"]');
    await waitFor(() => evalPage("document.querySelectorAll('#hmuMultiFrameSelector .hmu-frame-card').length > 0"), 'embedded multishot frames');
    check(await evalPage("!document.getElementById('hmuMultiFramePicker').hidden && document.getElementById('hmuFramePicker').hidden && !photoboothPreview.stream"), 'Mode switch opens the inline picker without opening camera');
    await evalPage("(() => { const selector=document.getElementById('hmu-multishot-layout'); selector.value=" + JSON.stringify(layout) + "; selector.dispatchEvent(new Event('change')); return true; })()");
    await click('#hmuMultiFrameSelector [data-frame="hmu-' + layout + '"]');
    check(await evalPage("document.querySelectorAll('#hmuMultiFrameSelector .hmu-frame-card').length === 1 && !hmuFilterStage.active && !photoboothPreview.stream"), 'Choosing an inline frame updates selection and leaves the camera off');
    const hero = await waitFor(() => evalPage("document.getElementById('hmuHeroFrame').naturalWidth && document.getElementById('hmuHeroFrame').src"), 'shared hero frame');
    check(hero.includes('mode=multi') && decodeURIComponent(hero).includes('hmu-' + layout + '.png'), 'Shared hero shows the selected multi-photo frame');
    const preview = await fetch(hero);
    check(preview.ok && preview.headers.get('content-type') === 'image/png' && preview.headers.has('etag'), 'Multi-photo preview uses the existing PNG cache');
  };
  const openSelected = async () => {
    await click('.hmu-actions .takePic');
    await waitFor(() => evalPage("hmuFilterStage.active && !document.getElementById('hmu-filter-shoot').disabled"), 'multishot camera');
    check(await evalPage("document.body.classList.contains('hmu-multishot-mode') && hmuFilterStage.frame === ''"), 'The same capture button opens effects for the selected set');
    await evalPage("document.getElementById('hmu-timer-seconds').value=1; true");
  };
  await cdp.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  const singleFrame = await evalPage('hmuFrameSelection.ready().then(()=>hmuFrameSelection.filename)');
  const singleHero = await evalPage("document.getElementById('hmuHeroFrame').src");
  check(singleFrame && await evalPage("document.querySelector('[data-capture-mode=single]')?.getAttribute('aria-pressed') === 'true'"), 'The page starts in single-photo mode with the segment visible');
  await evalPage(`(() => {
    const originalFetch = window.fetch;
    window.fetch = (...args) => String(args[0]).includes('action=frames')
      ? new Promise(resolve => { window.releaseModeCatalog = () => { window.fetch = originalFetch; resolve(originalFetch(...args)); }; })
      : originalFetch(...args);
    return true;
  })()`);
  await click('[data-capture-mode="multi"]');
  await click('.hmu-actions .takePic');
  await click('[data-capture-mode="single"]');
  await click('[data-capture-mode="multi"]');
  await evalPage("window.releaseModeCatalog(); true");
  await waitFor(() => evalPage("document.querySelector('#hmuMultiFrameSelector .hmu-frame-card') && document.getElementById('hmuMultiFrameSelector').getAttribute('aria-busy') === 'false'"), 'delayed frame catalog');
  check(await evalPage("!hmuFilterStage.active && !photoboothPreview.stream"), 'Switching away cancels earlier capture intent even after returning to the mode');
  await choose('1x4');
  await evalPage("window.previousCaptureKey = config.picture.key; config.picture.key = '13'; document.querySelector('#hmuMultiFrameSelector .hmu-frame-card').focus(); true");
  await cdp.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
  await cdp.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
  check(await evalPage("!hmuFilterStage.active && !photoBooth.takingPic"), 'Enter on a frame card chooses the frame without firing the shutter shortcut');
  await evalPage("config.picture.key = window.previousCaptureKey; true");
  const rotary = await evalPage(`(() => {
    config.remotebuzzer.userotary = true;
    rotaryController.focusSet('#start');
    const reached = new Set();
    for (let step = 0; step < 30; step++) {
      const active = document.querySelector('.focused');
      if (active?.matches('[data-capture-mode=multi]')) reached.add('mode');
      if (active?.matches('.hmu-actions .takePic')) reached.add('capture');
      if (active?.matches('#hmuMultiFrameSelector .hmu-frame-card')) reached.add('frame');
      rotaryController.focusNext();
    }
    rotaryController.focusRemove();
    config.remotebuzzer.userotary = false;
    return [...reached];
  })()`);
  check(rotary.includes('mode') && rotary.includes('capture') && rotary.includes('frame'), 'Rotary navigation can reach the mode, frame cards and shared capture button');
  check(await evalPage("document.documentElement.scrollWidth <= innerWidth && document.querySelector('[data-capture-mode=multi]').getBoundingClientRect().height >= 44"), 'Portrait segmentation and inline picker fit and support touch');
  await openSelected();
  await click('#hmu-filter-back');
  check(await evalPage("!hmuFilterStage.active && document.activeElement?.dataset.frame === 'hmu-1x4'"), 'Back restores the selected card in the in-page frame picker');
  await evalPage("document.querySelector('[data-capture-mode=multi]').focus(); true");
  await cdp.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'ArrowLeft', code: 'ArrowLeft' });
  await cdp.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'ArrowLeft', code: 'ArrowLeft' });
  check(await evalPage("document.activeElement?.dataset.captureMode === 'single' && document.getElementById('hmuHeroFrame').src === " + JSON.stringify(singleHero)), 'Arrow navigation restores the original single frame');
  await choose('1x4');
  await openSelected();
  await click('[data-beauty-preset="natural"]');
  await waitFor(() => evalPage("!document.getElementById('hmu-filter-shoot').disabled"), 'beauty preset ready');
  await evalPage("window.originalMultishotFetch = window.fetch; window.lostShot = false; window.lostRaw = false; window.fetch = async (url, options) => { if (!lostRaw && String(url).includes('action=raw') && String(url).includes('slot=1')) { lostRaw=true; throw new Error('Không đọc được ảnh thử nghiệm'); } const response = await originalMultishotFetch(url, options); if (!lostShot && String(url).includes('action=capture') && options?.body?.get('slot') === '2' && response.ok) { lostShot=true; throw new Error('Mất phản hồi sau khi chụp'); } return response; }; true");
  await click('#hmu-filter-shoot');
  // Repeated shutter input during collection must not open a second capture.
  await evalPage("photoBooth.thrill('photo'); true");
  await waitFor(() => evalPage("window.lostRaw && !document.querySelector('[data-multishot-retry]').hidden"), 'retry after failing to read slot two');
  check(await evalPage("document.querySelectorAll('.hmu-multishot-sheet span img').length === 1"), 'A delayed raw download does not commit an unedited slot');
  await click('[data-multishot-retry]');
  await waitFor(() => evalPage("window.lostShot && !document.querySelector('[data-multishot-retry]').hidden"), 'retry after losing capture response');
  check(await evalPage("document.querySelectorAll('.hmu-multishot-sheet span img').length === 2"), 'Losing shot three keeps the first two slots');
  await click('[data-multishot-retry]');
  const result = async () => await waitFor(() => evalPage("!photoBooth.takingPic && document.querySelector('#result').getClientRects().length && document.querySelector('#result').dataset.img"), 'multishot result');
  const first = await result();
  const requests = (await logs()).filter(row => row.route === 'api/multishot.php');
  const captures = requests.filter(row => row.action === 'capture');
  check(captures.map(row => row.slot).join(',') === '0,1,2,2,3' && captures[2].request === captures[3].request && !captures[3].hasCanvas, 'Reconciliation reuses the same request without capturing another frame');
  const processed = requests.filter(row => row.action === 'process');
  check(processed.length === 4 && processed.every(row => row.beautyImageBytes > 1000) && new Set(processed.map(row => row.file)).size === 4, 'Beauty settings apply to the four independent shots');
  check((await fs.readdir(path.join(fixture, 'images'))).length === 1, 'Only the finished sheet appears in the gallery directory');
  check(JSON.parse(await fs.readFile(path.join(fixture, 'db.txt'), 'utf8')).join(',') === first, 'Gallery database contains one entry for the entire session');
  const qr = await fetch(base + '/api/qrcode.php?filename=' + encodeURIComponent(first));
  const expected = base + '/photo.php?image=' + encodeURIComponent(first);
  const cache = createHash('md5').update(expected).digest('hex') + '.png';
  check(qr.ok && qr.headers.get('content-type') === 'image/png' && (await fs.stat(path.join(fixture, 'qrcodes', cache))).size > 0, 'Result QR uses the standard receipt URL for the saved sheet');
  const receipt = await fetch(expected);
  const downloaded = await fetch(expected + '&download=1');
  check(receipt.ok && (await receipt.text()).includes('width="840" height="2264"'), 'Phone receipt shows the composite dimensions');
  check(downloaded.ok && Buffer.from(await downloaded.arrayBuffer()).equals(await fs.readFile(path.join(fixture, 'images', first))), 'Receipt download returns the exact saved sheet');
  const foreign = await fetch(base + '/api/multishot.php?action=status&id=' + encodeURIComponent(first));
  check(foreign.status === 404, 'The public receipt link does not grant access to private sessions');
  check(await evalPage("!document.querySelector('.hmu-multishot-repeat').hidden && hmuFrameSelection.filename === " + JSON.stringify(singleFrame)), 'New set action and prior single frame are both available');

  await click('#result .newpic');
  await waitFor(() => evalPage("hmuFilterStage.active && !document.getElementById('hmu-filter-shoot').disabled"), 'return to single photo');
  check(await evalPage("!document.body.classList.contains('hmu-multishot-mode') && hmuFilterStage.frame === " + JSON.stringify(singleFrame)), 'Returning to single photo restores its frame');
  await click('#hmu-filter-back');
  await choose('2x3');
  await openSelected();
  await click('#hmu-filter-shoot');
  const second = await result();
  check(second !== first && (await fs.readdir(path.join(fixture, 'images'))).length === 2, 'Six-shot layout saves one more sheet');
  await click('#result .hmu-multishot-repeat');
  check(await evalPage("!hmuFilterStage.active && document.activeElement?.dataset.frame === 'hmu-2x3' && !document.getElementById('hmuMultiFramePicker').hidden"), 'New set returns to the inline picker with the selected frame');
  const secondReceipt = await fetch(base + '/photo.php?image=' + encodeURIComponent(second));
  check((await secondReceipt.text()).includes('width="1600" height="1744"'), '2×3 receipt has its own sheet dimensions');

  const deleted = await evalPage("fetch('api/deletePhoto.php', {method:'POST', body:new URLSearchParams({file:" + JSON.stringify(second) + "})}).then(r=>r.json())");
  check(deleted.success, 'The existing deletion endpoint removes the composite');
  const replay = await evalPage("fetch('api/multishot.php?action=complete', {method:'POST', body:new URLSearchParams({id:" + JSON.stringify(second) + "})}).then(r=>r.status)");
  check(replay === 410, 'A deleted composite cannot be recreated by a replay');
}

export async function checkSingleExternalStop({ cdp, check, waitFor, openStage, load, fixture, fs, base }) {
  await cdp.evaluate("sessionStorage.setItem('hmu-camera-source','external'); true");
  await cdp.send('Network.setCookie', { name: 'filterTestMode', value: 'external-retry', url: base + '/' });
  await fs.writeFile(path.join(fixture, 'attempted'), '1');
  await load();
  await openStage();
  await cdp.evaluate(`(() => {
    const stop = photoboothPreview.stopPreview;
    window.previewStopped = false;
    window.shutterWaitedForStop = false;
    window.externalCanvasEncoded = false;
    const encode = HTMLCanvasElement.prototype.toDataURL;
    HTMLCanvasElement.prototype.toDataURL = function (...args) {
      if (this.id === 'video--sensor') window.externalCanvasEncoded = true;
      return encode.apply(this, args);
    };
    photoboothPreview.stopPreview = () => Promise.resolve(stop()).then(() => new Promise(resolve => {
      setTimeout(() => { window.previewStopped = true; resolve(); }, 700);
    }));
    $.ajaxPrefilter(options => {
      if (options.url === 'api/takePic.php') {
        window.shutterWaitedForStop = window.previewStopped;
        window.externalCanvasPosted = new URLSearchParams(options.data).has('canvasimg');
      }
    });
    document.getElementById('hmu-filter-shoot').click();
    return true;
  })()`);
  const file = await waitFor(() => cdp.evaluate("!photoBooth.takingPic && document.getElementById('result').dataset.img"), 'single external photo');
  check(!file.startsWith('multishot-') && await cdp.evaluate('window.shutterWaitedForStop'), 'Single external shot waits for its own preview stop before posting');
  check(await cdp.evaluate('window.externalCanvasPosted === false && !window.externalCanvasEncoded'), 'External single shot avoids encoding and uploading an unused browser canvas');
}
