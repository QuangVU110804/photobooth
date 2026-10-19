/** Exercises the real pinned shaders, local detector and PHP capture path. */
export async function checkBeautifier({cdp,check,waitFor,openStage,logs,fixture,root,fs,base,verifyExport}) {
  const gpu=await cdp.evaluate(`(async()=>{
    const source=new Image();source.src='scripts/fixtures/filter-face.jpg';await source.decode();
    const mapping=HmuSkinBeauty.indices(),worker=new Worker('resources/js/face-effects-worker.js?v=4');
    const request=(data,transfer=[])=>new Promise((resolve,reject)=>{worker.onmessage=({data})=>data.error?reject(new Error(data.error)):resolve(data);worker.onerror=reject;worker.postMessage(data,transfer);});
    await request({id:1,type:'init'});
    const bitmap=await createImageBitmap(source);
    const detected=await request({id:2,type:'image',bitmap,indices:mapping,skin:true},[bitmap]);worker.terminate();
    if(!detected.faces.length)throw new Error('Test portrait has no detected face');
    const canvas=document.createElement('canvas'),engine=await HmuSkinBeauty.createRenderer(canvas,()=>{}),B=HmuBeautySettings;
    engine.setFaces(detected.faces,mapping,true);
    const pixels=state=>{engine.render(source,state,true);return engine.pipeline.readPixels(0,0,canvas.width,canvas.height);};
    const difference=(a,b)=>{let count=0;for(let i=0;i<a.length;i+=4)if(Math.abs(a[i]-b[i])+Math.abs(a[i+1]-b[i+1])+Math.abs(a[i+2]-b[i+2])>3)count++;return count;};
    const results={};
    for(const c of B.allControls()) {
      const before=B.preset('off');
      if(c.key==='texture'||c.key==='radius'){before.values.smooth=90;before.values.texture=0;before.values.radius=0;}
      const a=pixels(before),after=B.validate(before);after.values[c.key]=c.max;
      results[c.key]=difference(a,pixels(after));
    }
    let colors=B.preset('off');colors.values.lipAmount=100;const rose=pixels(colors);colors=B.validate(colors);colors.colors.lip='#7c3557';const lip=difference(rose,pixels(colors));
    colors=B.validate(colors);colors.values.blushAmount=100;const peach=pixels(colors);colors=B.validate(colors);colors.colors.blush='#d98fb0';const blush=difference(peach,pixels(colors));
    const group=Array.from({length:4},(_,i)=>detected.faces[0].map(p=>({x:p.x+(i-1.5)*.1,y:p.y})));
    engine.setFaces(group,mapping,true);engine.render(source,B.preset('doll'),true);
    const multi={faces:engine.meshes.length,warps:engine.geometry(B.preset('doll'),source.naturalWidth,source.naturalHeight).warps.length,error:engine.pipeline.glError()};
    engine.setFaces(detected.faces,mapping,true);engine.view={compare:1.2,showMask:true};
    const data=await engine.export(source,B.preset('natural')),exported=new Image();exported.src=data;await exported.decode();
    const size=[exported.naturalWidth,exported.naturalHeight],original=[source.naturalWidth,source.naturalHeight];
    let bounded=false;try{engine.pipeline.resize(5000,4000);}catch(error){bounded=error.message.includes('tối đa 16 MP')&&canvas.width===size[0]&&canvas.height===size[1];}
    engine.dispose();const again=await HmuSkinBeauty.createRenderer(canvas,()=>{});again.render(source,B.preset('off'));const reused=!again.gl.isContextLost();again.dispose();
    window.beautyTestResults={results,lip,blush,multi,size,original,reused,bounded};return window.beautyTestResults;
  })()`);
  for(const [key,pixels] of Object.entries(gpu.results))check(pixels>0,`${key} changes real GPU pixels (${pixels})`);
  check(gpu.lip>0&&gpu.blush>0,'Both makeup palettes change actual rendered pixels');
  check(gpu.multi.faces===4&&gpu.multi.warps>20&&!gpu.multi.error,'Four faces fit the shader warp/uniform budget');
  check(gpu.size.join(',')===gpu.original.join(','),'GPU export retains original still resolution');
  check(gpu.bounded,'Oversized stills fail before resizing or allocating export buffers');
  check(gpu.reused,'GPU resources can be recreated on the next visit');
  await openStage();
  check(await cdp.evaluate("document.querySelectorAll('[data-beauty]').length===21&&document.querySelectorAll('[data-beauty-preset]').length===6&&document.querySelectorAll('[data-beauty-swatch]').length===10"),'UI exposes all 21 controls, 6 presets and 10 colors');
  check(await cdp.evaluate("!hmuFilterStage.hasEffects&&document.getElementById('hmu-beauty-surface').hidden"),'New visitor starts Off');
  await cdp.evaluate("document.querySelector('[data-beauty-preset=doll]').click()");
  await waitFor(()=>cdp.evaluate("!document.querySelector('[data-beauty=smooth]').disabled&&!document.getElementById('hmu-beauty-surface').hidden"),'full GPU preview ready',45000);
  await waitFor(()=>cdp.evaluate("document.getElementById('hmu-filter-status').textContent.includes('Hiệu ứng đang')"),'face tracking');
  check(await cdp.evaluate("document.querySelector('[data-beauty=slim]').value==='58'&&document.querySelector('[data-beauty=eyes]').value==='46'"),'Doll loads upstream face geometry settings');
  await cdp.evaluate("document.getElementById('hmu-beauty-split').click();document.getElementById('hmu-beauty-split-range').value=35;document.getElementById('hmu-beauty-split-range').dispatchEvent(new Event('input'))");
  check(await cdp.evaluate("!document.getElementById('hmu-beauty-split-range').parentElement.hidden&&document.getElementById('hmu-beauty-split').getAttribute('aria-pressed')==='true'"),'Split view is adjustable');
  await cdp.evaluate("document.getElementById('hmu-beauty-mask').click()");
  check(await cdp.evaluate("document.getElementById('hmu-beauty-mask').getAttribute('aria-pressed')==='true'&&document.getElementById('hmu-beauty-split').getAttribute('aria-pressed')==='false'"),'Mask view replaces split inspection');
  await cdp.evaluate("document.getElementById('hmu-beauty-mask').click();document.querySelector('[data-color=cssgram-moon]').click();document.querySelector('[data-face=blush]').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.face==='blush'&&!document.getElementById('hmu-filter-shoot').disabled"),'beauty and stickers');
  await cdp.evaluate(`(async()=>{
    const {Engine}=await import('./resources/js/beauty/engine.mjs?v=1'),map=Engine.prototype.mapFaces;
    Engine.prototype.mapFaces=function(...args){
      if(!hmuFilterStage.active&&!hmuFilterStage.countingDown&&args[0].length)window.beautyStillMappedFaces=this.meshes.length;
      return map.apply(this,args);
    };
    const canvas=document.getElementById('hmu-beauty-surface'),encode=canvas.toBlob;
    canvas.toBlob=function(callback,...args){
      encode.call(this,blob=>setTimeout(()=>{
        window.beautyVisibilityDuringEncode=true;
        document.dispatchEvent(new Event('visibilitychange'));
        callback(blob);
      },30),...args);
    };
  })()`);
  const before=(await logs()).length;
  await cdp.evaluate("document.getElementById('hmu-filter-shoot').click();document.getElementById('hmu-filter-shoot').click()");
  await waitFor(()=>cdp.evaluate("!photoBooth.takingPic&&!!document.getElementById('result').getAttribute('data-img')"),'full beautifier capture',45000);
  const requests=(await logs()).slice(before),exported=requests.find(r=>r.route==='api/applyEffects.php');
  check(requests.filter(r=>r.route==='api/takePic.php').length===1,'Full beautifier does not double capture');
  check(exported.beautyImageBytes>1000&&exported.beautyImageBytes<33554432&&!exported.beautyEffects,'Capture sends one bounded GPU image without applying legacy beauty twice');
  check(JSON.parse(exported.faceEffects)[exported.file].length>0,'Warped still keeps the selected face sticker');
  check(await cdp.evaluate("beautyVisibilityDuringEncode&&beautyStillMappedFaces>0"),'Changing tab during encoding preserves still-warped sticker anchors');
  check((await fs.stat(fixture+'/images/'+exported.file)).size>0,'GPU result reaches the real saved JPG');
  await verifyExport(exported,'beautifier-doll');
  await cdp.evaluate("document.querySelector('.newpic').click()");
  await waitFor(()=>cdp.evaluate("hmuFilterStage.active&&!document.getElementById('hmu-filter-shoot').disabled"),'next session');
  check(await cdp.evaluate("!hmuFilterStage.hasEffects&&document.querySelector('[data-beauty=slim]').value==='0'"),'All geometry resets for the next visitor');
  await cdp.evaluate("document.querySelector('[data-beauty-preset=natural]').click()");
  await waitFor(()=>cdp.evaluate("!document.querySelector('[data-beauty=smooth]').disabled&&!document.getElementById('hmu-beauty-surface').hidden"),'renderer reuse');
  await cdp.evaluate("document.getElementById('hmu-beauty-reset').click()");
  await waitFor(()=>cdp.evaluate("!document.querySelector('[data-beauty=smooth]').disabled"),'reset ready');
  check(await cdp.evaluate("document.querySelector('[data-beauty=smooth]').value==='45'&&document.querySelector('[data-beauty=texture]').value==='30'"),'Reset restores upstream slider defaults');
  check(await cdp.evaluate("document.getElementById('hmu-beauty-title').textContent==='Làm đẹp & Chỉnh ảnh'&&document.getElementById('hmu-beauty-split').textContent==='Chia đôi'&&document.getElementById('hmu-beauty-reset').textContent==='Đặt lại'"),'Vietnamese beauty headings and tools preserve UTF-8 text');
  await waitFor(()=>cdp.evaluate("getComputedStyle(document.getElementById('blocker')).display==='none'&&getComputedStyle(document.getElementById('aperture')).display==='none'"),'shutter settles');
  for(const [width,height] of [[375,812],[1440,1000]]) {
    await cdp.send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<900});
    await cdp.evaluate("document.querySelector('.hmu-beauty-group').scrollIntoView({block:'center'})");
    await cdp.evaluate("new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))");
    check(await cdp.evaluate("document.getElementById('hmu-filter-stage').scrollWidth<=innerWidth"),'Full controls fit '+width+'px');
    const shot=await cdp.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
    await fs.mkdir(root+'/reports',{recursive:true});await fs.writeFile(root+`/reports/beautifier-${width}.png`,Buffer.from(shot.data,'base64'));
  }
  await cdp.evaluate("document.getElementById('hmu-beauty-surface').getContext('webgl2').getExtension('WEBGL_lose_context').loseContext()");
  await waitFor(()=>cdp.evaluate("document.querySelector('[data-beauty=smooth]').disabled&&!document.getElementById('hmu-filter-shoot').disabled"),'GPU loss fallback');
  check(await cdp.evaluate("!hmuFilterStage.hasEffects&&!document.querySelector('[data-face=blush]').disabled"),'GPU failure preserves camera and legacy face effects');
  console.log('Full beauty pixel evidence:',JSON.stringify(gpu),'upload bytes:',exported.beautyImageBytes);
}
