'use strict';
// Breaks caught: unsafe catalog acceptance, wrong face-axis/origin geometry,
// duplicated decoding and bitmap leaks after eviction/cancelled selection.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const modulePath = path.join(__dirname, '../resources/js/face-stickers.js');
assert.ok(fs.existsSync(modulePath), 'PNG sticker catalog/geometry module must exist');
const S = require(modulePath);
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; };
const near = (actual, wanted, label) => check(Math.abs(actual - wanted) < 1e-9, label);
const raw = () => ({version:1,effects:[{id:'test-crown',title:'Test crown',parts:[{file:'crown.png',anchor:'eyes',width:1}]}]});
const effect = S.validateCatalog(raw())[0];
check(effect.parts[0].opacity === 1 && effect.parts[0].origin[0] === .5, 'Missing options normalize to safe defaults');
const unicode=raw();unicode.effects[0].title='👑'.repeat(80);
check(S.validateCatalog(unicode)[0].title===unicode.effects[0].title,'Unicode title limit agrees with PHP character count');
for (const mutate of [
  c=>c.version=2, c=>c.effects.push(c.effects[0]), c=>c.effects[0].id='glasses',
  c=>c.effects[0].parts[0].file='../crown.png', c=>c.effects[0].parts[0].file='https://a/x.png',
  c=>c.effects[0].parts[0].file='x.jpg', c=>c.effects[0].parts[0].anchor='unknown',
  c=>c.effects[0].parts[0].width=0, c=>c.effects[0].parts[0].width=Infinity,
  c=>c.effects[0].parts[0].origin=[1.1,0], c=>c.effects[0].parts[0].offset=[0,3],
  c=>c.effects[0].parts[0].opacity=-.1, c=>c.effects[0].parts=Array(5).fill(c.effects[0].parts[0]),
  c=>c.effects=Array.from({length:33},(_,i)=>({...c.effects[0],id:'effect'+i})),
  c=>c.effects[0].parts[0].extra=true, c=>c.effects[0].title='', c=>c.effects[0].id='__proto__'
]) { const c=raw(); mutate(c); assert.throws(()=>S.validateCatalog(c), /catalog|effect|part/i); checks++; }
const images = new Map([['crown.png',{width:100,height:50}]]);
const anchors = [[{x:.2,y:.4},{x:.6,y:.4},{x:.1,y:.5},{x:.7,y:.5}]];
let p = S.placements(anchors,effect,1000,500,images)[0];
check(p.type==='sticker'&&p.effect==='test-crown'&&p.part===0,'Tagged payload identifies catalog component');
near(p.center[0],.4,'Eyes midpoint X'); near(p.center[1],.4,'Eyes midpoint Y');
near(p.width,.4,'Width relative to raw image'); near(p.angle,0,'Horizontal angle');
let customized = {...effect,parts:[{...effect.parts[0],origin:[0,0],offset:[.1,-.2]}]};
p=S.placements(anchors,customized,1000,500,images)[0];
near(p.center[0],.64,'Origin and horizontal offset shift center');
near(p.center[1],.44,'Texture aspect ratio determines vertical origin');
const vertical=[[{x:.5,y:.2},{x:.5,y:.6},{x:.1,y:.5},{x:.7,y:.5}]];
p=S.placements(vertical,effect,1000,500,images)[0];
near(p.width,.2,'Non-square image uses pixel distance'); near(p.angle,Math.PI/2,'Clockwise angle');
const upward=[[{x:.5,y:.6},{x:.5,y:.2},{x:.1,y:.5},{x:.7,y:.5}]];
near(S.placements(upward,effect,1000,500,images)[0].angle,-Math.PI/2,'Counterclockwise angle');
check(S.placements([],effect,1000,500,images).length===0,'No faces means no sticker');
check(S.placements(Array(4).fill(anchors[0]),effect,1000,500,images).length===4,'All four faces get sticker');
const double={...effect,parts:[effect.parts[0],{...effect.parts[0],anchor:'left-cheek'}]};
const multiple=S.placements(Array(4).fill(anchors[0]),double,1000,500,images);
check(multiple.length===8&&multiple[0].part===0&&multiple[1].part===1&&multiple[6].part===0,'Multi-part ordering preserved on all four faces');
check(S.placements([[anchors[0][0],anchors[0][0],...anchors[0].slice(2)]],effect,1000,500,images).length===0,'Degenerate eye distance skips face');
check(S.indicesFor(effect).join(',')==='33,263,93,323','Legacy four anchors stay first');
const forehead={...effect,parts:[{...effect.parts[0],anchor:'forehead'}]};
check(S.indicesFor(forehead).join(',')==='33,263,93,323,10','Only required extra landmark crosses worker');
const presets={'left-eye':0,'right-eye':1,'left-cheek':2,'right-cheek':3};
for(const [anchor,index] of Object.entries(presets)) {
  const placed=S.placements(anchors,{...effect,parts:[{...effect.parts[0],anchor}]},1000,500,images)[0];
  near(placed.center[0],anchors[0][index].x,anchor+' X');near(placed.center[1],anchors[0][index].y,anchor+' Y');
}
const nose={...effect,parts:[{...effect.parts[0],anchor:'nose'}]};
p=S.placements([[...anchors[0],{x:.43,y:.55}]],nose,1000,500,images)[0];near(p.center[0],.43,'Extra nose landmark used');
for(const anchor of ['mouth','chin','face']) check(S.indicesFor({...effect,parts:[{...effect.parts[0],anchor}]}).length>4,anchor+' supplies required landmarks');

(async()=>{
  let loads=0,closed=0;
  const cache=new S.AssetCache(async()=>{loads++;return {width:100,height:50,close(){closed++;}};});
  const [a,b]=await Promise.all([cache.prepare(effect),cache.prepare(effect)]);
  check(a===b&&loads===1,'Simultaneous preparation decodes each unique image once');
  await cache.prepare({...effect,id:'second'});await cache.prepare({...effect,id:'third'});
  check(closed===1,'Third effect evicts oldest effect bitmaps');
  cache.clear();check(closed===3,'Clear releases all cached bitmaps');
  let release;
  const slow=new S.AssetCache(()=>new Promise(resolve=>{release=resolve;}));
  const loading=slow.prepare(effect);slow.clear();release({width:100,height:50,close(){closed++;}});
  await assert.rejects(loading,/cancel/i);checks++;check(closed===4,'Late load after cancellation is closed');
  const oversized=new S.AssetCache(async()=>({width:2048,height:1,close(){closed++;}}));
  await assert.rejects(oversized.prepare(effect),/size|PNG/i);checks++;
  let attempts=0;
  const retry=new S.AssetCache(async()=>{if(!attempts++)throw new Error('offline');return {width:1,height:1,close(){}};});
  await assert.rejects(retry.prepare(effect),/offline/);await retry.prepare(effect);check(attempts===2,'Failed image can be retried');retry.clear();
  const shipped=JSON.parse(fs.readFileSync(path.join(__dirname,'../resources/face-effects/catalog.json'),'utf8'));
  const medicalCache=new S.AssetCache(async file=>{
    const png=fs.readFileSync(path.join(__dirname,'../resources/face-effects/stickers',file));
    return {width:png.readUInt32BE(16),height:png.readUInt32BE(20),close(){}};
  });
  for(const [id,width] of Object.entries({hat:1500,mask:1254})) {
    const medical=S.validateCatalog(shipped).find(e=>e.id===id);
    check(!!medical,'Medical sticker available alongside crown: '+id);
    const bitmaps=await medicalCache.prepare(medical);
    check(bitmaps.get(id+'.png').width===width,'Supplied medical PNG dimensions preserved: '+id);
  }
  medicalCache.clear();
  let active=0,peak=0,aborted=0;
  const cancellation=new S.AssetCache((file, options)=>new Promise((resolve,reject)=>{
    check(!!options?.signal,'Every asset load receives cancellation signal');active++;peak=Math.max(peak,active);
    const abort=()=>{active--;aborted++;reject(new Error('cancelled'));};
    options.signal.addEventListener('abort',abort,{once:true});
  }));
  const cancelled=[];
  for(let i=0;i<20;i++)cancelled.push(cancellation.prepare({...effect,id:'rapid-'+i,parts:[{...effect.parts[0],file:'a.png'},{...effect.parts[0],file:'b.png'}]}).catch(()=>{}));
  cancellation.clear();await Promise.all(cancelled);
  check(peak<=4&&active===0&&aborted>0,'Rapid switching bounds concurrent loads and aborts cancelled network work');
  check(S.validateCatalog(shipped).length>=1,'Shipped catalog contains an original usable sticker');
  console.log(`${checks} PNG sticker JavaScript checks passed`);
})().catch(error=>{console.error(error);process.exitCode=1;});
