"use strict";

/* globals Translator */
var translator = new Translator({
  persist: false,
  defaultLanguage: 'en',
  detectLanguage: false,
  registerGlobally: 'i18n',
  filesLocation: config.ui.folders_lang,
  debug: config.dev.loglevel > 0
});
translator.fetch('en').then(function () {
  translator.translatePageTo();
});
if (config.ui.language !== 'en') {
  translator.fetch(config.ui.language).then(function () {
    translator.translatePageTo(config.ui.language);
  });
}