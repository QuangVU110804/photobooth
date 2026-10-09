"use strict";

/* globals photoBooth MarvinColorModelConverter AlphaBoundary MarvinImage Seriously rotaryController photoboothTools */
/* exported setBackgroundImage setMainImage */
var mainImage;
var mainImageWidth;
var mainImageHeight;
var backgroundImage;
var seriously;
var target;
var chroma;
var seriouslyimage;
var needsReload = false;
var notificationTimeout = config.ui.notification_timeout * 1000;
function greenToTransparency(imageIn, imageOut) {
  for (var y = 0; y < imageIn.getHeight(); y++) {
    for (var x = 0; x < imageIn.getWidth(); x++) {
      var color = imageIn.getIntColor(x, y);
      var hsv = MarvinColorModelConverter.rgbToHsv([color]);
      if (hsv[0] >= 60 && hsv[0] <= 200 && hsv[1] >= 0.2 && hsv[2] >= 0.2) {
        imageOut.setIntColor(x, y, 0, 127, 127, 127);
      } else {
        imageOut.setIntColor(x, y, color);
      }
    }
  }
}
function reduceGreen(image) {
  for (var y = 0; y < image.getHeight(); y++) {
    for (var x = 0; x < image.getWidth(); x++) {
      var r = image.getIntComponent0(x, y);
      var g = image.getIntComponent1(x, y);
      var b = image.getIntComponent2(x, y);
      var color = image.getIntColor(x, y);
      var hsv = MarvinColorModelConverter.rgbToHsv([color]);
      if (hsv[0] >= 60 && hsv[0] <= 130 && hsv[1] >= 0.15 && hsv[2] >= 0.15) {
        if (r * b != 0 && g * g / (r * b) > 1.5) {
          image.setIntColor(x, y, 255, r * 1.4, g, b * 1.4);
        } else {
          image.setIntColor(x, y, 255, r * 1.2, g, b * 1.2);
        }
      }
    }
  }
}
function alphaBoundary(imageOut, radius) {
  var ab = new AlphaBoundary();
  for (var y = 0; y < imageOut.getHeight(); y++) {
    for (var x = 0; x < imageOut.getWidth(); x++) {
      ab.alphaRadius(imageOut, x, y, radius);
    }
  }
}

// eslint-disable-next-line no-unused-vars
function setMainImage(imgSrc) {
  photoboothTools.console.logDev('[LIVECHROMA] Keying variant: ' + config.keying.variant);
  if (config.keying.variant === 'marvinj') {
    var image = new MarvinImage();
    image.load(imgSrc, function () {
      mainImageWidth = image.getWidth();
      mainImageHeight = image.getHeight();
      var imageOut = new MarvinImage(image.getWidth(), image.getHeight());

      //1. Convert green to transparency
      greenToTransparency(image, imageOut);

      // 2. Reduce remaining green pixels
      reduceGreen(imageOut);

      // 3. Apply alpha to the boundary
      alphaBoundary(imageOut, 6);
      var tmpCanvas = document.createElement('canvas');
      tmpCanvas.width = mainImageWidth;
      tmpCanvas.height = mainImageHeight;
      imageOut.draw(tmpCanvas);
      mainImage = new Image();
      mainImage.src = tmpCanvas.toDataURL('image/png');
      mainImage.onload = function () {
        drawCanvas();
      };
    });
  } else {
    var _image = new Image();
    _image.src = imgSrc;
    _image.onload = function () {
      mainImageWidth = _image.width;
      mainImageHeight = _image.height;

      // create tmpcanvas and size it to image size
      var tmpCanvas = document.createElement('canvas');
      tmpCanvas.width = mainImageWidth;
      tmpCanvas.height = mainImageHeight;
      tmpCanvas.id = 'tmpimageout';

      // append Canvas for Seriously to chromakey the image
      // eslint-disable-next-line no-unused-vars
      var body = document.getElementsByTagName('body')[0];
      document.body.appendChild(tmpCanvas);
      seriously = new Seriously();
      target = seriously.target('#tmpimageout');
      seriouslyimage = seriously.source(_image);
      chroma = seriously.effect('chroma');
      chroma.source = seriouslyimage;
      target.source = chroma;
      var color = config.keying.seriouslyjs_color;
      var r = parseInt(color.substr(1, 2), 16) / 255;
      var g = parseInt(color.substr(3, 2), 16) / 255;
      var b = parseInt(color.substr(5, 2), 16) / 255;
      photoboothTools.console.logDev('Chromakeying color:', color);
      photoboothTools.console.logDev('Red:', r, 'Green:', g, 'Blue:', b);
      chroma.screen = [r, g, b, 1];
      seriously.go();
      mainImage = new Image();
      mainImage.src = tmpCanvas.toDataURL('image/png');
      mainImage.onload = function () {
        drawCanvas();
      };
    };
  }
}

// eslint-disable-next-line no-unused-vars
function setBackgroundImage(url) {
  backgroundImage = new Image();
  backgroundImage.src = url;
  backgroundImage.onload = function () {
    drawCanvas();
  };
}
function drawCanvas() {
  var canvas = document.getElementById('mainCanvas');
  if (typeof mainImage !== 'undefined' && mainImage !== null) {
    canvas.width = mainImage.width;
    canvas.height = mainImage.height;
  } else if (typeof backgroundImage !== 'undefined' && backgroundImage !== null) {
    canvas.width = backgroundImage.width;
    canvas.height = backgroundImage.height;
  }
  var ctx = canvas.getContext ? canvas.getContext('2d') : null;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (typeof backgroundImage !== 'undefined' && backgroundImage !== null) {
    if (typeof mainImage !== 'undefined' && mainImage !== null) {
      var size = calculateAspectRatioFit(backgroundImage.width, backgroundImage.height, mainImage.width, mainImage.height);
      ctx.drawImage(backgroundImage, 0, 0, size.width, size.height);
    } else {
      ctx.drawImage(backgroundImage, 0, 0, backgroundImage.width, backgroundImage.height);
    }
  }
  if (typeof mainImage !== 'undefined' && mainImage !== null) {
    if (config.keying.variant === 'marvinj') {
      ctx.drawImage(mainImage, 0, 0);
    } else {
      //important to fetch tmpimageout
      ctx.drawImage(document.getElementById('tmpimageout'), 0, 0);
    }
    saveImage();
  }
}
function calculateAspectRatioFit(srcWidth, srcHeight, maxWidth, maxHeight) {
  var ratio = Math.max(maxWidth / srcWidth, maxHeight / srcHeight);
  return {
    width: srcWidth * ratio,
    height: srcHeight * ratio
  };
}
function saveImage(cb) {
  var canvas = document.getElementById('mainCanvas');
  var dataURL = canvas.toDataURL('image/png');
  $.post('api/chromakeying/save.php', {
    imgData: dataURL
  }, function (data) {
    photoBooth.takingPic = false;
    needsReload = true;
    if ($('.chroma-control-bar').is(':hidden')) {
      $('.chroma-control-bar').show();
      $('.takeChroma').hide();
    }
    if (config.picture.allow_delete) {
      $('.deletebtn').css('visibility', 'visible');
      $('.chroma-control-bar').find('.deletebtn').off('click').on('click', function (ev) {
        ev.preventDefault();
        var msg = photoboothTools.getTranslation('really_delete_image');
        var really = config["delete"].no_request ? true : confirm(data.filename + ' ' + msg);
        if (really) {
          photoBooth.deleteImage(data.filename, function (result) {
            if (result.success && config.live_keying.show_all) {
              photoBooth.deleteImage(photoBooth.chromaimage, function () {
                setTimeout(function () {
                  photoboothTools.reloadPage();
                }, notificationTimeout);
              });
            } else {
              setTimeout(function () {
                photoboothTools.reloadPage();
              }, notificationTimeout);
            }
          });
        } else {
          $('.deletebtn').blur();
        }
      });
    }
    if (data.filename) {
      // Add Image to gallery and slider
      photoBooth.addImage(data.filename);
    }
    if (cb) {
      cb(data);
    }
  });
}
$('.backgroundPreview').on('click', function () {
  $('.canvasWrapper').removeClass('initial');
  if ($('.chroma-control-bar').is(':hidden')) {
    $('.chroma-control-bar').show();
    $('.chromaNote').empty();
    $('.chromaNote').hide();
  }
  $('.backgrounds').addClass('shrinked');
});

// Take Chroma Button
$('.takeChroma, .newchroma').on('click', function (e) {
  e.preventDefault();
  if (photoBooth.takingPic) {
    photoboothTools.console.logDev('Taking picture in progress already!');
    return;
  }
  var chromaInfo = photoboothTools.getTranslation('chromaInfoAfter');
  photoBooth.thrill('chroma');
  if ($('.chroma-control-bar').is(':visible')) {
    $('.chroma-control-bar').hide();
    $('.backgrounds').hide();
    setTimeout(function () {
      $('.chromaNote').show();
      $('.chromaNote').text(chromaInfo);
      $('.chroma-control-bar > .takeChroma').hide();
      $('.chroma-control-bar > .deleteBtn').hide();
      $('.chroma-control-bar > .reloadPage').show();
      $('.chroma-control-bar').show();
    }, config.picture.cntdwn_time * 1000);
  }
});
$(document).on('keyup', function (ev) {
  if (config.picture.key && parseInt(config.picture.key, 10) === ev.keyCode || config.collage.key && parseInt(config.collage.key, 10) === ev.keyCode) {
    if (!backgroundImage) {
      photoboothTools.modalMesg.showError('#modal_mesg', photoboothTools.getTranslation('chroma_needs_background'));
      setTimeout(function () {
        photoboothTools.modalMesg.reset('#modal_mesg');
      }, 1000);
      photoboothTools.console.logDev('Please choose a background first!');
    } else if (needsReload) {
      photoboothTools.modalMesg.showError('#modal_mesg', photoboothTools.getTranslation('chroma_needs_reload'));
      setTimeout(function () {
        photoboothTools.modalMesg.reset('#modal_mesg');
      }, 1000);
      photoboothTools.console.logDev('Please reload the page to take a new Picture!');
    } else if (!photoBooth.takingPic) {
      if (config.collage.key && parseInt(config.collage.key, 10) === ev.keyCode) {
        photoboothTools.console.logDev('Collage key pressed. Not possible on live chroma, triggering photo now.');
      }
      photoBooth.thrill('chroma');
    } else if (config.dev.loglevel > 0 && photoBooth.takingPic) {
      photoboothTools.console.log('Taking photo already in progress!');
    }
  }
});
$('.reloadPage').on('click', function (e) {
  e.preventDefault();
  e.stopPropagation();
  photoboothTools.reloadPage();
});

// Open Gallery Button
$('.livechroma-gallery-btn').on('click', function (e) {
  e.preventDefault();
  photoBooth.openGallery($(this));
});

// Close Button
$('.livechroma-close-btn').on('click', function () {
  location.assign('./index.php');
});
$(document).ready(function () {
  photoboothTools.console.log('[LIVECHROMA] DOM ready');
  rotaryController.focusSet('#start');
});