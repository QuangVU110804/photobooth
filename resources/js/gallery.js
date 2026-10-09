"use strict";

/* globals photoBooth photoboothTools */
$(function () {
  var reloadElement = $('<a class="btn btn--' + config.ui.button + ' gallery__reload rotaryfocus">');
  reloadElement.append('<i class="' + config.icons.refresh + '"></i>');
  reloadElement.attr('href', '#');
  reloadElement.attr('aria-label', 'Tải lại thư viện');
  reloadElement.attr('title', 'Tải lại thư viện');
  reloadElement.on('click', function () {
    return photoboothTools.reloadPage();
  });
  reloadElement.appendTo('.gallery__header');
  $('.gallery__close').hide();
  photoBooth.openGallery();
});
