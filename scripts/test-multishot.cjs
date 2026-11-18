'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const moduleFile = path.resolve(__dirname, '../resources/js/multishot.js');
assert(fs.existsSync(moduleFile), 'Multishot should provide a sequential collector');
const collect = require(moduleFile);

(async () => {
  const events = [];
  let captureFailed = false;
  let processFailed = false;
  const result = await collect(4, {
    capture: async (slot, request) => {
      events.push(['capture', slot, request]);
      if (slot === 2 && !captureFailed) {
        captureFailed = true;
        throw new Error('Camera was disconnected');
      }
      return 'shot-' + slot;
    },
    process: async (slot, file) => {
      assert.equal(file, 'shot-' + slot);
      events.push(['process', slot]);
      if (slot === 2 && !processFailed) {
        processFailed = true;
        throw new Error('Upload interrupted');
      }
    },
    progress: (slot) => events.push(['progress', slot]),
    retry: async (_error, slot) => { events.push(['retry', slot]); return true; },
    requestId: (slot) => 'request-' + slot,
    complete: async () => { events.push(['complete']); return 'sheet.jpg'; },
  });
  assert.equal(result, 'sheet.jpg');
  assert.deepEqual(events, [
    ['capture', 0, 'request-0'], ['process', 0], ['progress', 0],
    ['capture', 1, 'request-1'], ['process', 1], ['progress', 1],
    ['capture', 2, 'request-2'], ['retry', 2], ['capture', 2, 'request-2'],
    ['process', 2], ['retry', 2], ['process', 2], ['progress', 2],
    ['capture', 3, 'request-3'], ['process', 3], ['progress', 3], ['complete'],
  ]);
  let published = false;
  const cancelled = await collect(2, {
    capture: async () => { throw new Error('Camera disconnected'); },
    retry: async () => false,
    requestId: () => 'request',
    complete: async () => { published = true; },
  });
  assert.equal(cancelled, null);
  assert.equal(published, false);
  console.log('Multishot sequencing, per-slot recovery and cancellation passed');
})().catch((error) => { console.error(error); process.exitCode = 1; });
