(function (root) {
  'use strict';
  const Beauty = root.HmuBeautySettings || (typeof require === 'function' ? require('./beauty-settings.js') : null);
  class CaptureSession {
    constructor() {
      this.phase = 'idle';
      this.face = 'none';
      this.beauty = Beauty.preset('off');
      this.style = 'photo';
    }
    request(style, continuation) {
      if (style !== 'photo') return false;
      if (continuation && this.phase === 'capturing') return true;
      if (this.phase === 'confirmed') { this.phase = 'capturing'; return true; }
      if (this.phase === 'idle') {
        this.phase = 'selecting';
        this.style = style;
        this.face = 'none';
        this.beauty = Beauty.preset('off');
      }
      return false;
    }
    select(face) {
      if (this.phase !== 'selecting') return;
      this.face = face;
    }
    confirm() {
      if (this.phase !== 'selecting') throw new Error('Effect selection is not active');
      this.phase = 'confirmed';
    }
    selectBeauty(settings) {
      if (this.phase !== 'selecting') return;
      const next = Beauty.validate(settings);
      this.beauty = Object.freeze({ values: Object.freeze(next.values), colors: Object.freeze(next.colors), preset: next.preset });
    }
    finish() { this.phase = 'idle'; }
    cancel() { this.phase = 'idle'; this.face = 'none'; this.beauty = Beauty.preset('off'); }
  }
  if (typeof module === 'object' && module.exports) module.exports = CaptureSession;
  else root.HmuCaptureSession = CaptureSession;
})(typeof globalThis === 'object' ? globalThis : this);
