(function (Drupal, once) {
  'use strict';

  /**
   * Initializes HLS.js on a media element.
   *
   * @param {HTMLMediaElement} mediaElement
   *   The video or audio element.
   * @param {string} url
   *   The HLS manifest URL.
   */
  function initHls(mediaElement, url) {
    var hls = new Hls({
      debug: false,
      enableWorker: true,
    });

    hls.loadSource(url);
    hls.attachMedia(mediaElement);

    hls.on(Hls.Events.ERROR, function (event, data) {
      if (data.fatal) {
        switch (data.type) {
          case Hls.ErrorTypes.NETWORK_ERROR:
            console.error('HLS network error, attempting recovery...');
            hls.startLoad();
            break;
          case Hls.ErrorTypes.MEDIA_ERROR:
            console.error('HLS media error, attempting recovery...');
            hls.recoverMediaError();
            break;
          default:
            console.error('HLS fatal error:', data);
            hls.destroy();
            break;
        }
      }
    });

    // Store instance for cleanup.
    mediaElement.hlsInstance = hls;
  }

  /**
   * Drupal behavior for HLS player initialization.
   */
  Drupal.behaviors.islandoraHlsPlayer = {
    attach: function (context) {
      if (typeof Hls === 'undefined') {
        console.error('HLS.js library not loaded');
        return;
      }

      once('islandora-hls', '[data-hls-url]', context).forEach(function (mediaElement) {
        var url = mediaElement.getAttribute('data-hls-url');
        if (!url) {
          return;
        }

        if (Hls.isSupported()) {
          initHls(mediaElement, url);
        }
        else if (mediaElement.canPlayType('application/vnd.apple.mpegurl')) {
          // Native HLS support (Safari, iOS).
          mediaElement.src = url;
        }
        else {
          console.error('HLS playback is not supported in this browser');
        }
      });
    },

    detach: function (context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }

      once.remove('islandora-hls', '[data-hls-url]', context).forEach(function (mediaElement) {
        if (mediaElement.hlsInstance) {
          mediaElement.hlsInstance.destroy();
          delete mediaElement.hlsInstance;
        }
      });
    }
  };

})(Drupal, once);
