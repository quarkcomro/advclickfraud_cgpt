(function () {
  'use strict';

  var config = window.advClickFraud || {};
  if (!config.collectUrl || typeof config.collectUrl !== 'string') {
    return;
  }

  var start = Date.now();
  var interacted = false;
  var firstInteractionAt = null;
  var maxScroll = 0;
  var maxPayloadBytes = 30000;
  var queryKeys = [
    'gclid',
    'wbraid',
    'gbraid',
    'fbclid',
    'ttclid',
    'msclkid',
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_content',
    'utm_term'
  ];

  function safeString(value, maxLength) {
    if (value === null || value === undefined) {
      return '';
    }

    var text = String(value);
    if (text.length > maxLength) {
      return text.slice(0, maxLength);
    }

    return text;
  }

  function bucketNumber(value, step, max) {
    var numeric = Number(value) || 0;
    if (numeric < 0) {
      numeric = 0;
    }
    if (numeric > max) {
      return String(max) + '+';
    }

    return String(Math.floor(numeric / step) * step);
  }

  function hashString(input) {
    var hash = 0;
    var text = safeString(input, 8192);
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
      if (!gl || typeof gl.getExtension !== 'function') {
        return '';
      }

      var debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
      if (!debugInfo) {
        return '';
      }

      return hashString(
        String(gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) || '') + '|' +
        String(gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || '')
      );
    } catch (error) {
      return '';
    }
  }

  function storageAvailable() {
    try {
      if (!window.localStorage) {
        return false;
      }

      var key = '__advclickfraud_storage_test__';
      window.localStorage.setItem(key, '1');
      window.localStorage.removeItem(key);

      return true;
    } catch (error) {
      return false;
    }
  }

  function getTimezone() {
    try {
      if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
        return safeString(Intl.DateTimeFormat().resolvedOptions().timeZone || '', 128);
      }
    } catch (error) {
      return '';
    }

    return '';
  }

  function decodeQueryValue(value) {
    try {
      return decodeURIComponent(String(value || '').replace(/\+/g, ' '));
    } catch (error) {
      return String(value || '');
    }
  }

  function collectQueryFallback() {
    var result = {};
    var query = window.location && window.location.search ? window.location.search.slice(1) : '';
    if (!query) {
      return result;
    }

    query.split('&').forEach(function (pair) {
      var parts = pair.split('=');
      var key = decodeQueryValue(parts[0]);
      if (queryKeys.indexOf(key) === -1) {
        return;
      }

      result[key] = safeString(decodeQueryValue(parts.slice(1).join('=')), 255);
    });

    return result;
  }

  function collectQuery() {
    var result = {};

    if (typeof URLSearchParams === 'undefined') {
      return collectQueryFallback();
    }

    try {
      var params = new URLSearchParams(window.location.search);
      queryKeys.forEach(function (key) {
        if (params.has(key)) {
          result[key] = safeString(params.get(key), 255);
        }
      });
    } catch (error) {
      return collectQueryFallback();
    }

    return result;
  }

  function classifyAttribution(query) {
    if (query.gclid || query.wbraid || query.gbraid) {
      return {
        isPaidClick: true,
        channel: 'google_ads',
        clickIdType: query.gclid ? 'gclid' : (query.wbraid ? 'wbraid' : 'gbraid')
      };
    }
    if (query.fbclid) {
      return {isPaidClick: true, channel: 'meta_ads', clickIdType: 'fbclid'};
    }
    if (query.ttclid) {
      return {isPaidClick: true, channel: 'tiktok_ads', clickIdType: 'ttclid'};
    }
    if (query.msclkid) {
      return {isPaidClick: true, channel: 'microsoft_ads', clickIdType: 'msclkid'};
    }
    if (query.utm_source) {
      return {isPaidClick: true, channel: safeString(query.utm_source, 64), clickIdType: 'utm_source'};
    }

    return {isPaidClick: false, channel: 'organic_or_direct', clickIdType: ''};
  }

  function buildPayload() {
    var nav = window.navigator || {};
    var screenData = window.screen || {};
    var screenWidth = screenData.width || 0;
    var screenHeight = screenData.height || 0;
    var query = collectQuery();
    var attribution = classifyAttribution(query);

    return {
      sdkVersion: '1.0.0',
      requestId: safeString(config.requestId || '', 64),
      pageType: safeString(config.pageType || 'page', 32),
      shopId: Number(config.shopId) || 0,
      query: query,
      attribution: {
        isPaidClick: attribution.isPaidClick,
        channel: attribution.channel,
        clickIdType: attribution.clickIdType,
        campaign: safeString(query.utm_campaign || '', 128),
        medium: safeString(query.utm_medium || '', 64),
        content: safeString(query.utm_content || '', 128),
        term: safeString(query.utm_term || '', 128)
      },
      signals: {
        uaJs: safeString(nav.userAgent || '', 512),
        platform: safeString(nav.platform || '', 128),
        timezone: getTimezone(),
        locale: safeString(nav.language || '', 64),
        screenBucket: bucketNumber(screenWidth, 100, 4000) + 'x' + bucketNumber(screenHeight, 100, 4000),
        dprBucket: bucketNumber(window.devicePixelRatio || 1, 1, 5),
        touch: ('ontouchstart' in window) || ((nav.maxTouchPoints || 0) > 0),
        webdriver: Boolean(nav.webdriver),
        canvas: canvasSignal(),
        webgl: webglSignal(),
        audio: '',
        storage: storageAvailable(),
        cookieRoundtrip: Boolean(nav.cookieEnabled)
      },
      behavior: {
        sdkElapsedMs: Date.now() - start,
        timeToFirstInteractionMs: firstInteractionAt === null ? null : firstInteractionAt - start,
        scrollDepthBucket: bucketNumber(maxScroll, 25, 100),
        hadInteraction: interacted
      }
    };
  }

  function stringifyPayload(payload) {
    try {
      var json = JSON.stringify(payload);
      if (json.length <= maxPayloadBytes) {
        return json;
      }

      payload.signals.canvas = '';
      payload.signals.webgl = '';
      payload.signals.audio = '';
      json = JSON.stringify(payload);

      return json.length <= maxPayloadBytes ? json : '';
    } catch (error) {
      return '';
    }
  }

  function sendPayload() {
    var payload = stringifyPayload(buildPayload());
    if (!payload) {
      return;
    }

    if (navigator.sendBeacon && typeof Blob !== 'undefined') {
      navigator.sendBeacon(config.collectUrl, new Blob([payload], {type: 'application/json'}));
      return;
    }

    if (typeof window.fetch === 'function') {
      window.fetch(config.collectUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: payload,
        keepalive: true
      }).catch(function () {});
    }
  }

  function addSafeEventListener(target, eventName, handler, options) {
    try {
      target.addEventListener(eventName, handler, options);
    } catch (error) {
      target.addEventListener(eventName, handler, false);
    }
  }

  ['pointerdown', 'keydown', 'touchstart'].forEach(function (eventName) {
    addSafeEventListener(window, eventName, function () {
      interacted = true;
      if (firstInteractionAt === null) {
        firstInteractionAt = Date.now();
      }
    }, {once: true, passive: true});
  });

  addSafeEventListener(window, 'scroll', function () {
    var documentElement = document.documentElement || {};
    var height = Math.max(1, (documentElement.scrollHeight || 0) - (window.innerHeight || 0));
    maxScroll = Math.max(maxScroll, Math.round(((window.scrollY || 0) / height) * 100));
  }, {passive: true});

  window.setTimeout(sendPayload, 800);
}());
