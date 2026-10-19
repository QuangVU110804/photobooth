"use strict";

/* globals photoBooth photoboothTools */
var photoboothPreview = function () {
  // vars
  var CameraDisplayMode = {
      INIT: 1,
      BACKGROUND: 2,
      COUNTDOWN: 3,
      TEST: 4
    },
    PreviewMode = {
      NONE: 'none',
      DEVICE: 'device_cam',
      URL: 'url'
    },
    webcamConstraints = {
      audio: false,
      video: {
        width: { ideal: Number(config.preview.videoWidth) || 1280 },
        height: { ideal: Number(config.preview.videoHeight) || 720 },
        facingMode: { ideal: config.preview.camera_mode || 'user' }
      }
    },
    api = {};
  var pid, video, loader, wrapper, url, pictureFrame;
  var mediaRequest = null, mediaGeneration = 0;
  try {
    var savedDevice = sessionStorage.getItem('hmu-camera-device');
    if (savedDevice) webcamConstraints.video.deviceId = { exact: savedDevice };
  } catch (error) { /* Optional persistence. */ }
  api.selectDevice = function (deviceId) {
    api.stopVideo();
    if (deviceId) webcamConstraints.video.deviceId = { exact: deviceId };
    else delete webcamConstraints.video.deviceId;
    try { sessionStorage.setItem('hmu-camera-device', deviceId || ''); } catch (error) { /* Optional persistence. */ }
    return api.initializeMedia(function () {}, 0, true);
  };
  api.listDevices = function () {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return Promise.resolve([]);
    return navigator.mediaDevices.enumerateDevices().then(function (devices) {
      return devices.filter(function (device) { return device.kind === 'videoinput'; });
    });
  };
  api.changeVideoMode = function (mode) {
    photoboothTools.console.logDev('Preview: Changing video mode: ' + mode);
    if (mode === CameraDisplayMode.BACKGROUND) {
      video.css('z-index', 0);
      wrapper.css('background-image', 'none');
      wrapper.css('background-color', 'transparent');
    } else {
      wrapper.css('background-color', config.colors.panel);
      loader.css('background-color', 'transparent');
      video.css('z-index', 99);
    }
    video.show();
  };
  api.initializeMedia = function () {
    var cb = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : function () {};
    var retry = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : 0;
    photoboothTools.console.logDev('Preview: Trying to initialize media...');
    var forFilterStage = arguments.length > 2 && arguments[2] === true;
    if (!navigator.mediaDevices || (!forFilterStage && (config.preview.mode === PreviewMode.NONE.valueOf() || config.preview.mode === PreviewMode.URL.valueOf()))) {
      photoboothTools.console.logDev('Preview: No preview from device cam or no webcam available!');
      return forFilterStage ? Promise.reject(new Error('Camera preview is unavailable')) : Promise.resolve(null);
    }
    var getMedia = navigator.mediaDevices.getUserMedia || navigator.mediaDevices.webkitGetUserMedia || navigator.mediaDevices.mozGetUserMedia || false;
    if (!getMedia) {
      photoboothTools.console.logDev('Preview: Could not get media!');
      return forFilterStage ? Promise.reject(new Error('Camera preview is unavailable')) : Promise.resolve(null);
    }
    if (api.stream && api.stream.getVideoTracks().some(function (track) { return track.readyState === 'live'; })) {
      cb();
      return Promise.resolve(api.stream);
    }
    if (mediaRequest) return mediaRequest.then(function (stream) { cb(); return stream; });
    var generation = mediaGeneration;
    mediaRequest = getMedia.call(navigator.mediaDevices, webcamConstraints).catch(function (error) {
      // A disconnected saved device or an unsupported size must not block all cameras.
      if (error.name !== 'OverconstrainedError' && error.name !== 'NotFoundError') throw error;
      if (generation !== mediaGeneration) throw new Error('Camera preview was cancelled');
      delete webcamConstraints.video.deviceId;
      return getMedia.call(navigator.mediaDevices, { audio: false, video: true });
    }).then(function (stream) {
      if (generation !== mediaGeneration) { stream.getTracks().forEach(function (track) { track.stop(); }); throw new Error('Camera preview was cancelled'); }
      photoboothTools.console.logDev('Preview: getMedia done!');
      api.stream = stream;
      video.get(0).srcObject = stream;
      return stream;
    });
    var request = mediaRequest;
    request.then(function () { if (mediaRequest === request) mediaRequest = null; }, function () { if (mediaRequest === request) mediaRequest = null; });
    return request.then(function (stream) { cb(); return stream; })["catch"](function (error) {
      photoboothTools.console.log('ERROR: Preview: Could not get user media: ', error);
      if (!forFilterStage && generation === mediaGeneration && retry < 3) {
        photoboothTools.console.logDev('Preview: Retrying to get user media. Retry ' + retry + ' / 3');
        retry += 1;
        return new Promise(function (resolve) { setTimeout(resolve, 1000); }).then(function () {
          if (generation !== mediaGeneration) return;
          return api.initializeMedia(cb, retry);
        });
      } else {
        photoboothTools.console.logDev('ERROR: Preview: Unable to get user media. Failed retries: ' + retry);
        if (forFilterStage) throw error;
      }
    });
  };
  api.getAndDisplayMedia = function (mode) {
    if (api.stream) {
      api.changeVideoMode(mode);
    } else {
      api.initializeMedia(function () {
        api.changeVideoMode(mode);
      });
    }
  };
  api.runCmd = function (mode) {
    var dataVideo = {
      play: mode,
      pid: pid
    };
    return jQuery.post('api/previewCamera.php', dataVideo).done(function (result) {
      photoboothTools.console.log('Preview: ' + dataVideo.play + ' webcam successfully.');
      pid = result.pid;
    })
    // eslint-disable-next-line no-unused-vars
    .fail(function (xhr, status, result) {
      photoboothTools.console.log('ERROR: Preview: Failed to ' + dataVideo.play + ' webcam!');
    });
  };
  api.startVideo = function (mode) {
    var retry = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : 0;
    photoboothTools.console.log('Preview: startVideo mode: ' + mode);
    if (config.preview.mode !== PreviewMode.URL.valueOf()) {
      if (!navigator.mediaDevices || config.preview.mode === PreviewMode.NONE.valueOf()) {
        return;
      }
    }
    switch (mode) {
      case CameraDisplayMode.INIT:
        photoboothTools.console.logDev('Preview: Running preview cmd (INIT).');
        api.runCmd('start');
        break;
      case CameraDisplayMode.BACKGROUND:
        if (config.preview.mode === PreviewMode.DEVICE.valueOf() && config.preview.cmd && !config.preview.bsm) {
          photoboothTools.console.logDev('Preview: Running preview cmd (BACKGROUND).');
          api.runCmd('start');
        }
        api.getAndDisplayMedia(CameraDisplayMode.BACKGROUND);
        break;
      case CameraDisplayMode.COUNTDOWN:
        if (config.preview.cmd) {
          if (config.preview.bsm || !config.preview.bsm && retry > 0) {
            photoboothTools.console.logDev('Preview: Running preview cmd (COUNTDOWN).');
            api.runCmd('start');
          }
        }
        if (config.preview.mode === PreviewMode.DEVICE.valueOf()) {
          photoboothTools.console.logDev('Preview: Preview at countdown from device cam.');
          api.getAndDisplayMedia(CameraDisplayMode.COUNTDOWN);
        } else if (config.preview.mode === PreviewMode.URL.valueOf()) {
          photoboothTools.console.logDev('Preview: Preview at countdown from URL.');
          setTimeout(function () {
            url.show();
            url.addClass('streaming');
          }, config.preview.url_delay);
        }
        break;
      case CameraDisplayMode.TEST:
        if (config.preview.mode === PreviewMode.DEVICE.valueOf()) {
          photoboothTools.console.logDev('Preview: Preview from device cam.');
          api.getAndDisplayMedia(CameraDisplayMode.TEST);
        } else if (config.preview.mode === PreviewMode.URL.valueOf()) {
          photoboothTools.console.logDev('Preview: Preview from URL.');
          setTimeout(function () {
            url.show();
            url.addClass('streaming');
          }, config.preview.url_delay);
        }
        break;
      default:
        photoboothTools.console.log('ERROR: Preview: Call for unexpected video mode: ' + mode);
        break;
    }
  };
  api.stopPreview = function () {
    if (config.preview.killcmd) {
      api.runCmd('stop');
    }
    if (config.preview.mode === PreviewMode.DEVICE.valueOf()) {
      setTimeout(function () {
        api.stopVideo();
      }, config.picture.cntdwn_offset * 1000);
    } else if (config.preview.mode === PreviewMode.URL.valueOf()) {
      setTimeout(function () {
        url.removeClass('streaming');
        url.hide();
      }, config.picture.cntdwn_offset * 1000);
    }
  };
  api.stopVideo = function () {
    mediaGeneration += 1;
    mediaRequest = null;
    wrapper.css('background-color', config.colors.panel);
    loader.css('background', config.colors.panel);
    loader.css('background-color', config.colors.panel);
    if (api.stream) {
      api.stream.getTracks().forEach(function (track) { track.stop(); });
      api.stream = null;
    }
    if (video.get(0)) video.get(0).srcObject = null;
    video.hide();
    pictureFrame.hide();
  };
  api.prepareFilterPreview = function () {
    var generation = mediaGeneration;
    var command = !config.preview.camTakesPic && config.preview.cmd ? Promise.resolve(api.runCmd('start')) : Promise.resolve();
    return command.then(function () {
      if (generation !== mediaGeneration) throw new Error('Camera preview was cancelled');
      if (config.preview.mode === PreviewMode.URL.valueOf()) return null;
      return api.initializeMedia(function () {}, 0, true);
    });
  };
  api.setElements = function () {
    var videoSelector = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : '#video--view';
    var loaderSelector = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : '#loader';
    var wrapperSelector = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : '#wrapper';
    var urlSelector = arguments.length > 3 && arguments[3] !== undefined ? arguments[3] : '#ipcam--view';
    var pictureFrameSelector = arguments.length > 4 && arguments[4] !== undefined ? arguments[4] : '#picture--frame';
    video = $(videoSelector);
    loader = $(loaderSelector);
    wrapper = $(wrapperSelector);
    url = $(urlSelector);
    pictureFrame = $(pictureFrameSelector);
  };
  api.init = function () {
    api.setElements();
  };
  return api;
}();
$(function () {
  photoboothPreview.init();
  photoboothTools.console.log('Preview: Preview functions available.');
});
