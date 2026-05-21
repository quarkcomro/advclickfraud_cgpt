(function () {
  'use strict';

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
      return;
    }
    callback();
  }

  ready(function () {
    var tabStorageKey = 'advclickfraud_active_admin_tab';
    var refreshStorageKey = 'advclickfraud_event_refresh_seconds';
    var tabs = document.querySelectorAll('#advclickfraud-dashboard-tabs a[data-toggle="tab"]');
    var intervalSelect = document.getElementById('advclickfraud-refresh-interval');
    var countdownNode = document.getElementById('advclickfraud-refresh-countdown');
    var tableWrapperId = 'advclickfraud-events-table-wrapper';
    var refreshSeconds = 0;
    var remainingSeconds = 0;
    var timerId = null;

    function activateStoredTab() {
      var savedTab = window.localStorage ? window.localStorage.getItem(tabStorageKey) : null;
      if (savedTab && window.jQuery) {
        window.jQuery('#advclickfraud-dashboard-tabs a[href="' + savedTab + '"]').tab('show');
      }
    }

    function rememberTabs() {
      if (!window.jQuery) {
        return;
      }
      window.jQuery(tabs).on('shown.bs.tab', function (event) {
        if (window.localStorage) {
          window.localStorage.setItem(tabStorageKey, event.target.getAttribute('href'));
        }
      });
    }

    function updateCountdownText() {
      if (!countdownNode) {
        return;
      }
      if (refreshSeconds <= 0) {
        countdownNode.textContent = 'Disabled';
        return;
      }
      countdownNode.textContent = String(remainingSeconds) + 's';
    }

    function replaceEventsTable(html) {
      var parser = new DOMParser();
      var documentCopy = parser.parseFromString(html, 'text/html');
      var nextWrapper = documentCopy.getElementById(tableWrapperId);
      var currentWrapper = document.getElementById(tableWrapperId);
      if (nextWrapper && currentWrapper) {
        currentWrapper.innerHTML = nextWrapper.innerHTML;
      }
    }

    function refreshEventsTable() {
      fetch(window.location.href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      })
        .then(function (response) { return response.text(); })
        .then(replaceEventsTable)
        .catch(function () {})
        .finally(function () {
          remainingSeconds = refreshSeconds;
          updateCountdownText();
        });
    }

    function stopTimer() {
      if (timerId !== null) {
        window.clearInterval(timerId);
        timerId = null;
      }
    }

    function startTimer() {
      stopTimer();
      refreshSeconds = intervalSelect ? parseInt(intervalSelect.value, 10) || 0 : 0;
      remainingSeconds = refreshSeconds;
      updateCountdownText();
      if (refreshSeconds <= 0) {
        return;
      }
      timerId = window.setInterval(function () {
        remainingSeconds -= 1;
        if (remainingSeconds <= 0) {
          refreshEventsTable();
          return;
        }
        updateCountdownText();
      }, 1000);
    }

    activateStoredTab();
    rememberTabs();

    if (intervalSelect) {
      var storedInterval = window.localStorage ? window.localStorage.getItem(refreshStorageKey) : null;
      if (storedInterval !== null) {
        intervalSelect.value = storedInterval;
      }
      intervalSelect.addEventListener('change', function () {
        if (window.localStorage) {
          window.localStorage.setItem(refreshStorageKey, intervalSelect.value);
        }
        startTimer();
      });
      startTimer();
    }
  });
}());
