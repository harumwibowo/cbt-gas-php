/**
 * Shim google.script.run → fetch ke api.php
 * ---------------------------------------------------------------
 * Frontend CBT memanggil backend lewat google.script.run (Apps Script).
 * Shim ini menyediakan API yang sama persis (.withSuccessHandler,
 * .withFailureHandler, .withUserObject) sehingga seluruh kode UI tidak
 * perlu diubah, namun eksekusinya dilakukan oleh backend PHP + MySQL.
 */
(function () {
  'use strict';

  if (window.google && window.google.script && window.google.script.run && window.google.script.run.__cbtNative) {
    return;
  }

  var API_URL = new URL('api.php', document.baseURI).toString();
  var REQUEST_TIMEOUT_MS = 120000;

  function send(action, args, onSuccess, onFailure) {
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () {
      if (controller) controller.abort();
    }, REQUEST_TIMEOUT_MS);

    var fail = function (message) {
      clearTimeout(timer);
      if (typeof onFailure === 'function') {
        var e = message instanceof Error ? message : new Error(String(message));
        onFailure(e);
      } else {
        console.error('[CBT RPC] ' + action + ': ' + message);
      }
    };

    // CSRF double-submit: baca cookie (bukan HttpOnly) lalu kirim sebagai header.
    var headers = { 'Content-Type': 'application/json' };
    var csrf = '';
    document.cookie.split(';').some(function (item) {
      var pair = item.trim().split('=');
      if (pair[0] === 'cbt_csrf') { csrf = decodeURIComponent(pair.slice(1).join('=')); return true; }
      return false;
    });
    if (csrf) headers['X-CSRF-Token'] = csrf;

    fetch(API_URL + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: headers,
      body: JSON.stringify({ action: action, args: args }),
      credentials: 'same-origin',
      cache: 'no-store',
      signal: controller ? controller.signal : undefined
    })
      .then(function (res) {
        return res.text().then(function (text) {
          var data = null;
          if (text) {
            try {
              data = JSON.parse(text);
            } catch (parseErr) {
              throw new Error('Respons server tidak valid (HTTP ' + res.status + '): ' + text.slice(0, 300));
            }
          }
          if (!res.ok) {
            throw new Error((data && data.error) || 'HTTP ' + res.status + ' ' + res.statusText);
          }
          if (data && typeof data === 'object' && data.error) {
            throw new Error(data.error);
          }
          return data;
        });
      })
      .then(function (data) {
        clearTimeout(timer);
        if (typeof onSuccess === 'function') onSuccess(data);
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') {
          fail('Permintaan ' + action + ' melebihi batas waktu ' + Math.round(REQUEST_TIMEOUT_MS / 1000) + ' detik.');
          return;
        }
        fail(e);
      });
  }

  function makeRunner(onSuccess, onFailure, userObject) {
    var chain = {
      withSuccessHandler: function (fn) {
        return makeRunner(fn, onFailure, userObject);
      },
      withFailureHandler: function (fn) {
        return makeRunner(onSuccess, fn, userObject);
      },
      withUserObject: function (obj) {
        return makeRunner(onSuccess, onFailure, obj);
      }
    };

    return new Proxy(chain, {
      get: function (target, prop) {
        if (typeof prop !== 'string') return undefined;
        if (Object.prototype.hasOwnProperty.call(target, prop)) return target[prop];
        if (prop === 'then' || prop === 'catch' || prop === 'finally') return undefined;
        return function () {
          var args = Array.prototype.slice.call(arguments);
          send(prop, args, onSuccess, onFailure);
        };
      }
    });
  }

  var fallbackUserObject = null;
  var runner = makeRunner(
    function () {},
    function () {},
    fallbackUserObject
  );

  // Pemanggilan langsung tanpa handler (mis. google.script.run.logout(...)).
  var runApi = new Proxy(runner, {
    get: function (target, prop) {
      if (typeof prop !== 'string') return undefined;
      if (prop === '__cbtNative') return true;
      return target[prop];
    },
    set: function (target, prop, value) {
      target[prop] = value;
      return true;
    }
  });

  window.google = window.google || {};
  window.google.script = window.google.script || {};
  window.google.script.run = runApi;
  window.google.script.host = window.google.script.host || { close: function () {} };
  window.google.script.url = window.google.script.url || {
    getLocation: function (cb) {
      if (typeof cb === 'function') cb({ parameter: {}, hash: window.location.hash || '' });
    }
  };
})();
