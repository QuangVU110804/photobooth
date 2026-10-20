/** Guide integration checks run against the real UI, Driver.js and PHP capture pipeline. */
export async function checkGuideTour({ cdp, check, waitFor, load, logs, base, screenshot }) {
  const evaluate = expression => cdp.evaluate(expression);
  const phase = () => evaluate('document.body.dataset.hmuGuideStep || "idle"');
  const at = name => waitFor(async () => await phase() === name, `guide step ${name}`);
  const click = async (selector, touch = false) => {
    const point = await waitFor(() => evaluate(`(() => {
      const element = document.querySelector(${JSON.stringify(selector)});
      if (!element) throw new Error('Missing click target: ' + ${JSON.stringify(selector)});
      const box = element.getBoundingClientRect();
      if(box.bottom<0||box.top>innerHeight) { element.scrollIntoView({block:'center',inline:'nearest'}); return false; }
      const x=box.x+box.width/2,y=box.y+box.height/2;
      const hit=document.elementFromPoint(x,y);
      return hit && (element===hit || element.contains(hit)) ? {x,y} : false;
    })()`), `clickable ${selector}`);
    if (touch) {
      await cdp.send('Input.dispatchTouchEvent', { type:'touchStart', touchPoints:[point] });
      await cdp.send('Input.dispatchTouchEvent', { type:'touchEnd', touchPoints:[] });
    } else {
      await cdp.send('Input.dispatchMouseEvent', { type:'mousePressed', button:'left', clickCount:1, ...point });
      await cdp.send('Input.dispatchMouseEvent', { type:'mouseReleased', button:'left', clickCount:1, ...point });
    }
  };
  const escape = async () => {
    await cdp.send('Input.dispatchKeyEvent', {type:'keyDown', key:'Escape', code:'Escape'});
    await cdp.send('Input.dispatchKeyEvent', {type:'keyUp', key:'Escape', code:'Escape'});
  };
  const mode = async value => {
    await evaluate("sessionStorage.removeItem('hmu-camera-source');sessionStorage.removeItem('hmu-camera-device')");
    await evaluate(`document.cookie='filterTestMode=${value};path=/'`);
    if (value === 'classic') {
      await cdp.send('Page.navigate',{url:`${base}/index.php`});
      await waitFor(() => evaluate("document.readyState==='complete'&&typeof photoBooth!=='undefined'"), 'classic page');
    } else await load();
  };
  const start = async () => { await click('#hmu-guide-start'); await at('frames'); };
  const selectFrame = async () => { await click('.hmu-frame-card'); await at('open-camera'); };
  const openCamera = async () => {
    await click('.hmu-actions .takePic');
    await waitFor(() => evaluate("hmuFilterStage.active && !document.getElementById('hmu-filter-shoot').disabled"), 'camera is ready');
    await at('shoot');
    check(await evaluate("document.getElementById('hmu-filter-stage').contains(document.querySelector('.driver-popover'))"), 'Camera spotlight instructions belong to the active accessible dialog');
  };
  const verifyShootStep = async () => {
    await at('shoot');
    check(await evaluate("!document.getElementById('hmu-color-filters')&&!document.querySelector('[data-color]')"), 'Guide proceeds to capture without retired color controls');
    check(await evaluate("document.getElementById('hmu-filter-stage').contains(document.querySelector('.driver-popover'))"), 'Capture instructions and close control remain inside the camera dialog');
    check(await evaluate("(()=>{const p=document.querySelector('.driver-popover');return p.offsetHeight-p.clientHeight<=2;})()"), 'Capture instructions have no horizontal scrollbar');
  };

  await mode('guide');
  check(await evaluate("!!document.getElementById('hmu-guide-start')"), 'Modern screen has a manual Hướng dẫn button');
  check(await phase() === 'idle' && !await evaluate("!!document.querySelector('.driver-overlay')"), 'Tour never starts automatically');
  const storageBefore = await evaluate('JSON.stringify([Object.entries(localStorage),Object.entries(sessionStorage)])');
  await start();
  await screenshot('guide-frames');
  check(await evaluate("(()=>{const p=document.querySelector('.driver-popover').getBoundingClientRect(),g=document.getElementById('kioskFrameSelector').getBoundingClientRect();return p.bottom<=g.top||p.top>=g.bottom||p.right<=g.left||p.left>=g.right;})()"), 'Frame instructions do not cover the selectable frames');
  check(await evaluate("[...document.querySelectorAll('.driver-popover-next-btn')].every(button=>!button.getClientRects().length)"), 'Guide offers no Next button to bypass real actions');
  await cdp.send('Input.dispatchKeyEvent', {type:'keyDown',key:'ArrowRight',code:'ArrowRight'});
  check(await phase() === 'frames', 'Arrow navigation cannot bypass frame selection');
  await click('.hmu-frame-card', true); await at('open-camera');
  check(await evaluate("document.querySelector('.hmu-frame-card').getAttribute('aria-pressed')==='true' && !hmuFilterStage.active"), 'A real touch selects a frame without starting capture');
  await openCamera();
  await escape(); await at('idle');
  check(await evaluate("hmuFilterStage.active && !document.querySelector('.driver-overlay')"), 'Escape closes only the guide and leaves camera usable');
  await evaluate("document.getElementById('hmu-filter-back').click()");
  await start(); await selectFrame(); await openCamera(); await verifyShootStep();
  await click('.driver-popover-close-btn'); await at('idle');
  check(await evaluate("document.activeElement===document.getElementById('hmu-filter-shoot') && !document.getElementById('hmu-filter-shoot').disabled"), 'Closing restores focus to a usable capture button');
  await evaluate("document.getElementById('hmu-filter-back').click()");

  await start(); await selectFrame(); await openCamera();
  check(await phase() === 'shoot', 'Camera readiness advances directly to the real capture button');
  await verifyShootStep();
  await screenshot('guide-camera');
  const requestsBefore = (await logs()).filter(row => row.route === 'api/takePic.php').length;
  await click('#hmu-filter-shoot'); await at('capturing');
  check(!await evaluate("!!document.querySelector('.driver-overlay,.driver-popover')"), 'Countdown and capture have no guide overlay');
  await at('qr');
  check((await logs()).filter(row => row.route === 'api/takePic.php').length === requestsBefore + 1, 'A guided click produces exactly one real capture');
  await click('#result .qrbtn'); await at('idle');
  await screenshot('guide-qr');
  check(await evaluate("document.getElementById('qrCode').classList.contains('modal--show')"), 'Real QR action opens the QR modal');
  check(!await evaluate("!!document.querySelector('.driver-overlay,.driver-popover')"), 'Completing the guide leaves the entire QR code unobstructed');
  check(await evaluate("document.activeElement===document.querySelector('#qrCode .modal__body button')"), 'Completing the guide focuses the visible QR close control');
  await click('#qrCode .modal__body button');
  check(await evaluate('JSON.stringify([Object.entries(localStorage),Object.entries(sessionStorage)])') === storageBefore, 'Guide never records progress or completion in browser storage');

  await load();
  check(await phase() === 'idle', 'Reload never restarts a completed tour');
  await start(); await escape(); await at('idle');
  await start(); await escape(); await at('idle');
  check(!await evaluate("!!document.querySelector('.driver-overlay')"), 'Guide can be restarted and dismissed repeatedly');

  await start();
  cdp.events.length=0;
  await cdp.send('Fetch.enable',{patterns:[{urlPattern:'*api/setFrame.php',requestStage:'Request'}]});
  await click('.hmu-frame-card');
  const held=await waitFor(()=>cdp.events.find(event=>event.request.url.includes('api/setFrame.php')), 'held frame request');
  check(await phase()==='frames', 'Guide waits while frame synchronization is still in flight');
  await cdp.send('Fetch.continueRequest',{requestId:held.requestId});
  await at('open-camera'); await cdp.send('Fetch.disable');
  await escape(); await at('idle');

  await mode('frame-fail'); await start(); await click('.hmu-frame-card');
  await waitFor(() => evaluate("!document.getElementById('hmuFrameError').hidden"), 'frame sync error');
  check(await phase() === 'frames' && !await evaluate('hmuFilterStage.active'), 'Frame sync failure cannot advance the guide or open the camera');
  await escape(); await at('idle');

  const denial = await cdp.send('Page.addScriptToEvaluateOnNewDocument', {source:`navigator.mediaDevices.getUserMedia=()=>Promise.reject(new DOMException('Denied','NotAllowedError'));`});
  await mode('guide'); await start(); await selectFrame(); await click('.hmu-actions .takePic');
  await waitFor(() => evaluate("document.getElementById('hmu-filter-status').textContent.includes('Không mở được camera')"), 'permission denial');
  check(await phase() === 'camera' && await evaluate("document.getElementById('hmu-filter-shoot').disabled"), 'Permission denial waits without guiding the user to a disabled capture');
  check(await evaluate("document.getElementById('hmu-filter-stage').contains(document.getElementById('hmu-guide-wait'))&&!document.getElementById('hmu-guide-stop').disabled"), 'Camera wait instructions and stop control belong to the active accessible dialog');
  await evaluate("document.getElementById('hmu-camera-source').value='external';document.getElementById('hmu-camera-source').dispatchEvent(new Event('change'))");
  await at('shoot');
  check(await evaluate("!document.getElementById('hmu-filter-shoot').disabled"), 'External capture remains available after device-camera permission denial');
  await escape(); await at('idle');
  check(await evaluate('hmuFilterStage.active'), 'Permission error can be escaped without trapping the application');
  await cdp.send('Page.removeScriptToEvaluateOnNewDocument', {identifier:denial.identifier});

  await mode('guide-no-qr'); await start(); await selectFrame();
  await click('.hmu-actions .takePic'); await at('shoot');
  await click('#hmu-filter-shoot'); await at('capturing'); await at('idle');
  check(await evaluate("document.getElementById('result').getAttribute('data-img') && !document.querySelector('.driver-overlay')"), 'Disabled QR completes without a missing-target trap');

  await mode('guide-hidden-result'); await start(); await selectFrame(); await openCamera(); await verifyShootStep();
  await click('#hmu-filter-shoot'); await at('capturing'); await at('idle');
  check(await evaluate("document.getElementById('result').getAttribute('data-img')&&!document.querySelector('.driver-overlay')"), 'Hidden result actions never strand a QR step');

  await mode('guide-capture-fail'); await start(); await selectFrame(); await openCamera(); await verifyShootStep();
  await click('#hmu-filter-shoot'); await at('capturing'); await at('idle');
  check(await evaluate("document.getElementById('loader').classList.contains('error')&&!document.querySelector('.driver-overlay')"), 'A real capture failure ends the guide and preserves the application error');

  await mode('guide');
  await cdp.send('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
  await cdp.send('Emulation.setEmulatedMedia', {features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  await start();
  await screenshot('guide-mobile');
  check(await evaluate("(()=>{const b=document.querySelector('.driver-popover').getBoundingClientRect();return b.left>=0&&b.right<=innerWidth&&b.top>=0&&b.bottom<=innerHeight;})()"), 'Guide popover fits the mobile viewport with reduced motion');
  await escape(); await at('idle');
  await cdp.send('Emulation.clearDeviceMetricsOverride');
  await cdp.send('Emulation.setEmulatedMedia', {features:[]});

  await mode('classic');
  check(!await evaluate("!!document.getElementById('hmu-guide-start') || !!document.querySelector('.driver-overlay')"), 'Classic layout remains outside the modern guide');
  await mode('guide');
  await evaluate("document.querySelector('.takePic').click()");
  await waitFor(() => evaluate("hmuFilterStage.active&&!document.getElementById('hmu-filter-shoot').disabled"), 'unguided camera');
  check(await phase() === 'idle', 'Normal capture remains available without starting the tour');

  await mode('guide-keyboard');
  await evaluate("document.getElementById('hmu-guide-start').focus()");
  await cdp.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',text:'\r',windowsVirtualKeyCode:13});
  await cdp.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
  await at('frames');
  check(!await evaluate('hmuFilterStage.active||photoBooth.takingPic'), 'Enter on Hướng dẫn starts only the tour even when Enter is configured as shutter');
  await evaluate("document.querySelector('.hmu-frame-card').focus()");
  await cdp.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',text:'\r',windowsVirtualKeyCode:13});
  await cdp.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
  await at('open-camera');
  check(!await evaluate('hmuFilterStage.active||photoBooth.takingPic'), 'Enter selects a frame without also triggering a configured shutter keyboard shortcut');
  await escape(); await at('idle');
  await evaluate('document.activeElement.blur()');
  await cdp.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',text:'\r',windowsVirtualKeyCode:13});
  await cdp.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
  await waitFor(()=>evaluate('hmuFilterStage.active'), 'normal shutter shortcut after guide');
  check(await phase()==='idle', 'Configured shutter keyboard shortcut works normally after exiting the guide');

  await mode('guide-space');
  await evaluate("document.getElementById('hmu-guide-start').focus()");
  await cdp.send('Input.dispatchKeyEvent',{type:'keyDown',key:' ',code:'Space',text:' ',windowsVirtualKeyCode:32});
  await cdp.send('Input.dispatchKeyEvent',{type:'keyUp',key:' ',code:'Space',windowsVirtualKeyCode:32});
  await at('frames');
  check(!await evaluate('hmuFilterStage.active||photoBooth.takingPic'), 'Space on Hướng dẫn starts only the tour even when Space is configured as shutter');
  await escape(); await at('idle');
}
