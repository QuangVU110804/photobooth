"use strict";

/* globals photoboothTools */
$(function () {
  $('.download-zip-btn').on('click', function (e) {
    e.preventDefault();
    photoboothTools.modal.open('#save_mesg');
    var data = {
      type: 'zip'
    };
    $.ajax({
      url: '../api/diskusage.php',
      data: data,
      dataType: 'json',
      type: 'post',
      success: function success(response) {
        photoboothTools.console.log('data', response);
        setTimeout(function () {
          if (response.success === 'zip') {
            $.ajax({
              url: '../' + config.folders.archives + '/' + response.file,
              type: 'HEAD',
              error: function error() {
                photoboothTools.console.log('ZIP does not exist!');
              },
              success: function success() {
                location.href = '../' + config.folders.archives + '/' + response.file;
              }
            });
          }
          photoboothTools.modal.close('#save_mesg');
          $('.download-zip-btn').blur();
        }, 10000);
      },
      error: function error(jqXHR, textStatus) {
        photoboothTools.console.log('Error while downloading: ', textStatus);
        photoboothTools.modal.close('#save_mesg');
      }
    });
  });
});