'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../resources/js/gallery_updatecheck.js'), 'utf8');
let checks = 0;
function check(condition, label) { assert.ok(condition, label); checks++; }
function load(seconds = 1, initialSize = null) {
  const requests = [];
  const state = { busy: false, reloads: 0, ticks: null, period: null };
  const context = {
    config: { gallery: { db_check_time: seconds } },
    document: { hidden: false, body: { getAttribute: () => initialSize } },
    photoBooth: { isTimeOutPending: () => state.busy },
    photoboothTools: { console: { log() {}, logDev() {} }, reloadPage() { state.reloads++; } },
    $: { getJSON(options) { requests.push(options); } },
    setInterval(callback, period) { state.ticks = callback; state.period = period; }
  };
  vm.createContext(context);
  vm.runInContext(source, context);
  return { context, requests, state };
}
// Real polling script, controlled network boundary: a slow response must not
// queue more work, and a failure must permit the next scheduled request.
const { context, requests, state } = load();
state.ticks(); state.ticks(); state.ticks();
check(requests.length === 1, 'Slow gallery response permits only one pending request');
requests[0].success({ dbsize: 12 }); requests[0].complete();
check(state.reloads === 0, 'First successful status establishes the baseline');
context.document.hidden = true; state.ticks();
check(requests.length === 1, 'Hidden tabs do not poll');
context.document.hidden = false; state.busy = true; state.ticks();
check(requests.length === 1, 'Active photo interaction defers polling');
state.busy = false; state.ticks();
check(requests.length === 2, 'Polling resumes when the tab is visible and idle');
requests[1].complete(); state.ticks();
check(requests.length === 3, 'A failed request releases the pending slot');
check(Number.isFinite(requests[2].timeout) && requests[2].timeout > 0, 'Network timeout prevents a permanently stalled poll');
requests[2].success({ dbsize: 15 }); requests[2].complete();
check(state.reloads === 1, 'Changed database still refreshes the gallery');
state.ticks(); state.busy = true;
requests[3].success({ dbsize: 18 }); requests[3].complete();
check(state.reloads === 1, 'Response arriving during capture cannot interrupt the photo flow');
state.busy = false; state.ticks();
requests[4].success({ dbsize: 18 }); requests[4].complete();
check(state.reloads === 2, 'Deferred change remains visible to the next idle poll');
state.ticks(); requests[5].success({ error: 'unavailable' }); requests[5].complete();
check(state.reloads === 2, 'Malformed status does not cause a spurious reload');
state.ticks(); context.document.hidden = true;
requests[6].success({ dbsize: 21 }); requests[6].complete();
check(state.reloads === 2, 'A late response cannot reload a hidden tab');
context.document.hidden = false; state.ticks();
requests[7].success({ dbsize: 21 }); requests[7].complete();
check(state.reloads === 3, 'Returning to the tab detects changes deferred while hidden');
for (const value of [0, -1, 'invalid', null]) {
  check(load(value).state.period >= 1000, 'Invalid or zero interval cannot create a request storm');
}
check(load(5).state.period === 5000, 'Configured polling interval is preserved');
const selecting = load(1, '10');
selecting.context.window = { hmuGallerySelection: { active: true } };
selecting.state.ticks();
check(selecting.requests.length === 0, 'Selecting photos defers polling so selection cannot be lost');
selecting.context.window.hmuGallerySelection.active = false;
selecting.state.ticks();
selecting.context.window.hmuGallerySelection.active = true;
selecting.requests[0].success({dbsize: 20}); selecting.requests[0].complete();
check(selecting.state.reloads === 0, 'In-flight status response cannot reload during photo selection');
selecting.context.window.hmuGallerySelection.active = false;
selecting.state.ticks();
selecting.requests[1].success({dbsize:20}); selecting.requests[1].complete();
check(selecting.state.reloads === 1, 'Polling resumes after selection and still detects deferred changes');
const earlyHidden = load(1, '100');
earlyHidden.context.document.hidden = true; earlyHidden.state.ticks();
earlyHidden.context.document.hidden = false; earlyHidden.state.ticks();
earlyHidden.requests[0].success({ dbsize: 200 }); earlyHidden.requests[0].complete();
check(earlyHidden.state.reloads === 1, 'Changes while hidden before the first poll are compared with the rendered gallery');
console.log(`${checks} gallery polling checks passed`);
