'use strict';
const assert=require('node:assert/strict');
const beauty=require('../resources/js/skin-beauty.js');
const indices=beauty.indices([33,263,93,323,10]);
assert.deepEqual(indices.slice(0,5),[33,263,93,323,10]);
assert.equal(indices.length,478);assert.equal(new Set(indices).size,478);
console.log('Full mesh preserves legacy/sticker anchor order');
