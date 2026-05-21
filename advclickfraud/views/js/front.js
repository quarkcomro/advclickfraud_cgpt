(function () {
  'use strict';

  if (!window.advClickFraud || !window.advClickFraud.collectUrl) {
    return;
  }

  var start = Date.now();
  var interacted = false;
  var maxScroll = 0;

  function bucketNumber(value, step, max) {
    var numeric = Number(value) || 0;
    if (numeric > max) {
      return String(max) + '+';
    }
    return String(Math.floor(numeric / step) * step);
  }

  function hashString(input) {
    var hash = 0;
    var text = String(input || '');
    for (var i = 0; i < text.length; i += 1) {
      hash = ((hash << 5) - hash) + text.charCodeAt(i);
      hash |= 0;
    }
    return String(hash);
  }

  function canvasSignal() {
    try {
      var canvas = document.createElement('canvas');
      var context = canvas.getContext('2d');
      if (!context) {
        return '';
      }
      context.textBaseline = 'top';
      context.font = '14px Arial';
      context.fillText('advclickfraud', 2, 2);
      return hashString(canvas.toDataURL());
    } catch (error) {
      return '';
    }
  }

  function webglSignal() {
    try {
      var canvas = document.createElement('canvas');
      var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
      if (!gl) {
        return '';
      }
      var debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
      if (!debugInfo) {
        return '';
      }
      return hashString(
        gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) + '|' +
        gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL)
      );
    } catch (error) {
      return '';
    }
  }

  function storageAvailable() {
    try {
      var key = '__advclickfraud_storage_test__';
      window.localStorage.setItem(key, '1');
      window.localStorage.removeItem(key);
      return true;
    } catch (error) {
      return false;
    }
  }

  function collectQuery() {
    var result = {};
    var params = new URLSearchParams(window.location.search);
    ['gclid', 'fbclid', 'ttclid', 'msclkid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content'].forEach(function (key) {
      if (params.has(key)) {
        result[key] = params.get(key);
      }
    });
    return result;
  }

  function buildPayload() {
    var screenWidth = window.screen && window.screen.width ? window.screen.width : 0;
    var screenHeight = window.screen && window.screen.height ? window.screen.height : 0;

    return {
      sdkVersion: '1.0.0',
      requestId: window.advClickFraud.requestId || '',
      pageType: window.advClickFraud.pageType || 'page',
      shopId: window.advClickFraud.shopId || 0,
      query: collectQuery(),
      signals: {
        uaJs: navigator.userAgent || '',
        platform: navigator.platform || '',
        timezone: Intl && Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone || '' : '',
        locale: navigator.language || '',
        screenBucket: bucketNumber(screenWidth, 100, 4000) + 'x' + bucketNumber(screenHeight, 100, 4000),
        dprBucket: bucketNumber(window.devicePixelRatio || 1, 1, 5),
        touch: ('ontouchstart' in window) || (navigator.maxTouchPoints > 0),
        webdriver: Boolean(navigator.webdriver),
        canvas: canvasSignal(),
        webgl: webglSignal(),
        audio: '',
        storage: storageAvailable(),
        cookieRoundtrip: navigator.cookieEnabled
      },
      behavior: {
        timeToFirstInteractionMs: interacted ? Date.now() - start : null,
        scrollDepthBucket: bucketNumber(maxScroll, 25, 100)
      }
    };
  }

  function sendPayload() {
    var payload = JSON.stringify(buildPayload());
    if (navigator.sendBeacon) {
      navigator.sendBeacon(window.advClickFraud.collectUrl, new Blob([payload], {type: 'application/json'}));
      return;
    }
    fetch(window.advClickFraud.collectUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: payload,
      keepalive: true
    }).catch(function () {});
  }

  ['pointerdown', 'keydown', 'touchstart'].forEach(function (eventName) {
    window.addEventListener(eventName, function () {
      interacted = true;
    }, {once: true, passive: true});
  });

  window.addEventListener('scroll', function () {
    var height = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    maxScroll = Math.max(maxScroll, Math.round((window.scrollY / height) * 100));
  }, {passive: true});

  window.setTimeout(sendPayload, 800);
}());
