'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
assert.ok(fs.existsSync('resources/js/beauty-settings.js'), 'Full reference beauty controls must exist');
const B = require('../resources/js/beauty-settings.js');
const Session = require('../resources/js/filter-session.js');
assert.equal(B.allControls().length,21);
assert.equal(B.PRESETS.length,6);
assert.equal(B.SWATCHES.lip.length,6);assert.equal(B.SWATCHES.blush.length,4);
const off=B.preset('off');assert.ok(!B.isActive(off));
assert.equal(off.values.texture,100);assert.equal(off.values.radius,50);
const natural=B.preset('natural');assert.equal(natural.values.smooth,38);assert.equal(natural.values.bright,8);
const doll=B.preset('doll');assert.equal(doll.values.slim,58);assert.equal(doll.values.eyes,46);
for(const c of B.allControls()) {
  const v=B.preset('off');v.values[c.key]=c.max;assert.doesNotThrow(()=>B.validate(v));
  v.values[c.key]=c.min;assert.doesNotThrow(()=>B.validate(v));
  v.values[c.key]=c.max+1;assert.throws(()=>B.validate(v));
}
const bad=B.preset('off');bad.colors.lip='javascript:bad';assert.throws(()=>B.validate(bad));
const session=new Session();session.request('photo');session.selectBeauty(doll);
session.select('glasses');session.confirm();session.selectBeauty(off);
session.request('photo');session.request('photo',true);
assert.equal(session.beauty.values.slim,58,'Confirmed geometry persists through retry');
assert.equal(session.face,'glasses','Beauty session keeps its selected sticker through retry');
assert.ok(Object.isFrozen(session.beauty.values),'Confirmed values cannot be mutated through a retained reference');
session.finish();session.request('photo');assert.ok(!B.isActive(session.beauty));
console.log('21 beauty controls, presets, validation and capture lifecycle passed');
