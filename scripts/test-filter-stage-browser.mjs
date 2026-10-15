/** End-to-end real PHP/GD + Chrome fake camera. No npm package required. */
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { promises as fs } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const php = process.env.PHOTOBOOTH_TEST_PHP || 'C:\\xampp\\php\\php.exe';
const chrome = process.env.PHOTOBOOTH_TEST_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const fixture = await fs.mkdtemp(path.join(tmpdir(), 'photobooth-filter-test-'));
const profile = path.join(fixture, 'chrome');
await fs.mkdir(profile);
for (const folder of ['images','tmp','thumbs','qrcodes','keying','print']) await fs.mkdir(path.join(fixture, folder));
let server, browser, cdp;
let checks = 0, serverLog = '';
const check = (value, label) => { assert.ok(value, label); checks++; };
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function waitFor(predicate, label, timeout = 30000) {
  const start = Date.now();
  while (Date.now() - start < timeout) { const result = await predicate(); if (result) return result; await delay(100); }
  throw new Error(`Timed out: ${label}`);
}
class CDP {
  constructor(socket) {
    this.socket = socket; this.id = 0; this.pending = new Map(); this.errors = []; this.events = [];
    socket.addEventListener('message', ({ data }) => {
      const message = JSON.parse(data);
      if (message.method === 'Fetch.requestPaused') this.events.push(message.params);
      if (message.method === 'Runtime.exceptionThrown') this.errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
      const item = this.pending.get(message.id);
      if (item) { clearTimeout(item.timer); this.pending.delete(message.id); message.error ? item.reject(new Error(message.error.message)) : item.resolve(message.result); }
    });
  }
  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = ++this.id;
      const timer = setTimeout(() => { this.pending.delete(id); reject(new Error(`CDP timeout ${method}`)); }, 40000);
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
  // Still portrait frames make actual MediaPipe detection deterministic.
  const quote = (value) => "'" + value.replaceAll('\\','/').replaceAll("'", "\\'") + "'";
  const rgb = execFileSync(php, ['-r', `$s=imagecreatefromjpeg(${quote(path.join(root,'scripts/fixtures/filter-face.jpg'))});$d=imagecreatetruecolor(320,480);imagecopyresampled($d,$s,0,0,0,0,320,480,imagesx($s),imagesy($s));for($y=0;$y<480;$y++)for($x=0;$x<320;$x++){$p=imagecolorat($d,$x,$y);echo chr(($p>>16)&255),chr(($p>>8)&255),chr($p&255);}`], { maxBuffer: 1024 * 1024 });
  const pixels = 320 * 480, yuv = Buffer.alloc(pixels * 1.5);
  const clamp = (n) => Math.max(0, Math.min(255, Math.round(n)));
  for (let y=0;y<480;y++) for(let x=0;x<320;x++) {
    const p=y*320+x, r=rgb[p*3],g=rgb[p*3+1],b=rgb[p*3+2];
    yuv[p]=clamp(16+.257*r+.504*g+.098*b);
    if(!(x%2)&&!(y%2)) { const c=(y/2)*160+x/2; yuv[pixels+c]=clamp(128-.148*r-.291*g+.439*b); yuv[pixels+pixels/4+c]=clamp(128+.439*r-.368*g-.071*b); }
  }
  const cameraFile = path.join(fixture,'camera.y4m');
  await fs.writeFile(cameraFile, Buffer.concat([Buffer.from('YUV4MPEG2 W320 H480 F10:1 Ip A1:1 C420jpeg\n'),...Array.from({length:20},()=>Buffer.concat([Buffer.from('FRAME\n'),yuv]))]));
  const portServer=createServer(); await new Promise((resolve)=>portServer.listen(0,'127.0.0.1',resolve)); const port=portServer.address().port; await new Promise((resolve)=>portServer.close(resolve));
  const base=`http://127.0.0.1:${port}/photobooth`;
  server=spawn(php,['-d',`session.save_path=${fixture}`,'-d',`sys_temp_dir=${fixture}`,'-d',`upload_tmp_dir=${fixture}`,'-S',`127.0.0.1:${port}`,'-t',path.dirname(root),path.join(root,'scripts/filter-test-router.php')],{cwd:root,env:{...process.env,PHOTOBOOTH_FILTER_TEST_DIR:fixture},stdio:['ignore','ignore','pipe'],windowsHide:true});
  server.stderr.on('data',(data)=>{serverLog+=data;});
  await waitFor(async()=>{try{const response=await fetch(`${base}/resources/js/filter-stage.js`);await response.arrayBuffer();return response.ok;}catch{return false;}},'PHP server');
  browser=spawn(chrome,['--headless=new','--no-sandbox','--disable-gpu','--no-first-run','--no-default-browser-check','--use-fake-ui-for-media-stream','--use-fake-device-for-media-stream',`--use-file-for-fake-video-capture=${cameraFile}`,'--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{stdio:['ignore','ignore','ignore'],windowsHide:true});
  const debugPort=await waitFor(async()=>{try{return(await fs.readFile(path.join(profile,'DevToolsActivePort'),'utf8')).split('\n')[0];}catch{return false;}},'Chrome');
  const targets=await(await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
  const socket=new WebSocket(targets.find((item)=>item.type==='page').webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
  cdp=new CDP(socket); await cdp.send('Page.enable'); await cdp.send('Runtime.enable');
  const load=async()=>{await cdp.send('Page.navigate',{url:`${base}/index.php`});await waitFor(()=>cdp.evaluate("document.readyState==='complete' && document.querySelector('.hmu-frame-card') && !document.getElementById('kioskFrameSelector').getAttribute('aria-busy').includes('true')"),'frame list');};
  const logs=async()=>await(await fetch(`${base}/_filter-test-log`)).json();
  // Compare decoded JPG pixels from the real export against controls rendered
  // from the same raw capture and frame, isolating face and color contributions.
  const verifyExport = async (request, label) => {
    const finalPath=path.join(fixture,'images',request.file);
    const original=await fs.readFile(finalPath);
    const saved=path.join(fixture,`${label}-final.jpg`);
    const colorOnly=path.join(fixture,`${label}-color.jpg`);
    const plain=path.join(fixture,`${label}-plain.jpg`);
    await fs.writeFile(saved,original);
    const render=async(filter,destination)=>{
      const response=await fetch(`${base}/api/applyEffects.php`,{method:'POST',body:new URLSearchParams({file:request.file,style:'photo',filter,frame:request.frame})});
      const body=await response.json();
      check(response.ok&&body.file===request.file,`${label}: control export succeeds`);
      await fs.copyFile(finalPath,destination);
    };
    try {
      await render(request.filter,colorOnly);
      await render('plain',plain);
      const stats=JSON.parse(execFileSync(php,['-r',`
        $final=imagecreatefromjpeg($argv[1]);$color=imagecreatefromjpeg($argv[2]);$plain=imagecreatefromjpeg($argv[3]);
        $faceCount=0;$colorCount=0;$grayCount=0;$total=imagesx($final)*imagesy($final);
        for($y=0;$y<imagesy($final);$y++)for($x=0;$x<imagesx($final);$x++){
          $a=imagecolorat($final,$x,$y);$b=imagecolorat($color,$x,$y);$c=imagecolorat($plain,$x,$y);
          $faceDiff=0;$colorDiff=0;
          foreach([0,8,16] as $shift){$faceDiff+=abs((($a>>$shift)&255)-(($b>>$shift)&255));$colorDiff+=abs((($b>>$shift)&255)-(($c>>$shift)&255));}
          if($faceDiff>60)$faceCount++;if($colorDiff>60)$colorCount++;
          $r=($a>>16)&255;$g=($a>>8)&255;$blue=$a&255;if(max($r,$g,$blue)-min($r,$g,$blue)<=3)$grayCount++;
        }
        echo json_encode(['facePixels'=>$faceCount,'colorPixels'=>$colorCount,'grayPixels'=>$grayCount,'totalPixels'=>$total]);
      `,saved,colorOnly,plain],{encoding:'utf8'}));
      check(stats.facePixels>20,`${label}: face effect is baked into JPG (${stats.facePixels} changed pixels)`);
      check(stats.colorPixels>100,`${label}: color filter is baked into JPG (${stats.colorPixels} changed pixels)`);
      await fs.mkdir(path.join(root,'reports'),{recursive:true});
      await fs.copyFile(saved,path.join(root,'reports',`export-${label}.jpg`));
      await fs.copyFile(plain,path.join(root,'reports',`export-${label}-plain.jpg`));
      console.log(`${label} JPG pixel evidence: ${JSON.stringify(stats)}`);
    } finally { await fs.writeFile(finalPath,original); }
  };
  const openStage=async()=>{await cdp.evaluate("document.querySelector('.takePic').click()");await waitFor(()=>cdp.evaluate("hmuFilterStage.active && !document.getElementById('hmu-filter-shoot').disabled"),'stage camera');};
  await load();
  const previewResponse = await fetch(`${base}/api/framePreview.php?frame=${encodeURIComponent('Golden Atelier.png')}`);
  const previewBytes = await previewResponse.arrayBuffer();
  const originalFrame = await fs.stat(path.join(root, 'resources/img/frames/Golden Atelier.png'));
  check(previewResponse.ok && previewResponse.headers.get('content-type') === 'image/png' && previewBytes.byteLength < originalFrame.size / 4, 'Frame preview API reduces transfer while keeping PNG transparency');
  const revalidated = await fetch(`${base}/api/framePreview.php?frame=${encodeURIComponent('Golden Atelier.png')}`, { headers: { 'If-None-Match': previewResponse.headers.get('etag') } });
  check(revalidated.status === 304 && (await revalidated.arrayBuffer()).byteLength === 0, 'Unchanged frame previews revalidate without sending image bytes');
  check(await cdp.evaluate("[...document.querySelectorAll('.hmu-frame-thumb img')].every(img=>img.src.includes('/api/framePreview.php?frame='))"), 'Frame cards use cached previews');
  check((await logs()).length===0,'Initial frame auto-selection never starts capture');
  await cdp.evaluate("photoBooth.thrill('collage');hmuFilterStage.request('collage')");
  check(await cdp.evaluate("!hmuFilterStage.active && !photoBooth.takingPic && !photoboothPreview.stream"),'Removed capture mode cannot open the stage or start a camera');
  await cdp.evaluate("document.querySelectorAll('.hmu-frame-card')[1].click();hmuFrameSelection.ready()");
  check(await cdp.evaluate("!hmuFilterStage.active && !photoboothPreview.stream && !photoBooth.takingPic"),'Selecting a frame stays on the frame screen without opening camera');
  check(await cdp.evaluate("document.querySelectorAll('.hmu-frame-card')[1].getAttribute('aria-pressed')==='true'"),'Frame selection updates selected state');
  check(await cdp.evaluate("!document.querySelector('.takeCollage,.newcollage,[data-style=collage]')"),'Capture UI offers only single photos');
  await openStage();
  check((await logs()).length===0,'Capture button opens filters without countdown/takePic');
  check(await cdp.evaluate("!photoBooth.takingPic && !document.getElementById('loader').classList.contains('open')"),'Countdown stays idle while choosing filters');
  check(await cdp.evaluate("document.getElementById('hmu-filter-video').srcObject === photoboothPreview.stream"),'Live stage shares the existing camera stream');
  await cdp.evaluate("document.getElementById('start').classList.remove('open');document.getElementById('hmu-filter-back').click()");
  check(await cdp.evaluate("(()=>{const start=document.getElementById('start');const style=getComputedStyle(start);const wrapper=getComputedStyle(document.getElementById('wrapper'));return !hmuFilterStage.active&&start.classList.contains('open')&&style.display==='flex'&&start.getBoundingClientRect().height>0&&wrapper.backgroundColor==='rgb(247, 249, 252)';})()"),'Back restores the main screen layout and HMU background');
  await cdp.evaluate("document.getElementById('start').style.display='none';document.getElementById('result').style.display='block';document.querySelector('.takePic').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.active&&!document.getElementById('hmu-filter-shoot').disabled"),'stage reopens from result');
  await cdp.evaluate("document.getElementById('hmu-filter-back').click()");
  check(await cdp.evaluate("(()=>{const start=document.getElementById('start');const style=getComputedStyle(start);const wrapper=getComputedStyle(document.getElementById('wrapper'));return !hmuFilterStage.active&&document.getElementById('result').style.display==='none'&&style.display==='flex'&&start.getBoundingClientRect().height>0&&wrapper.backgroundColor==='rgb(247, 249, 252)';})()"),'Back restores the main screen and HMU background after a result preview');
  await openStage();
  for(const [width,height] of [[320,740],[375,812],[768,1024],[844,390],[1440,1000]]) {
    await cdp.send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<900});
    const layout=await cdp.evaluate("({overflow:document.getElementById('hmu-filter-stage').scrollWidth>innerWidth,button:document.getElementById('hmu-filter-shoot').getBoundingClientRect().height})");
    check(!layout.overflow,`No page overflow ${width}x${height}`);check(layout.button>=48,`Capture touch target ${width}x${height}`);
    check(await cdp.evaluate("document.getElementById('hmu-filter-shoot').getBoundingClientRect().bottom <= innerHeight"),`Confirmation stays visible ${width}x${height}`);
    if(width===375||width===1440){await fs.mkdir(path.join(root,'reports'),{recursive:true});const shot=await cdp.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await fs.writeFile(path.join(root,'reports',`filter-stage-${width===375?'mobile':'desktop'}.png`),Buffer.from(shot.data,'base64'));}
  }
  await cdp.send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  check(await cdp.evaluate("getComputedStyle(document.querySelector('.hmu-filter-option')).transitionDuration.split(',').every(v=>parseFloat(v)===0)"),'Filter UI respects reduced-motion preference');
  await cdp.send('Emulation.setDeviceMetricsOverride',{width:375,height:812,deviceScaleFactor:1,mobile:true});
  await cdp.evaluate("document.documentElement.style.fontSize='200%'");
  check(await cdp.evaluate("document.getElementById('hmu-filter-stage').scrollWidth<=innerWidth"),'200% text has no horizontal page overflow');
  await cdp.evaluate("document.documentElement.style.fontSize='';document.getElementById('hmu-filter-shoot').focus()");
  await cdp.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
  await cdp.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
  check(await cdp.evaluate("document.activeElement.id==='hmu-filter-back'"),'Keyboard focus stays inside filter stage');
  await cdp.evaluate("document.querySelector('[data-color=\"cssgram-inkwell\"]').click()");
  check(await cdp.evaluate("hmuFilterStage.color === 'cssgram-inkwell' && getComputedStyle(document.getElementById('hmu-filter-surface')).filter.includes('grayscale')"),'Color selection immediately changes live CSSgram preview');
  // Keep the real worker/detector; hold its response at the slow external boundary.
  await cdp.evaluate(`
    window.previewWork={pending:new Set(),queued:[],peak:0,hold:false};
    window.NativeFaceWorker=Worker;
    window.Worker=class extends NativeFaceWorker {
      postMessage(data,...args) {
        if(data.type==='video'){previewWork.pending.add(data.id);previewWork.peak=Math.max(previewWork.peak,previewWork.pending.size);}
        super.postMessage(data,...args);
      }
      set onmessage(handler) {
        super.onmessage=(event)=>{
          if(!previewWork.pending.has(event.data.id)){handler(event);return;}
          const deliver=()=>{previewWork.pending.delete(event.data.id);handler(event);};
          previewWork.hold?previewWork.queued.push(deliver):deliver();
        };
      }
    };
  `);
  await cdp.evaluate(`window.OriginalWorker=Worker;window.Worker=class extends OriginalWorker{set onmessage(handler){super.onmessage=(event)=>{if(event.data.ready){window.releaseFilterInit=()=>handler(event);return;}handler(event);};}}; document.querySelector('[data-face=glasses]').click()`);
  await waitFor(()=>cdp.evaluate("typeof releaseFilterInit==='function'"),'worker init held at response boundary');
  await cdp.evaluate("document.querySelector('[data-face=none]').click();releaseFilterInit();window.Worker=OriginalWorker");
  await cdp.evaluate("new Promise(resolve=>setTimeout(resolve,0))");
  check(await cdp.evaluate("hmuFilterStage.face==='none'&&!document.getElementById('hmu-filter-shoot').disabled"),'No-effect selection wins over pending MediaPipe initialization');
  await cdp.evaluate("document.querySelector('[data-face=glasses]').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.face==='glasses' && !document.getElementById('hmu-filter-shoot').disabled"),'actual MediaPipe loads',40000);
  await waitFor(()=>cdp.evaluate("(()=>{const c=document.getElementById('hmu-filter-overlay');return c.width>0&&c.getContext('2d').getImageData(0,0,c.width,c.height).data.some((v,i)=>i%4===3&&v>0);})()"),'real face detection');
  check(await cdp.evaluate("(()=>{const c=document.getElementById('hmu-filter-overlay');return c.getContext('2d').getImageData(0,0,c.width,c.height).data.some((v,i)=>i%4===3&&v>0);})()"),'Actual face landmarks render the glasses overlay');
  await cdp.evaluate('previewWork.hold=true;previewWork.peak=previewWork.pending.size');
  await waitFor(()=>cdp.evaluate('previewWork.queued.length>0'),'slow preview response held');
  await cdp.evaluate("document.querySelector('[data-face=blush]').click()");
  await delay(500);
  check(await cdp.evaluate('previewWork.peak===1'), 'Changing effects during slow detection keeps at most one preview inference in flight');
  await cdp.evaluate("previewWork.hold=false;previewWork.queued.splice(0).forEach(deliver=>deliver());document.querySelector('[data-face=glasses]').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.face==='glasses'&&!document.getElementById('hmu-filter-shoot').disabled"),'glasses restored');
  await cdp.evaluate("window.lastCaptureError=null;const originalError=photoBooth.errorPic;photoBooth.errorPic=(data)=>{window.lastCaptureError=data;return originalError(data);}");
  const before=(await logs()).length;
  await cdp.evaluate("document.getElementById('hmu-filter-shoot').click();document.getElementById('hmu-filter-shoot').click();");
  check(await cdp.evaluate("photoBooth.takingPic && !hmuFilterStage.active"),'Only confirmation starts countdown');
  await waitFor(()=>cdp.evaluate("typeof photoBooth !== 'undefined' && !photoBooth.takingPic && document.getElementById('result').getAttribute('data-img')"),'final filtered photo',40000);
  const first=(await logs()).slice(before);
  check(first.filter((r)=>r.route==='api/takePic.php').length===1,'Double click produces exactly one capture');
  const processed=first.find((r)=>r.route==='api/applyEffects.php');
  check(processed?.filter==='cssgram-inkwell','Selected filter survives takePic and reaches applyEffects');
  check(processed?.frame && JSON.parse(processed.faceEffects)[processed.file].length>0,'Final processing uses selected frame and fresh still-image landmarks');
  check((await fs.stat(path.join(fixture,'images',processed.file))).size>0,'Real PHP/GD saves the final filtered photo');
  await verifyExport(processed,'inkwell-glasses');
  await cdp.evaluate("document.querySelector('.newpic').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.active&&!document.getElementById('hmu-filter-shoot').disabled"),'new photo stage');
  check(await cdp.evaluate("hmuFilterStage.face==='none'&&hmuFilterStage.color==='plain'"),'New session resets effects without leaking previous choices');
  await cdp.evaluate("document.querySelector('[data-color=\"cssgram-reyes\"]').click();document.querySelector('[data-face=blush]').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.face==='blush'&&!document.getElementById('hmu-filter-shoot').disabled"),'blush ready');
  const nextBefore=(await logs()).length;
  await cdp.evaluate("document.getElementById('hmu-filter-shoot').click()");
  await waitFor(()=>cdp.evaluate(`!photoBooth.takingPic && document.getElementById('result').getAttribute('data-img') !== ${JSON.stringify(processed.file)}`),'second photo completes',45000);
  const next=(await logs()).slice(nextBefore);
  check(next.filter((r)=>r.route==='api/takePic.php').length===1,'Second session captures exactly one photo');
  const nextEffects=next.find((r)=>r.route==='api/applyEffects.php');
  check(nextEffects?.style==='photo'&&nextEffects.filter==='cssgram-reyes','Second photo retains the confirmed color');
  check(JSON.parse(nextEffects.faceEffects)[nextEffects.file].some(shape=>shape.color==='pink'),'Blush uses fresh still-image landmarks');
  await verifyExport(nextEffects,'reyes-blush');
  await cdp.evaluate("document.querySelector('.newpic').click()");await waitFor(()=>cdp.evaluate("hmuFilterStage.active&&!document.getElementById('hmu-filter-shoot').disabled"),'cancel stage ready');
  const cancelBefore=(await logs()).length;
  await cdp.evaluate("document.getElementById('hmu-filter-back').click()");
  check(await cdp.evaluate("!hmuFilterStage.active && !photoboothPreview.stream"),'Back cancels selection and releases camera');
  check(await cdp.evaluate("(()=>{const start=document.getElementById('start');const style=getComputedStyle(start);return start.classList.contains('open')&&style.display==='flex'&&start.getBoundingClientRect().height>0;})()"),'Back restores the main screen layout');
  check((await logs()).length===cancelBefore,'Cancel never invokes capture');
  // The socket is the external hardware boundary; keep real controllers and
  // stage lifecycle, and simulate the server's arm/completed handshake.
  await cdp.evaluate(`
    window.buzzerArmed=true;window.buzzerListeners={};
    config.remotebuzzer.usenogpio=true;config.remotebuzzer.userotary=true;
    window.io=()=>({on:(name,handler)=>{buzzerListeners[name]=handler;},emit:(name,command)=>{if(command==='completed')buzzerArmed=true;if(command==='in-progress')buzzerArmed=false;}});
    initRemoteBuzzerFromDOM();
    window.pressTestBuzzer=()=>{if(buzzerArmed){buzzerArmed=false;buzzerListeners['photobooth-socket']('start-picture');}};
    pressTestBuzzer();
  `);
  await waitFor(()=>cdp.evaluate("hmuFilterStage.active&&!document.getElementById('hmu-filter-shoot').disabled"),'buzzer opens selection');
  check(await cdp.evaluate("document.querySelector('#hmu-filter-stage .rotaryfocus.focused')!==null"),'Rotary focus transfers into the filter stage');
  await cdp.evaluate("rotaryController.focusNext()");
  check(await cdp.evaluate("document.activeElement.closest('#hmu-filter-stage')!==null && !document.activeElement.disabled"),'Rotary advances to an enabled stage control');
  await cdp.evaluate("document.getElementById('hmu-filter-back').click()");
  check(await cdp.evaluate("buzzerArmed&&!hmuFilterStage.active&&!photoboothPreview.stream"),'Cancelling rearms hardware trigger and releases camera');
  check(await cdp.evaluate("document.querySelector('#start .rotaryfocus.focused')!==null"),'Cancel restores rotary focus to the frame screen');
  await cdp.evaluate("pressTestBuzzer()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.active&&!document.getElementById('hmu-filter-shoot').disabled"),'buzzer works again after cancel');
  await cdp.evaluate("document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");
  check(await cdp.evaluate("buzzerArmed&&!hmuFilterStage.active"),'Escape also rearms hardware');
  await cdp.evaluate("config.remotebuzzer.usenogpio=false;config.remotebuzzer.userotary=false;initRemoteBuzzerFromDOM()");
  await cdp.send('Fetch.enable',{patterns:[{urlPattern:'*/api/setFrame.php',requestStage:'Request'}]});
  await cdp.evaluate("document.querySelector('.hmu-frame-card').click();document.querySelector('.takePic').click();document.getElementById('hmu-filter-back').click()");
  const paused=await waitFor(()=>Promise.resolve(cdp.events.shift()),'delayed frame write');
  await cdp.send('Fetch.continueRequest',{requestId:paused.requestId});await cdp.send('Fetch.disable');
  await cdp.evaluate("hmuFrameSelection.ready()");
  check(await cdp.evaluate("!hmuFilterStage.active&&!photoboothPreview.stream"),'Cancelled waiting stage cannot reopen when the frame response arrives');
  await cdp.evaluate("document.querySelectorAll('.hmu-frame-card')[0].click();document.querySelectorAll('.hmu-frame-card')[1].click();document.querySelectorAll('.hmu-frame-card')[2].click()");
  await cdp.evaluate("hmuFrameSelection.ready()");
  check(await cdp.evaluate("!hmuFilterStage.active&&!photoboothPreview.stream"),'Rapid frame changes stay at frame selection');
  await openStage();
  const expectedFrame=await cdp.evaluate("document.querySelectorAll('.hmu-frame-card')[2].dataset.frame");
  check((await(await fetch(`${base}/_filter-test-frame`)).text())===expectedFrame,'Serialized frame writes retain the last selection');
  check(await cdp.evaluate(`hmuFilterStage.frame===${JSON.stringify(expectedFrame)}`),'Capture snapshot uses the last selected frame');
  await cdp.evaluate("document.getElementById('hmu-filter-back').click()");
  const denial=await cdp.send('Page.addScriptToEvaluateOnNewDocument',{source:"navigator.mediaDevices.getUserMedia=()=>Promise.reject(new DOMException('Permission denied','NotAllowedError'));"});
  await load();await cdp.evaluate("document.querySelector('.takePic').click()");
  await waitFor(()=>cdp.evaluate("document.getElementById('hmu-filter-status').textContent.includes('Không mở được camera')"),'camera denial');
  check(await cdp.evaluate("document.getElementById('hmu-filter-shoot').disabled"),'Device-camera capture is blocked when camera permission is denied');
  check(await cdp.evaluate("document.querySelector('[data-face=glasses]').disabled"),'Face effect unavailable when camera fails');
  await cdp.evaluate("document.cookie='filterTestMode=external;path=/'");
  await load();await cdp.evaluate("document.querySelector('.takePic').click()");
  await waitFor(()=>cdp.evaluate("document.getElementById('hmu-filter-status').textContent.includes('Chưa mở được camera')"),'DSLR preview denial');
  check(await cdp.evaluate("!document.getElementById('hmu-filter-shoot').disabled"),'External-camera users can choose colors when browser preview is unavailable');
  await cdp.send('Page.removeScriptToEvaluateOnNewDocument',{identifier:denial.identifier});
  await cdp.evaluate("document.cookie='filterTestMode=frame-fail;path=/'");
  await load();await cdp.evaluate("document.querySelector('.takePic').click()");
  await waitFor(()=>cdp.evaluate("document.getElementById('hmu-filter-status').textContent.includes('Chưa lưu được khung')"),'frame error');
  check(await cdp.evaluate("document.getElementById('hmu-filter-shoot').disabled&&!photoBooth.takingPic"),'Frame synchronization failure never starts countdown');
  await cdp.evaluate("document.cookie='filterTestMode=classic;path=/'");
  await cdp.send('Page.navigate',{url:`${base}/index.php`});
  await waitFor(()=>cdp.evaluate("document.readyState==='complete'&&typeof photoBooth!=='undefined'"),'classic page');
  check(await cdp.evaluate("typeof hmuFilterStage==='undefined'"),'Classic layout retains its original flow');
  await cdp.evaluate("photoBooth.thrill('photo')");
  const classicFile=await waitFor(()=>cdp.evaluate("!photoBooth.takingPic&&document.getElementById('result').getAttribute('data-img')"),'classic first photo');
  await cdp.evaluate("document.getElementById('sepia-light').click()");
  await waitFor(()=>cdp.evaluate("jQuery.active===0"),'classic result filter completes');
  const classicBefore=(await logs()).length;
  await cdp.evaluate("photoBooth.thrill('photo')");
  await waitFor(()=>cdp.evaluate(`!photoBooth.takingPic&&document.getElementById('result').getAttribute('data-img')!==${JSON.stringify(classicFile)}`),'classic next photo');
  check((await logs()).slice(classicBefore).find(r=>r.route==='api/applyEffects.php')?.filter==='plain','Classic new capture uses its configured default, not the previous result filter');
  check(cdp.errors.length===0,`No browser errors: ${cdp.errors.join('; ')}`);
  check(!/PHP (?:Warning|Fatal error|Notice|Deprecated)/i.test(serverLog),`No PHP diagnostics: ${serverLog.match(/.*PHP (?:Warning|Fatal error|Notice|Deprecated).*/g)?.join('; ')}`);
  console.log(`${checks} real camera/browser/PHP flow checks passed`);
} catch(error) {
  if(cdp) console.error('Browser diagnostics:',await cdp.evaluate("({captureError:window.lastCaptureError,status:document.getElementById('hmu-filter-status')?.textContent,loading:document.querySelector('.loading')?.textContent,body:document.body.innerText.slice(-600),errors:[]})").catch(()=>null),cdp.errors);
  console.error(serverLog.split('\n').filter(line=>/PHP|POST|_filter-test-data/.test(line)).join('\n'));throw error;
} finally {
  cdp?.socket.close();browser?.kill();server?.kill();await delay(1000);
  const resolved=path.resolve(fixture);
  if(resolved.startsWith(path.resolve(tmpdir())+path.sep)&&path.basename(resolved).startsWith('photobooth-filter-test-')) await fs.rm(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:250});
}
