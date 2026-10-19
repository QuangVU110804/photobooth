'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const document = new EventTarget();
const window = new EventTarget();
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../resources/js/kiosk-zoom.js'), 'utf8'), { document, window });
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }
function dispatch(target, type, properties) {
  const event = new Event(type, { cancelable: true });
  Object.assign(event, properties);
  return target.dispatchEvent(event);
}
check(dispatch(document, 'wheel', {}), 'Normal wheel scrolling remains enabled');
check(!dispatch(document, 'wheel', { ctrlKey: true }), 'Ctrl wheel zoom is prevented');
check(!dispatch(document, 'keydown', { metaKey: true, key: '+' }), 'Keyboard zoom is prevented');
check(dispatch(window, 'pointerdown', { pointerType: 'touch', pointerId: 1 }), 'One touch pointer is allowed for swiping');
check(!dispatch(window, 'pointerdown', { pointerType: 'touch', pointerId: 2 }), 'Second touch pointer is prevented before reaching image viewer');
check(!dispatch(window, 'pointermove', { pointerType: 'touch', pointerId: 1 }), 'Pinch movement is blocked');
check(dispatch(window, 'pointerup', { pointerType: 'touch', pointerId: 1 }), 'Pointer release reaches viewer for cleanup');
check(!dispatch(window, 'pointermove', { pointerType: 'touch', pointerId: 2 }), 'Remaining pointer cannot drag until pinch ends');
dispatch(window, 'pointerup', { pointerType: 'touch', pointerId: 2 });
check(dispatch(window, 'pointerdown', { pointerType: 'touch', pointerId: 3 }), 'Single touch works again after pinch');
check(dispatch(window, 'pointermove', { pointerType: 'touch', pointerId: 3 }), 'Normal swiping resumes');
dispatch(window, 'pointercancel', { pointerType: 'touch', pointerId: 3 });
console.log(`${checks} kiosk zoom checks passed`);
