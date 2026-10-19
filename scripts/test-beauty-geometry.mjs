import assert from 'node:assert/strict';
import {forwardPoint} from '../resources/js/beauty/engine.mjs';
const warp={cx:.5,cy:.5,r:.2,mode:0,dx:.04,dy:0};
// The destination center must sample the source center minus displacement.
const p=forwardPoint({x:.46,y:.5},[warp],1);
assert.ok(Math.abs(p.x-.5)<1e-6&&Math.abs(p.y-.5)<1e-6);
assert.deepEqual(forwardPoint({x:.9,y:.9},[warp],1),{x:.9,y:.9});
const warps=[warp,{cx:.5,cy:.5,r:.15,mode:1,k:.2}];
for(const original of [{x:.46,y:.5},{x:.52,y:.55},{x:.3,y:.4}]) {
  const mapped=forwardPoint(original,warps,1);let q=[mapped.x,mapped.y];
  for(const h of warps){const t=Math.min(1,Math.hypot(q[0]-h.cx,q[1]-h.cy)/h.r),fall=1-t*t*(3-2*t);q=h.mode===0?[q[0]-h.dx*fall,q[1]-h.dy*fall]:[h.cx+(q[0]-h.cx)*(1-h.k*fall),h.cy+(q[1]-h.cy)*(1-h.k*fall)];}
  assert.ok(Math.hypot(q[0]-original.x,q[1]-original.y)<1e-6,'Forward sticker anchors invert the composed shader warp');
}
console.log('Sticker forward mapping matches the reference backward warp');
