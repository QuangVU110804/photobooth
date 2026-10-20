/** End-to-end real PHP/GD + Chrome fake camera. No npm package required. */
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { promises as fs } from 'node:fs';
import { createServer } from 'node:net';
import { createServer as createHttpServer } from 'node:http';
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
let server, browser, cdp, catalogCacheServer;
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
  await (async () => {
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
  await fs.writeFile(path.join(fixture,'hardware.php'), `<?php
    $dir=__DIR__; $action=$argv[1]; file_put_contents($dir.'/hardware.log',$action."\\n",FILE_APPEND);
    if($action==='capture') {
      if(!file_exists($dir.'/attempted')){file_put_contents($dir.'/attempted','1');exit(1);}
      copy(${quote(path.join(root,'scripts/fixtures/filter-face.jpg'))},$argv[2]);
    }
  `);
  await fs.writeFile(cameraFile, Buffer.concat([Buffer.from('YUV4MPEG2 W320 H480 F10:1 Ip A1:1 C420jpeg\n'),...Array.from({length:20},()=>Buffer.concat([Buffer.from('FRAME\n'),yuv]))]));
  const portServer=createServer(); await new Promise((resolve)=>portServer.listen(0,'127.0.0.1',resolve)); const port=portServer.address().port; await new Promise((resolve)=>portServer.close(resolve));
  const base=`http://127.0.0.1:${port}/photobooth`;
  server=spawn(php,['-d',`session.save_path=${fixture}`,'-d',`sys_temp_dir=${fixture}`,'-d',`upload_tmp_dir=${fixture}`,'-S',`127.0.0.1:${port}`,'-t',path.dirname(root),path.join(root,'scripts/filter-test-router.php')],{cwd:root,env:{...process.env,PHOTOBOOTH_FILTER_TEST_DIR:fixture},stdio:['ignore','ignore','pipe'],windowsHide:true});
  server.stderr.on('data',(data)=>{serverLog+=data;});
  await waitFor(async()=>{try{const response=await fetch(`${base}/resources/js/filter-stage.js`);await response.arrayBuffer();return response.ok;}catch{return false;}},'PHP server');
  browser=spawn(chrome,['--headless=new','--no-sandbox','--disable-gpu','--enable-unsafe-swiftshader','--no-first-run','--no-default-browser-check','--use-fake-ui-for-media-stream','--use-fake-device-for-media-stream',`--use-file-for-fake-video-capture=${cameraFile}`,'--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{stdio:['ignore','ignore','ignore'],windowsHide:true});
  const debugPort=await waitFor(async()=>{try{return(await fs.readFile(path.join(profile,'DevToolsActivePort'),'utf8')).split('\n')[0];}catch{return false;}},'Chrome');
  const targets=await(await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
  const socket=new WebSocket(targets.find((item)=>item.type==='page').webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
  cdp=new CDP(socket); await cdp.send('Page.enable'); await cdp.send('Runtime.enable');
  // A returning browser may have cached the crown-only catalog as fresh.
  // Serve the actual loader, prime that cache, then change the server's catalog.
  const catalogSource=await fs.readFile(path.join(root,'resources/face-effects/catalog.json'),'utf8');
  const loaderSource=await fs.readFile(path.join(root,'resources/js/face-stickers.js'),'utf8');
  let latestCatalog=false, catalogRequests=0;
  catalogCacheServer=createHttpServer((request,response)=>{
    if(request.url==='/resources/face-effects/catalog.json') {
      catalogRequests++;
      response.writeHead(200,{'Content-Type':'application/json','Cache-Control':'public, max-age=86400'});
      response.end(latestCatalog?catalogSource:JSON.stringify({version:1,effects:JSON.parse(catalogSource).effects.filter(e=>e.id==='gold-crown')}));
    } else if(request.url==='/face-stickers.js') {
      response.writeHead(200,{'Content-Type':'text/javascript'});response.end(loaderSource);
    } else { response.writeHead(200,{'Content-Type':'text/html'});response.end('<script src="/face-stickers.js"></script>'); }
  });
  await new Promise(resolve=>catalogCacheServer.listen(0,'127.0.0.1',resolve));
  await cdp.send('Page.navigate',{url:`http://127.0.0.1:${catalogCacheServer.address().port}/`});
  await waitFor(()=>cdp.evaluate("typeof HmuFaceStickers!=='undefined'"),'catalog cache fixture');
  check((await cdp.evaluate('HmuFaceStickers.loadCatalog().then(effects=>effects.map(e=>e.id))')).join(',')==='gold-crown','Returning browser initially has old catalog');
  latestCatalog=true;
  const refreshedCatalog=await cdp.evaluate('HmuFaceStickers.loadCatalog().then(effects=>effects.map(e=>e.id))');
  check(JSON.parse(catalogSource).effects.every(effect=>refreshedCatalog.includes(effect.id)),'Catalog refresh bypasses a fresh cached crown-only response');
  check(catalogRequests===2,'Each catalog load reaches the server for current effects');
  await cdp.send('Page.navigate',{url:'about:blank'});
  catalogCacheServer.closeAllConnections();
  await new Promise(resolve=>catalogCacheServer.close(resolve));catalogCacheServer=null;
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
      await fs.mkdir(path.join(root,'reports'),{recursive:true});
      await fs.copyFile(saved,path.join(root,'reports',`export-${label}.jpg`));
      await fs.copyFile(plain,path.join(root,'reports',`export-${label}-plain.jpg`));
      console.log(`${label} JPG pixel evidence: ${JSON.stringify(stats)}`);
      check(stats.facePixels>20,`${label}: face effect is baked into JPG (${stats.facePixels} changed pixels)`);
      check(stats.colorPixels>100,`${label}: color filter is baked into JPG (${stats.colorPixels} changed pixels)`);
    } finally { await fs.writeFile(finalPath,original); }
  };
  const openStage=async()=>{await cdp.evaluate("document.querySelector('.takePic').click()");await waitFor(()=>cdp.evaluate("hmuFilterStage.active && !document.getElementById('hmu-filter-shoot').disabled"),'stage camera');};
  await load();
  if (process.argv.includes('--guide-only')) {
    const {checkGuideTour}=await import('./guide-tour-browser-checks.mjs');
    const screenshot=async(name)=>{await fs.mkdir(path.join(root,'reports'),{recursive:true});const shot=await cdp.send('Page.captureScreenshot',{format:'png'});await fs.writeFile(path.join(root,'reports',`${name}.png`),Buffer.from(shot.data,'base64'));};
    await checkGuideTour({cdp,check,waitFor,load,logs,base,screenshot});
    check(cdp.errors.length===0,`No guide browser errors: ${cdp.errors.join('; ')}`);
    check(!/PHP (?:Warning|Fatal error|Notice|Deprecated)/i.test(serverLog),'No guide PHP diagnostics');
    console.log(`${checks} spotlight guide checks passed`);return;
  }
  if (process.argv.includes('--beauty-only')) {
    const {checkBeautifier}=await import('./beautifier-browser-checks.mjs');
    await checkBeautifier({cdp,check,waitFor,openStage,logs,fixture,root,fs,base,verifyExport});
    check(cdp.errors.length===0,`No beauty browser errors: ${cdp.errors.join('; ')}`);
    check(!/PHP (?:Warning|Fatal error|Notice|Deprecated)/i.test(serverLog),'No beauty PHP diagnostics');
    console.log(`${checks} full beautifier GPU/camera/PHP checks passed`);return;
  }
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
  check(await cdp.evaluate("document.getElementById('hmu-camera-source')?.value==='browser' && document.getElementById('hmu-camera-device')?.options.length>0"), 'Camera source and detected device can be selected');
  check(await cdp.evaluate("document.getElementById('hmu-preview-frame')?.complete && document.getElementById('hmu-preview-frame')?.naturalWidth>0"), 'Selected frame is loaded above camera preview');
  await cdp.evaluate("document.getElementById('hmu-camera-device').dispatchEvent(new Event('change'))");
  await waitFor(()=>cdp.evaluate("!document.getElementById('hmu-filter-shoot').disabled && document.getElementById('hmu-filter-video').srcObject===photoboothPreview.stream"),'camera switch finishes');
  check(await cdp.evaluate("photoboothPreview.stream.getVideoTracks()[0].readyState==='live'"),'Selecting a device reopens a live stream');
  await cdp.evaluate("document.getElementById('hmu-camera-source').value='external';document.getElementById('hmu-camera-source').dispatchEvent(new Event('change'))");
  check(await cdp.evaluate("hmuFilterStage.captureSource==='external'&&!config.preview.camTakesPic"),'External source routes capture to the configured hardware command');
  await cdp.evaluate("document.getElementById('hmu-camera-source').value='browser';document.getElementById('hmu-camera-source').dispatchEvent(new Event('change'))");
  check(await cdp.evaluate("hmuFilterStage.captureSource==='browser'&&config.preview.camTakesPic"),'Browser source routes capture to the preview camera');
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
    window.previewWork={pending:new Set(),queued:[],peak:0,hold:false,starts:new Map(),timings:[]};
    window.NativeFaceWorker=Worker;
    window.Worker=class extends NativeFaceWorker {
      postMessage(data,...args) {
        if(data.type==='video'){previewWork.pending.add(data.id);previewWork.starts.set(data.id,{time:performance.now(),png:!!data.indices});previewWork.peak=Math.max(previewWork.peak,previewWork.pending.size);}
        super.postMessage(data,...args);
      }
      set onmessage(handler) {
        super.onmessage=(event)=>{
          if(!previewWork.pending.has(event.data.id)){handler(event);return;}
          const start=previewWork.starts.get(event.data.id);previewWork.starts.delete(event.data.id);
          previewWork.timings.push({ms:performance.now()-start.time,png:start.png});
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
  check(await cdp.evaluate("!document.getElementById('hmu-countdown-preview').hidden && document.getElementById('hmu-countdown-preview').contains(document.getElementById('hmu-preview-frame')) && getComputedStyle(document.getElementById('hmu-filter-surface')).filter.includes('grayscale') && !document.getElementById('hmu-filter-video').paused"), 'Countdown keeps the selected frame, color, live camera and face overlay');
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
  check(await cdp.evaluate("!document.dispatchEvent(new WheelEvent('wheel',{ctrlKey:true,cancelable:true})) && document.dispatchEvent(new WheelEvent('wheel',{cancelable:true})) && !document.dispatchEvent(new KeyboardEvent('keydown',{key:'+',ctrlKey:true,cancelable:true}))"), 'Kiosk blocks zoom shortcuts while preserving normal scrolling');
  await cdp.evaluate("document.querySelector('.gallery-button,.gallerybtn').click()");
  await waitFor(()=>cdp.evaluate("document.querySelectorAll('#galimages > a').length>=2"),'two captured images in gallery');
  check(await cdp.evaluate("!!document.getElementById('hmu-gallery-select')"),'Gallery provides multi-select action');
  await cdp.evaluate("document.querySelector('#galimages > a').click()");
  await waitFor(()=>cdp.evaluate("!!window.globalGalleryHandle?.currItem"),'photo viewer');
  check(await cdp.evaluate("(()=>{const g=globalGalleryHandle,z=g.getZoomLevel();g.zoomTo(z*2);return g.getZoomLevel()===z&&g.options.zoomEl===false;})()"),'Photo viewer keeps fitted zoom level');
  await delay(400);
  check(await cdp.evaluate(`(async()=>{
    const target=document.querySelector('.pswp__scroll-wrap');
    const send=(type,id,x)=>target.dispatchEvent(new PointerEvent(type,{pointerId:id,pointerType:'touch',clientX:x,clientY:250,bubbles:true,cancelable:true,buttons:type==='pointerup'?0:1}));
    const fit=globalGalleryHandle.currItem.initialZoomLevel;
    let pointerDowns=0;const count=()=>pointerDowns++;document.addEventListener('pointerdown',count,true);
    send('pointerdown',1,130);send('pointerdown',2,230);
    send('pointermove',1,30);send('pointermove',2,330);
    await new Promise(resolve=>setTimeout(resolve,100));
    const during=globalGalleryHandle.getZoomLevel();
    send('pointerup',1,30);send('pointerup',2,330);
    await new Promise(resolve=>setTimeout(resolve,350));
    document.removeEventListener('pointerdown',count,true);
    return pointerDowns===1&&Math.abs(during-fit)<.001&&Math.abs(globalGalleryHandle.getZoomLevel()-fit)<.001;
  })()`),'Pointer-event pinch cannot change gallery zoom during or after the gesture');
  await cdp.evaluate("globalGalleryHandle.close();document.getElementById('hmu-gallery-select').click();document.querySelector('#galimages > a').click()");
  check(await cdp.evaluate("hmuGallerySelection.active && document.querySelectorAll('#galimages > a[aria-checked=true]').length===1"),'Clicking in selection mode selects an image without opening the viewer');
  const selectedFile=await cdp.evaluate("decodeURIComponent(document.querySelector('#galimages > a[aria-checked=true]').getAttribute('href').split('/').pop())");
  await cdp.evaluate("window.confirm=()=>false;document.getElementById('hmu-gallery-delete').click()");
  check((await fs.stat(path.join(fixture,'images',selectedFile))).size>0,'Cancelling confirmation preserves the selected photo');
  await cdp.send('Fetch.enable',{patterns:[{urlPattern:'*/api/deletePhoto.php',requestStage:'Request'}]});
  await cdp.evaluate("window.confirm=()=>true;document.getElementById('hmu-gallery-delete').click()");
  const pendingDelete=await waitFor(()=>Promise.resolve(cdp.events.shift()),'held gallery deletion');
  const busyNavigationBlocked=await cdp.evaluate(`(()=>{
    hmuGallerySelection.cancel();
    const link=document.querySelector('#galimages > a');
    let prevented=null;
    const inspect=event=>{prevented=event.defaultPrevented;event.preventDefault();};
    document.addEventListener('click',inspect,{once:true});
    const event=new MouseEvent('click',{bubbles:true,cancelable:true});
    link.dispatchEvent(event);
    document.removeEventListener('click',inspect);
    return prevented===null?event.defaultPrevented:prevented;
  })()`);
  check(busyNavigationBlocked,'Cancelling selection during deletion cannot navigate away to a raw JPEG');
  await cdp.send('Fetch.continueRequest',{requestId:pendingDelete.requestId});await cdp.send('Fetch.disable');
  await waitFor(()=>cdp.evaluate("document.querySelectorAll('#galimages > a').length===1"),'selected image deleted');
  check(!(await fs.access(path.join(fixture,'images',selectedFile)).then(()=>true,()=>false)),'Batch deletion removes the selected image from disk');
  check((await fs.readdir(path.join(fixture,'images'))).length===1,'Unselected image remains on disk');
  await cdp.evaluate("document.getElementById('hmu-gallery-select').click();document.getElementById('hmu-gallery-all').click()");
  check(await cdp.evaluate("document.querySelectorAll('#galimages > a[aria-checked=true]').length===1"),'Select all selects remaining photos');
  await cdp.evaluate("document.getElementById('hmu-gallery-clear').click();document.getElementById('hmu-gallery-cancel').click();document.querySelector('.gallery__close').click()");
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
  // Real PNG catalog -> MediaPipe preview -> fresh still detection -> GD/JPG.
  // Blue Atelier's opaque header covers this fixture's forehead. Pick a frame
  // with a shallow header; preserve production frame ordering/occlusion.
  await cdp.evaluate("document.getElementById('hmu-filter-back').click();[...document.querySelectorAll('.hmu-frame-card')].find(c=>c.dataset.frame.startsWith('Concept B')).click();hmuFrameSelection.ready()");
  await openStage();
  const pngFrame=await cdp.evaluate('hmuFilterStage.frame');
  await waitFor(()=>cdp.evaluate("!!document.querySelector('[data-face=gold-crown]')"),'PNG catalog option');
  check(await cdp.evaluate("!document.querySelector('[data-face=gold-crown]').disabled"),'PNG sticker available with browser camera');
  await cdp.evaluate("document.querySelector('[data-color=\"cssgram-moon\"]').click();document.querySelector('[data-face=gold-crown]').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.face==='gold-crown'&&!document.getElementById('hmu-filter-shoot').disabled"),'PNG assets decoded');
  await waitFor(()=>cdp.evaluate("(()=>{const c=document.getElementById('hmu-filter-overlay');return c.getContext('2d').getImageData(0,0,c.width,c.height).data.some((n,i)=>i%4===3&&n>0);})()"),'PNG preview follows actual face');
  await waitFor(()=>cdp.evaluate("previewWork.timings.filter(t=>t.png).length>=8"),'PNG inference timing samples');
  const inference=await cdp.evaluate("previewWork.timings.filter(t=>t.png).map(t=>t.ms).sort((a,b)=>a-b)");
  console.log('PNG Worker round-trip evidence:',JSON.stringify({samples:inference.length,median_ms:inference[Math.floor(inference.length/2)],p95_ms:inference[Math.floor((inference.length-1)*.95)]}));
  check(await cdp.evaluate('previewWork.peak===1'),'PNG preview also keeps one inference in flight');
  await cdp.evaluate("Object.defineProperty(document,'hidden',{configurable:true,get:()=>true});document.dispatchEvent(new Event('visibilitychange'))");
  await delay(200);
  const hiddenSamples=await cdp.evaluate('previewWork.timings.length');await delay(400);
  check(await cdp.evaluate(`previewWork.timings.length===${hiddenSamples}`),'Hidden tab stops PNG inference scheduling');
  await cdp.evaluate("delete document.hidden;document.dispatchEvent(new Event('visibilitychange'))");
  await waitFor(()=>cdp.evaluate(`previewWork.timings.length>${hiddenSamples}`),'PNG inference resumes when visible');
  await cdp.evaluate('previewWork.hold=true');
  await waitFor(()=>cdp.evaluate('previewWork.queued.length>0'),'PNG inference response held');
  await cdp.evaluate("document.querySelector('[data-face=glasses]').click();previewWork.hold=false;previewWork.queued.splice(0).forEach(deliver=>deliver())");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.face==='glasses'&&!document.getElementById('hmu-filter-shoot').disabled"),'legacy effect after PNG in-flight');
  check(await cdp.evaluate('previewWork.peak===1'),'Changing PNG to legacy does not queue another inference');
  await cdp.evaluate("document.querySelector('[data-face=gold-crown]').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.face==='gold-crown'&&!document.getElementById('hmu-filter-shoot').disabled"),'PNG restored before shoot');
  const pngBefore=(await logs()).length;
  await cdp.evaluate("document.getElementById('hmu-filter-shoot').click();document.getElementById('hmu-filter-shoot').click()");
  check(await cdp.evaluate("hmuFilterStage.countingDown&&hmuFilterStage.face==='gold-crown'"),'Countdown retains PNG sticker');
  await waitFor(()=>cdp.evaluate("!photoBooth.takingPic&&!!document.getElementById('result').getAttribute('data-img')"),'PNG capture completes',45000);
  const pngRequests=(await logs()).slice(pngBefore),pngExport=pngRequests.find(r=>r.route==='api/applyEffects.php');
  check(pngRequests.filter(r=>r.route==='api/takePic.php').length===1,'PNG double click captures only once');
  check(JSON.parse(pngExport.faceEffects)[pngExport.file].some(e=>e.type==='sticker'&&e.effect==='gold-crown'),'Fresh still-image payload contains catalog PNG');
  check(pngExport.frame===pngFrame&&pngExport.filter==='cssgram-moon','PNG keeps chosen frame and color');
  console.log('PNG placement evidence:',pngExport.faceEffects, 'frame:',pngExport.frame);
  await verifyExport(pngExport,'moon-crown');
  await cdp.evaluate("document.querySelector('.newpic').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.active&&!document.getElementById('hmu-filter-shoot').disabled"),'session after PNG');
  check(await cdp.evaluate("hmuFilterStage.face==='none'"),'PNG does not leak into next session');
  // Supplied PNG assets must survive real browser decoding, landmarks and JPG export.
  check(await cdp.evaluate("['glasses','blush','gold-crown','hat','mask'].every(id=>!!document.querySelector('[data-face='+id+']'))"),'Old and new face filters remain selectable together');
  for(const id of ['hat','mask']) {
    await cdp.evaluate(`document.querySelector('[data-color="cssgram-moon"]').click();document.querySelector('[data-face=${id}]').click()`);
    await waitFor(()=>cdp.evaluate(`hmuFilterStage.face==='${id}'&&!document.getElementById('hmu-filter-shoot').disabled`),id+' PNG decoded');
    await waitFor(()=>cdp.evaluate("(()=>{const c=document.getElementById('hmu-filter-overlay');return c.getContext('2d').getImageData(0,0,c.width,c.height).data.some((n,i)=>i%4===3&&n>0);})()"),id+' preview visible');
    const before=(await logs()).length;
    await cdp.evaluate("document.getElementById('hmu-filter-shoot').click()");
    await waitFor(()=>cdp.evaluate("!photoBooth.takingPic&&!!document.getElementById('result').getAttribute('data-img')"),id+' capture completes',45000);
    const exported=(await logs()).slice(before).find(r=>r.route==='api/applyEffects.php');
    check(JSON.parse(exported.faceEffects)[exported.file].some(e=>e.type==='sticker'&&e.effect===id),id+' uses fresh still-image placement');
    await verifyExport(exported,'moon-'+id);
    await cdp.evaluate("document.querySelector('.newpic').click()");
    await waitFor(()=>cdp.evaluate("hmuFilterStage.active&&!document.getElementById('hmu-filter-shoot').disabled"),'session after '+id);
  }
  // Slow decode is an external boundary; keep real catalog and renderer.
  await cdp.evaluate(`window.pngNativeLoader=HmuFaceStickers.loadPng;window.pngRelease=null;
    window.pngOriginalPrepare=HmuFaceStickers.AssetCache.prototype.prepare;
    HmuFaceStickers.AssetCache.prototype.prepare=function(effect){return new Promise((resolve,reject)=>{window.pngRelease=()=>window.pngOriginalPrepare.call(this,effect).then(images=>{window.pngTestBitmaps=[...images.values()];resolve(images);},reject);});};
    document.querySelector('[data-face=gold-crown]').click();`);
  check(await cdp.evaluate("document.getElementById('hmu-filter-shoot').disabled"),'Shoot waits for PNG loading');
  await cdp.evaluate("document.querySelector('[data-face=none]').click();pngRelease();HmuFaceStickers.AssetCache.prototype.prepare=pngOriginalPrepare");
  await delay(200);
  check(await cdp.evaluate("hmuFilterStage.face==='none'&&!document.getElementById('hmu-filter-shoot').disabled"),'None wins over delayed PNG loading');
  await waitFor(()=>cdp.evaluate('window.pngTestBitmaps?.length>0'),'late PNG finishes decoding');
  await cdp.evaluate("document.getElementById('hmu-filter-back').click()");
  check(await cdp.evaluate('pngTestBitmaps.every(image=>image.width===0&&image.height===0)'),'Back closes actual decoded PNG bitmaps');
  await openStage();
  await cdp.send('Fetch.enable',{patterns:[{urlPattern:'*/resources/face-effects/stickers/crown.png',requestStage:'Request'}]});
  await cdp.evaluate("document.querySelector('[data-face=gold-crown]').click()");
  const failedPng=await waitFor(()=>Promise.resolve(cdp.events.shift()),'PNG network request');
  await cdp.send('Fetch.fulfillRequest',{requestId:failedPng.requestId,responseCode:404,body:''});await cdp.send('Fetch.disable');
  await waitFor(()=>cdp.evaluate("document.querySelector('[data-face=gold-crown]').disabled&&!document.getElementById('hmu-filter-shoot').disabled"),'failed PNG disabled');
  check(await cdp.evaluate("hmuFilterStage.face==='none'&&!document.querySelector('[data-face=glasses]').disabled"),'Missing PNG preserves none and legacy capture');
  await cdp.evaluate("document.querySelector('[data-face=glasses]').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.face==='glasses'&&!document.getElementById('hmu-filter-shoot').disabled"),'legacy still works after missing PNG');
  await cdp.evaluate("document.getElementById('hmu-filter-back').click()");
  await cdp.send('Fetch.enable',{patterns:[{urlPattern:'*/resources/face-effects/catalog.json',requestStage:'Request'}]});
  await load();await openStage();
  const failedCatalog=await waitFor(()=>Promise.resolve(cdp.events.shift()),'catalog network request');
  await cdp.send('Fetch.fulfillRequest',{requestId:failedCatalog.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from('{"version":2,"effects":[]}').toString('base64')});await cdp.send('Fetch.disable');
  await delay(100);
  check(await cdp.evaluate("!document.querySelector('[data-face=gold-crown]')&&!document.getElementById('hmu-filter-shoot').disabled&&!document.querySelector('[data-face=glasses]').disabled"),'Malformed catalog preserves existing capture/effects');
  await cdp.evaluate("document.getElementById('hmu-filter-back').click()");
  await cdp.evaluate("sessionStorage.clear();document.cookie='filterTestMode=external-url;path=/'");
  await load(); await cdp.evaluate("document.querySelector('.takePic').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.active&&!document.getElementById('hmu-filter-shoot').disabled"),'URL preview ready');
  await cdp.evaluate("document.getElementById('hmu-camera-source').value='browser';document.getElementById('hmu-camera-source').dispatchEvent(new Event('change'))");
  await waitFor(()=>cdp.evaluate("!document.getElementById('hmu-filter-shoot').disabled&&!!photoboothPreview.stream"),'URL to browser ready');
  await cdp.evaluate("document.getElementById('hmu-camera-source').value='external';document.getElementById('hmu-camera-source').dispatchEvent(new Event('change'))");
  await waitFor(()=>cdp.evaluate("!document.getElementById('hmu-filter-shoot').disabled"),'external restored');
  check(await cdp.evaluate("config.preview.mode==='url'&&!document.getElementById('hmu-filter-ipcam').hidden&&!photoboothPreview.stream"),'Switching back to external restores its URL preview and releases browser camera');
  await cdp.evaluate("sessionStorage.clear();document.cookie='filterTestMode=external-retry;path=/'");
  await load(); await openStage();
  await cdp.evaluate("document.getElementById('hmu-filter-shoot').click()");
  await waitFor(()=>cdp.evaluate("!photoBooth.takingPic&&document.getElementById('result').getAttribute('data-img')"),'external retry completes',45000);
  const hardwareLog=await fs.readFile(path.join(fixture,'hardware.log'),'utf8');
  check(hardwareLog.split('\n').filter(x=>x==='capture').length===2,'External hardware command retries once and saves a photo');
  check(hardwareLog.split('\n').filter(x=>x==='start').length>=2,'External preview command restarts before retry');
  await cdp.evaluate("sessionStorage.clear();document.cookie='filterTestMode=;path=/'");
  const denial=await cdp.send('Page.addScriptToEvaluateOnNewDocument',{source:"navigator.mediaDevices.getUserMedia=()=>Promise.reject(new DOMException('Permission denied','NotAllowedError'));"});
  await load();await cdp.evaluate("document.querySelector('.takePic').click()");
  await waitFor(()=>cdp.evaluate("document.getElementById('hmu-filter-status').textContent.includes('Không mở được camera')"),'camera denial');
  check(await cdp.evaluate("document.getElementById('hmu-filter-shoot').disabled"),'Device-camera capture is blocked when camera permission is denied');
  check(await cdp.evaluate("document.querySelector('[data-face=glasses]').disabled"),'Face effect unavailable when camera fails');
  await cdp.evaluate("document.cookie='filterTestMode=external;path=/'");
  await cdp.evaluate("sessionStorage.removeItem('hmu-camera-source')");
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
  })();
} catch(error) {
  if(cdp && process.argv.includes('--guide-only')) {
    console.error('Guide diagnostics:', await cdp.evaluate("({phase:document.body.dataset.hmuGuideStep,active:document.activeElement?.outerHTML.slice(0,150),popover:document.querySelector('.driver-popover')?.outerHTML.slice(0,1200),camera:document.getElementById('hmu-filter-stage')?.hidden})").catch(()=>null));
    console.error('Guide geometry:',await cdp.evaluate("({grid:document.getElementById('kioskFrameSelector')?.getBoundingClientRect().toJSON(),popover:document.querySelector('.driver-popover')?.getBoundingClientRect().toJSON(),scroll:document.getElementById('start')?.scrollTop,height:document.getElementById('start')?.scrollHeight,padding:document.querySelector('.hmu-main')&&getComputedStyle(document.querySelector('.hmu-main')).paddingBottom,viewport:[innerWidth,innerHeight]})").catch(()=>null));
    await fs.mkdir(path.join(root,'reports'),{recursive:true});
    const shot=await cdp.send('Page.captureScreenshot',{format:'png'}).catch(()=>null);
    if(shot) await fs.writeFile(path.join(root,'reports/guide-tour-failure.png'),Buffer.from(shot.data,'base64'));
  }
  if(cdp) console.error('Browser diagnostics:',await cdp.evaluate("({captureError:window.lastCaptureError,status:document.getElementById('hmu-filter-status')?.textContent,loading:document.querySelector('.loading')?.textContent,body:document.body.innerText.slice(-600),errors:[]})").catch(()=>null),cdp.errors);
  console.error(serverLog.split('\n').filter(line=>/PHP|POST|_filter-test-data/.test(line)).join('\n'));throw error;
} finally {
  catalogCacheServer?.closeAllConnections();catalogCacheServer?.close();
  cdp?.socket.close();browser?.kill();server?.kill();await delay(1000);
  const resolved=path.resolve(fixture);
  if(resolved.startsWith(path.resolve(tmpdir())+path.sep)&&path.basename(resolved).startsWith('photobooth-filter-test-')) await fs.rm(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:250});
}
