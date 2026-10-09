"use strict";

/* globals i18n remoteBuzzerClient */
var photoboothTools = function () {
  // vars
  var notificationTimeout = config.ui.notification_timeout * 1000,
    api = {};
  api.isPrinting = false;
  api.console = {
    log: function log() {
      for (var _len = arguments.length, content = new Array(_len), _key = 0; _key < _len; _key++) {
        content[_key] = arguments[_key];
      }
      console.log('[', new Date().toISOString(), ']: ' + content);
    },
    logDev: function logDev() {
      if (config.dev.loglevel > 0) {
        for (var _len2 = arguments.length, content = new Array(_len2), _key2 = 0; _key2 < _len2; _key2++) {
          content[_key2] = arguments[_key2];
        }
        console.log('[', new Date().toISOString(), ']: ' + content);
      }
    }
  };
  api.getTranslation = function (key) {
    var translation = i18n(key, config.ui.language);
    var fallbackTranslation = i18n(key, 'en');
    if (translation) {
      return translation;
    } else if (fallbackTranslation) {
      return fallbackTranslation;
    }
    return key;
  };
  api.modal = {
    open: function open(selector) {
      $(selector).addClass('modal--show');
    },
    close: function close(selector) {
      if ($(selector).hasClass('modal--show')) {
        $(selector).removeClass('modal--show');
        return true;
      }
      return false;
    },
    toggle: function toggle(selector) {
      $(selector).toggleClass('modal--show');
    },
    empty: function empty(selector) {
      api.modal.close(selector);
      $(selector).find('.modal__body').empty();
    }
  };
  api.modalMesg = {
    showSuccess: function showSuccess(selector, successMsg) {
      $(selector).empty();
      $(selector).html('<div class="modal__body"><span style="color:green;">' + successMsg + '</span></div>');
      api.modal.open($(selector));
    },
    showError: function showError(selector, errorMsg) {
      $(selector).empty();
      $(selector).html('<div class="modal__body"><span style="color:red;">' + errorMsg + '</span></div>');
      api.modal.open($(selector));
    },
    reset: function reset(selector) {
      api.modal.close($(selector));
      $(selector).empty();
    }
  };
  api.reloadPage = function () {
    window.location.reload();
  };
  api.getRequest = function (url) {
    var request = new XMLHttpRequest();
    api.console.log('Sending GET request to: ' + url);
    request.onload = function () {
      if (request.status === 200) {
        // parse JSON data
        var responseData = request.responseText;
        api.console.log(responseData);
      } else if (request.status === 404) {
        api.console.log('No records found');
      } else {
        api.console.log('Unhandled request status: ' + request.status);
      }
    };
    request.onerror = function () {
      api.console.log('Network error occurred');
    };
    request.open('GET', url);
    request.send();
  };
  api.resetPrintErrorMessage = function (cb, to) {
    setTimeout(function () {
      api.modalMesg.reset('#modal_mesg');
      cb();
      api.isPrinting = false;
    }, to);
  };
  api.printImage = function (imageSrc, cb) {
    if (api.isPrinting) {
      api.console.log('Printing in progress: ' + api.isPrinting);
    } else {
      api.modal.open('#print_mesg');
      api.isPrinting = true;
      if (typeof remoteBuzzerClient !== 'undefined') {
        remoteBuzzerClient.inProgress('print');
      }
      $.ajax({
        method: 'GET',
        url: 'api/print.php',
        data: {
          filename: imageSrc
        },
        success: function success(data) {
          api.console.log('Picture processed: ', data);
          if (data.error) {
            api.console.log('ERROR: An error occurred: ', data.error);
            api.modal.close('#print_mesg');
            api.modalMesg.showError('#modal_mesg', data.error);
            api.resetPrintErrorMessage(cb, config.print.time);
          } else {
            setTimeout(function () {
              api.modal.close('#print_mesg');
              cb();
              api.isPrinting = false;
            }, config.print.time);
          }
        },
        error: function error(jqXHR, textStatus) {
          api.console.log('ERROR: An error occurred: ', textStatus);
          api.modal.close('#print_mesg');
          api.modalMesg.showError('#modal_mesg', api.getTranslation('error'));
          api.resetPrintErrorMessage(cb, notificationTimeout);
        }
      });
    }
  };
  return api;
}();

// Init on domready
$(function () {
  photoboothTools.console.log('Loglevel: ' + config.dev.loglevel);
});