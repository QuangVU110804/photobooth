/** Node 22+ and installed Chrome/Edge; no npm dependencies. */
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:net';
import { promises as fs } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const php = process.env.PHOTOBOOTH_TEST_PHP || 'C:\\xampp\\php\\php.exe';
const chrome = process.env.PHOTOBOOTH_TEST_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const profile = await fs.mkdtemp(path.join(tmpdir(), 'photobooth-browser-'));
const fixtureName = `receipt-browser-${process.pid}.jpg`;
const fixturePath = path.join(root, 'data', 'images', fixtureName);
const unicodeName = `Ảnh #${process.pid} + bạn.jpg`;
const unicodePath = path.join(root, 'data', 'images', unicodeName);
const createdQrFiles = [];
let phpServer, browser, cdp;
let checks = 0;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const phpQuote = (value) => "'" + value.replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'";
const waitFor = async (predicate, label, timeout = 15000) => {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    try { const value = await predicate(); if (value) return value; } catch { /* Wait for the owned process to become ready. */ }
    await sleep(100);
  }
  throw new Error(`Timed out: ${label}`);
};
const check = (condition, message) => { assert.ok(condition, message); checks++; };

class CDP {
  constructor(socket) {
    this.socket = socket;
    this.nextId = 1;
    this.pending = new Map();
    this.errors = [];
    socket.addEventListener('message', ({ data }) => {
      const message = JSON.parse(data);
      if (message.method === 'Runtime.exceptionThrown') this.errors.push(message.params.exceptionDetails.text);
      if (message.id && this.pending.has(message.id)) {
        const { resolve, reject, timer } = this.pending.get(message.id);
        clearTimeout(timer);
        this.pending.delete(message.id);
        message.error ? reject(new Error(message.error.message)) : resolve(message.result);
      }
    });
  }
  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = this.nextId++;
      const timer = setTimeout(() => { this.pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 15000);
      this.pending.set(id, { resolve, reject, timer });
      this.socket.send(JSON.stringify({ id, method, params }));
    });
  }
  async evaluate(expression) {
    const result = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  }
}

try {
  // Build a tall real-image fixture; production photos are never overwritten.
  const demo = path.join(root, 'scripts', 'fixtures', 'filter-face.jpg');
  const fixtureCode = `$src=imagecreatefromjpeg(${phpQuote(demo)}); $out=imagecreatetruecolor(600,1800); for($i=0;$i<3;$i++) { imagecopyresampled($out,$src,0,$i*600,0,0,600,600,imagesx($src),imagesy($src)); } imagejpeg($out,${phpQuote(fixturePath)},90);`;
  execFileSync(php, ['-r', fixtureCode], { cwd: root });
  await fs.copyFile(fixturePath, unicodePath);

  const portServer = createServer();
  await new Promise((resolve) => portServer.listen(0, '127.0.0.1', resolve));
  const port = portServer.address().port;
  await new Promise((resolve) => portServer.close(resolve));
  const origin = `http://127.0.0.1:${port}`;
  const base = `${origin}/photobooth`;
  let serverLog = '';
  phpServer = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', path.dirname(root)], { cwd: root, env: { ...process.env, PHOTOBOOTH_PUBLIC_URL: base }, stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true });
  phpServer.stderr.on('data', (data) => { serverLog += data; });
  await waitFor(async () => {
    const response = await fetch(`${base}/resources/css/photo-receipt.css`);
    await response.arrayBuffer();
    return response.ok;
  }, 'PHP test server');

  const receiptUrl = `${base}/photo.php?image=${encodeURIComponent(fixtureName)}`;
  const page = await fetch(receiptUrl);
  await page.arrayBuffer();
  check(page.status === 200 && page.headers.get('content-type').includes('text/html'), 'Receipt returns HTML');
  check(page.headers.get('cache-control').includes('no-store'), 'Photo receipt does not cache private HTML');
  const download = await fetch(`${receiptUrl}&download=1`);
  check(download.headers.get('content-disposition').startsWith('attachment;'), 'Download has attachment headers');
  check(Buffer.from(await download.arrayBuffer()).equals(await fs.readFile(fixturePath)), 'HTTP download preserves original bytes');
  const legacy = await fetch(`${base}/api/download.php?image=${encodeURIComponent(fixtureName)}`);
  check(Buffer.from(await legacy.arrayBuffer()).equals(await fs.readFile(fixturePath)), 'Existing gallery download endpoint still returns the same bytes');
  const unicodeDownload = await fetch(`${base}/photo.php?image=${encodeURIComponent(unicodeName)}&download=1`);
  check(unicodeDownload.status === 200 && unicodeDownload.headers.get('content-disposition').includes("filename*=UTF-8''"), 'Unicode filename download is supported');
  check(Buffer.from(await unicodeDownload.arrayBuffer()).equals(await fs.readFile(fixturePath)), 'Unicode filename returns the right photo');
  for (const [query, status] of [['', 400], ['?image=missing.jpg', 404], ['?image=..%2Foutside.jpg', 400], ['?image[]=x.jpg', 400], ['?image=%3Cscript%3E.jpg', 400]]) {
    const response = await fetch(`${base}/photo.php${query}`);
    await response.arrayBuffer();
    check(response.status === status, `Invalid/missing photo responds ${status}`);
  }
  const publicQrBase = base;
  const ownedQrName = createHash('md5').update(`${publicQrBase}/photo.php?image=${encodeURIComponent(fixtureName)}`).digest('hex') + '.png';
  const ownedQrPath = path.join(root, 'data', 'qrcodes', ownedQrName);
  const qrExisted = await fs.access(ownedQrPath).then(() => true, () => false);
  if (!qrExisted) createdQrFiles.push(ownedQrPath);
  const qr = await fetch(`${base}/api/qrcode.php?filename=${encodeURIComponent(fixtureName)}`);
  const qrBytes = Buffer.from(await qr.arrayBuffer());
  check(qr.ok && qr.headers.get('content-type').includes('image/png'), 'QR endpoint serves PNG');
  check(qrBytes.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])), 'QR bytes contain no BOM or HTML');

  browser = spawn(chrome, ['--headless=new', '--no-sandbox', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { stdio: ['ignore', 'ignore', 'ignore'], windowsHide: true });
  const activePort = await waitFor(async () => (await fs.readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0], 'Chrome debugging port');
  const targets = await (await fetch(`http://127.0.0.1:${activePort}/json/list`)).json();
  const socket = new WebSocket(targets.find((target) => target.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  cdp = new CDP(socket);
  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: true });

  const loadPage = async (url) => {
    await cdp.send('Page.navigate', { url });
    await waitFor(async () => await cdp.evaluate(`document.readyState === 'complete' && location.href === ${JSON.stringify(url)}`), 'receipt navigation');
  };
  const reports = path.join(root, 'reports');
  await fs.mkdir(reports, { recursive: true });
  for (const [width, height] of [[320, 740], [375, 812], [390, 844], [768, 1024], [844, 390], [1024, 768], [1440, 1000]]) {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 900 });
    await loadPage(receiptUrl);
    await waitFor(() => cdp.evaluate("document.getElementById('receipt-image').complete && document.getElementById('receipt-image').naturalWidth > 0"), 'photo loads');
    const layout = await cdp.evaluate(`(() => {
      const image = document.getElementById('receipt-image');
      const button = document.getElementById('download-photo');
      const photo = document.querySelector('.receipt-preview').getBoundingClientRect();
      const panels = document.querySelector('.receipt-panels').getBoundingClientRect();
      return { overflow: document.documentElement.scrollWidth > innerWidth, imageWidth: image.getBoundingClientRect().width, ratio: image.getBoundingClientRect().height / image.getBoundingClientRect().width, buttonHeight: button.getBoundingClientRect().height, photoX: photo.x, panelsX: panels.x, columns: getComputedStyle(document.querySelector('.receipt-layout')).gridTemplateColumns, name: document.querySelector('.receipt-filename').textContent };
    })()`);
    check(!layout.overflow, `No horizontal scroll at ${width}×${height}`);
    check(Math.abs(layout.ratio - 3) < .03, `Photo is uncropped at ${width}×${height}`);
    check(layout.buttonHeight >= 48, `Download touch target at ${width}×${height}`);
    check(layout.name === fixtureName, `Correct image identity at ${width}×${height}`);
    check(width >= 900 ? layout.panelsX > layout.photoX + 100 : Math.abs(layout.panelsX - layout.photoX) < 2, `Responsive column layout at ${width}×${height}`);
    if (width === 375 || width === 1440) {
      const metrics = await cdp.send('Page.getLayoutMetrics');
      const screenshot = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width, height: metrics.cssContentSize.height, scale: 1 } });
      await fs.writeFile(path.join(reports, width === 375 ? 'photo-receipt-mobile.png' : 'photo-receipt-desktop.png'), Buffer.from(screenshot.data, 'base64'));
    }
  }

  const downloadDir = path.join(profile, 'downloads');
  await fs.mkdir(downloadDir);
  await cdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: downloadDir });
  await cdp.evaluate("document.getElementById('download-photo').click()");
  const browserDownload = await waitFor(async () => await fs.readFile(path.join(downloadDir, `photobooth-${fixtureName}`)), 'native browser download');
  check(browserDownload.equals(await fs.readFile(fixturePath)), 'Clicking download in Chrome saves the exact original file');

  await cdp.evaluate("document.getElementById('view-photo').click()");
  check(await cdp.evaluate("document.getElementById('photo-dialog').open"), 'Enlarge image opens a native dialog');
  check(await cdp.evaluate("document.activeElement.getAttribute('aria-label') === 'Đóng ảnh phóng to'"), 'Dialog focuses the close button');
  await cdp.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await cdp.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  check(await cdp.evaluate("!document.getElementById('photo-dialog').open"), 'Escape closes enlarged photo');

  await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  check(await cdp.evaluate("getComputedStyle(document.getElementById('download-photo')).transitionDuration === '0s'"), 'Reduced-motion preference is respected');
  await cdp.evaluate("document.documentElement.style.fontSize = '200%'");
  check(await cdp.evaluate('document.documentElement.scrollWidth <= innerWidth'), '200% text size does not create horizontal overflow');
  await loadPage(`${base}/photo.php?image=${encodeURIComponent(unicodeName)}`);
  check(await cdp.evaluate(`document.querySelector('.receipt-filename').textContent === ${JSON.stringify(unicodeName)}`), 'Unicode photo name renders safely');

  // Share UI exercises the real handler with the platform clipboard API at its boundary.
  await cdp.send('Browser.grantPermissions', { origin, permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite'] });
  await cdp.evaluate("Object.defineProperty(navigator, 'share', { value: undefined, configurable: true }); document.getElementById('share-photo').click()");
  await waitFor(() => cdp.evaluate("document.getElementById('receipt-status').textContent.length > 0"), 'share fallback response');
  const shareState = await cdp.evaluate("({focused: document.hasFocus(), status: document.getElementById('receipt-status').textContent})");
  check(shareState.status.includes('Đã sao chép'), `Share fallback succeeds: ${JSON.stringify(shareState)}`);
  const sharedUrl = new URL(await cdp.evaluate("navigator.clipboard.readText()"));
  check(sharedUrl.origin === origin && sharedUrl.pathname === '/photobooth/photo.php' && sharedUrl.searchParams.get('image') === unicodeName && !sharedUrl.searchParams.has('download') && sharedUrl.hash === '', 'Share copies the receipt URL for this exact photo');

  await cdp.send('Emulation.setScriptExecutionDisabled', { value: true });
  await loadPage(receiptUrl);
  const noJs = await cdp.send('DOM.getDocument');
  const downloadNode = await cdp.send('DOM.querySelector', { nodeId: noJs.root.nodeId, selector: '#download-photo' });
  const nativeAttributes = await cdp.send('DOM.getAttributes', { nodeId: downloadNode.nodeId });
  check(nativeAttributes.attributes.includes('download') && nativeAttributes.attributes.some((value) => value.includes('download=1')), 'Native download remains available without JavaScript');
  await cdp.send('Emulation.setScriptExecutionDisabled', { value: false });
  await loadPage(`${base}/photo.php?image=missing.jpg`);
  check(await cdp.evaluate("document.querySelector('h1').textContent === 'Chưa nhận được ảnh' && !document.getElementById('download-photo')"), 'Missing-photo screen has no misleading download');
  check(cdp.errors.length === 0, 'No browser JavaScript errors');
  check(!/Warning|Fatal error|Notice|Deprecated/.test(serverLog), 'No PHP diagnostics during HTTP/browser tests');
  console.log(`${checks} browser/HTTP checks passed. Screenshots in reports/photo-receipt-{mobile,desktop}.png`);
} finally {
  if (cdp) cdp.socket.close();
  if (browser && browser.exitCode === null) {
    browser.kill();
    await Promise.race([new Promise((resolve) => browser.once('exit', resolve)), sleep(3000)]);
  }
  if (phpServer && phpServer.exitCode === null) {
    phpServer.kill();
    await Promise.race([new Promise((resolve) => phpServer.once('exit', resolve)), sleep(3000)]);
  }
  for (const file of [fixturePath, unicodePath, ...createdQrFiles]) await fs.unlink(file).catch((error) => { if (error.code !== 'ENOENT') throw error; });
  // Only the exact temporary profile created by this runner is removed.
  if (path.dirname(profile) === tmpdir() && path.basename(profile).startsWith('photobooth-browser-')) {
    await fs.rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
  }
}
