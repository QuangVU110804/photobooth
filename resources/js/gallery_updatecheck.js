"use strict";

/* globals photoBooth photoboothTools */
/*
 * This script checks for new pictures in regular intervals.
 * If changes are detected, the page will automatically be reloaded.
 *
 * Needs:
 * - jQuery
 * - photoBooth & photoboothTools Javascript
 *
 * Only one status request may be pending. Hidden tabs and active photo
 * interactions defer polling until a later interval.
 */

// Size of the DB - is used to determine changes
var renderedDBSize = document.body.getAttribute('data-gallery-db-size');
var lastDBSize = renderedDBSize !== null && /^\d+$/.test(renderedDBSize)
  ? Number(renderedDBSize) : -1;
// Interval, the page is checked (/ms)
var configuredInterval = Number(config.gallery.db_check_time);
var interval = Number.isFinite(configuredInterval) && configuredInterval > 0
  ? Math.max(1000, 1000 * configuredInterval) : 1000;
var statusRequestPending = false;
// URL to request for changes
var ajaxurl = 'gallery.php?status';

/*
 * This function will be called if there are new pictures
 */

function dbUpdated() {
  photoboothTools.console.log('DB is updated - refreshing');
  //location.reload(true); //Alternative
  photoboothTools.reloadPage();
}
var checkForUpdates = function checkForUpdates() {
  if (document.hidden || statusRequestPending) return;
  if (typeof window !== 'undefined' && window.hmuGallerySelection && window.hmuGallerySelection.active) return;
  if (photoBooth.isTimeOutPending()) {
    // If there is user interaction, do not check for updates
    photoboothTools.console.logDev('Timeout pending, waiting to refresh the standalone gallery');
    return;
  }
  statusRequestPending = true;
  $.getJSON({
    url: ajaxurl,
    timeout: 10000,
    success: function success(result) {
      // Interaction can begin while the request is in flight. Leave the
      // baseline untouched so the next idle poll can still detect the change.
      if (document.hidden || photoBooth.isTimeOutPending() || (typeof window !== 'undefined' && window.hmuGallerySelection && window.hmuGallerySelection.active)) return;
      if (!result || !Number.isFinite(result.dbsize) || result.dbsize < 0) return;
      var currentDBSize = result.dbsize;
      if (lastDBSize != currentDBSize && lastDBSize != -1) {
        dbUpdated();
      }
      lastDBSize = currentDBSize;
    },
    complete: function complete() {
      statusRequestPending = false;
    }
  });
};
setInterval(checkForUpdates, interval);
