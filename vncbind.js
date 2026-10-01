/* Asks for a VNC listen address before a VM create or update is sent. */
(function () {
  var STATUS = '/plugins/VncBind/include/vncbind-status.php';
  var SAVE = '/plugins/VncBind/include/vncbind-save.php';
  var REWRITE = '/plugins/VncBind/include/vncbind-rewrite.php';
  var ALERT = '/plugins/VncBind/include/vncbind-alert.php';
  var asking = false;
  var queue = [];

  function csrf() {
    if (typeof window.csrf_token === 'string' && window.csrf_token) return window.csrf_token;
    var el = document.querySelector('input[name="csrf_token"]');
    return el ? el.value : '';
  }

  function bodyString(data) {
    if (typeof data === 'string') return data;
    if (data && typeof data === 'object' && window.jQuery) {
      try { return window.jQuery.param(data); } catch (e) { return ''; }
    }
    return '';
  }

  function field(body, key) {
    var parts = String(body).split('&');
    for (var i = 0; i < parts.length; i++) {
      var eq = parts[i].indexOf('=');
      if (eq < 0) continue;
      var k = decodeURIComponent(parts[i].slice(0, eq).replace(/\+/g, ' '));
      if (k === key) return decodeURIComponent(parts[i].slice(eq + 1).replace(/\+/g, ' '));
    }
    return '';
  }

  function enc(value) {
    return encodeURIComponent(value).replace(/%20/g, '+').replace(/'/g, '%27');
  }

  function replaceField(body, key, value) {
    var parts = String(body).split('&');
    var next = enc(value);
    var found = false;
    for (var i = 0; i < parts.length; i++) {
      var eq = parts[i].indexOf('=');
      if (eq < 0) continue;
      var k = decodeURIComponent(parts[i].slice(0, eq).replace(/\+/g, ' '));
      if (k === key) {
        parts[i] = enc(key) + '=' + next;
        found = true;
      }
    }
    if (!found) parts.push(enc(key) + '=' + next);
    return parts.join('&');
  }

  function isVncSave(opts) {
    var url = String(opts && opts.url || '');
    if (url.indexOf('Custom.form.php') === -1 || url.indexOf('VncBind') !== -1) return false;
    var method = String(opts.type || opts.method || 'GET').toUpperCase();
    if (method !== 'POST') return false;
    var body = bodyString(opts.data);
    var xml = field(body, 'xmldesc');
    if (xml) return /<graphics\b[^>]*\btype=['"]vnc['"]/i.test(xml);
    if (field(body, 'createvm') === '' && field(body, 'updatevm') === '') return false;
    return /(?:^|&)gpu(?:%5B|\[)\d+(?:%5D|])(?:%5B|\[)protocol(?:%5D|])=vnc(?:&|$)/i.test(body);
  }

  function currentListen(xml) {
    var block = String(xml).match(/<graphics\b[^>]*\btype=['"]vnc['"][^>]*>[\s\S]*?<\/graphics>/i);
    if (!block) return '';
    var child = block[0].match(/<listen\b[^>]*\baddress=['"]([^'"]*)['"]/i);
    if (child) return child[1];
    var listen = block[0].match(/\blisten=['"]([^'"]*)['"]/);
    return listen ? listen[1] : '';
  }

  function releaseForm() {
    var spin = document.getElementById('spinner');
    if (spin) spin.style.display = 'none';
    if (!window.jQuery) return;
    var $ = window.jQuery;
    $('#vmform .formview input, #vmform .xmlview input').prop('disabled', false);
    $('#vmform #btnSubmit, #vmform #btnTemplateSubmit').each(function () {
      var ready = $(this).attr('readyvalue');
      if (ready) $(this).val(ready);
    });
  }

  function post(url, fields, cb) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (key) {
      body.append(key, fields[key]);
    });
    var x = new XMLHttpRequest();
    x.open('POST', url, true);
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    var token = csrf();
    if (token) x.setRequestHeader('X-CSRF-TOKEN', token);
    x.timeout = 20000;
    x.onload = function () {
      if (x.status < 200 || x.status >= 300) { cb(null); return; }
      if (x.responseURL && /[?&]err=/.test(x.responseURL)) { cb(null); return; }
      try { cb(JSON.parse(x.responseText)); return; } catch (e) { /* settings page */ }
      cb({ ok: true });
    };
    x.onerror = x.ontimeout = function () { cb(null); };
    x.send(body.toString());
  }

  function loadStatus(cb) {
    var x = new XMLHttpRequest();
    x.open('GET', STATUS, true);
    x.timeout = 8000;
    x.onload = function () {
      if (x.status >= 200 && x.status < 300) {
        try { cb(JSON.parse(x.responseText)); return; } catch (e) { /* fall through */ }
      }
      cb(null);
    };
    x.onerror = x.ontimeout = function () { cb(null); };
    x.send();
  }

  function overlay() {
    var root = document.getElementById('vncbind-ask');
    if (root) return root;
    root = document.createElement('div');
    root.id = 'vncbind-ask';
    root.hidden = true;
    root.innerHTML = '<div class="vncbind-ask-box" role="dialog" aria-modal="true">' +
      '<p class="vncbind-ask-text"></p>' +
      '<div class="vncbind-ask-list"></div>' +
      '<div class="vncbind-ask-actions">' +
      '<button type="button" class="vncbind-ask-ok">Use this address</button>' +
      '<button type="button" class="vncbind-ask-cancel">Cancel</button>' +
      '</div></div>';
    document.body.appendChild(root);
    root.addEventListener('click', function (ev) {
      if (ev.target === root) root.querySelector('.vncbind-ask-cancel').click();
    });
    return root;
  }

  function ask(status, xml, done) {
    var root = overlay();
    var text = root.querySelector('.vncbind-ask-text');
    var list = root.querySelector('.vncbind-ask-list');
    var ok = root.querySelector('.vncbind-ask-ok');
    var cancel = root.querySelector('.vncbind-ask-cancel');
    var choices = status && Array.isArray(status.choices) ? status.choices : [];
    var have = currentListen(xml || '');
    list.textContent = '';
    text.textContent = choices.length
      ? 'Choose the address VNC listens on.'
      : 'No allowed interface has an address. The save was not sent.';
    ok.hidden = choices.length === 0;
    var matched = false;
    choices.forEach(function (row) {
      var label = document.createElement('label');
      var radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'vncbind-addr';
      radio.value = row.addr;
      if (have && row.addr === have) {
        radio.checked = true;
        matched = true;
      }
      label.appendChild(radio);
      label.appendChild(document.createTextNode(' ' + row.name + ' ' + row.addr));
      list.appendChild(label);
    });
    if (!matched) {
      var first = list.querySelector('input');
      if (first) first.checked = true;
    }
    function finish(addr) {
      document.removeEventListener('keydown', onKey);
      root.hidden = true;
      ok.onclick = null;
      cancel.onclick = null;
      done(addr);
    }
    function onKey(ev) {
      if (ev.key === 'Escape') finish(null);
    }
    ok.onclick = function () {
      var picked = root.querySelector('input[name="vncbind-addr"]:checked');
      finish(picked ? picked.value : null);
    };
    cancel.onclick = function () { finish(null); };
    document.addEventListener('keydown', onKey);
    root.hidden = false;
    if (ok.hidden) cancel.focus();
    else ok.focus();
  }

  function enqueue(work) {
    queue.push(work);
    pump();
  }

  function pump() {
    if (asking || !queue.length) return;
    asking = true;
    var work = queue.shift();
    work(function () {
      asking = false;
      pump();
    });
  }

  function installAjax() {
    var $ = window.jQuery;
    if (!$ || $._vncbind) return;
    var real = $.ajax;
    $.ajax = function (url, options) {
      var single = url && typeof url === 'object';
      var opts = single ? url : $.extend({}, options, { url: url });
      if (!isVncSave(opts)) return real.apply(this, arguments);
      var deferred = $.Deferred();
      enqueue(function (next) {
        loadStatus(function (status) {
          if (!status || status.interjection !== 'yes') {
            var passed = real.call($, opts);
            passed.done(function () { deferred.resolveWith(this, arguments); });
            passed.fail(function () { deferred.rejectWith(this, arguments); });
            next();
            return;
          }
          var body = bodyString(opts.data);
          ask(status, field(body, 'xmldesc'), function (addr) {
            if (!addr) {
              releaseForm();
              deferred.reject();
              next();
              return;
            }
            var xml = field(body, 'xmldesc');
            var send = function (nextBody) {
              if (nextBody == null) {
                var refused = overlay();
                var note = refused.querySelector('.vncbind-ask-text');
                var dismiss = function () {
                  refused.hidden = true;
                  releaseForm();
                  deferred.reject();
                  next();
                };
                if (note) note.textContent = 'That address was refused. The save was not sent.';
                refused.querySelector('.vncbind-ask-ok').hidden = true;
                function onEsc(ev) {
                  if (ev.key === 'Escape') dismiss();
                }
                var cancel = refused.querySelector('.vncbind-ask-cancel');
                var close = function () {
                  document.removeEventListener('keydown', onEsc);
                  dismiss();
                };
                cancel.onclick = close;
                document.addEventListener('keydown', onEsc);
                refused.hidden = false;
                cancel.focus();
                return;
              }
              opts.data = nextBody;
              var userSuccess = opts.success;
              var userError = opts.error;
              opts.success = function (data, textStatus, jqXHR) {
                var finish = function (out) {
                  post(ALERT, { csrf_token: csrf() }, function () {});
                  try {
                    if (typeof userSuccess === 'function') userSuccess.call(jqXHR, out, textStatus, jqXHR);
                  } finally {
                    deferred.resolve(out, textStatus, jqXHR);
                    next();
                  }
                };
                if (!data || !data.success) {
                  finish(data);
                  return;
                }
                var name = field(nextBody, 'domain[name]');
                if (!name) {
                  if (!field(nextBody, 'xmldesc')) data = { error: 'The listen address was not saved.' };
                  finish(data);
                  return;
                }
                post(REWRITE, { csrf_token: csrf(), domain: name, addr: addr }, function (rewritten) {
                  if (!rewritten || !rewritten.ok) data = { error: 'The listen address was not saved.' };
                  finish(data);
                });
              };
              opts.error = function (jqXHR, textStatus, err) {
                if (typeof userError === 'function') userError.apply(this, arguments);
                deferred.reject(jqXHR, textStatus, err);
                next();
              };
              real.call($, opts);
            };
            if (!xml) {
              send(body);
              return;
            }
            post(REWRITE, { csrf_token: csrf(), xml: xml, addr: addr }, function (rewritten) {
              if (!rewritten || !rewritten.ok || typeof rewritten.xml !== 'string') {
                send(null);
                return;
              }
              send(replaceField(body, 'xmldesc', rewritten.xml));
            });
          });
        });
      });
      return deferred.promise();
    };
    $._vncbind = 1;
  }

  function placeVmControl() {
    if (document.getElementById('vncbind-vmset')) return;
    if (!/VMSettings(?:\.page)?\/?$/.test(location.pathname)) return;
    var form = document.getElementById('settingsForm');
    if (!form) return;
    var advanced = form.querySelector('div.advanced');
    if (!advanced) return;
    var dl = document.createElement('dl');
    dl.id = 'vncbind-vmset';
    var dt = document.createElement('dt');
    dt.textContent = 'VNC Bind:';
    var dd = document.createElement('dd');
    var select = document.createElement('select');
    [['yes', 'Enable'], ['no', 'Disable']].forEach(function (pair) {
      var opt = document.createElement('option');
      opt.value = pair[0];
      opt.textContent = pair[1];
      select.appendChild(opt);
    });
    dd.appendChild(select);
    dl.appendChild(dt);
    dl.appendChild(dd);
    advanced.insertBefore(dl, advanced.firstChild);
    select.disabled = true;
    loadStatus(function (status) {
      if (status && (status.interjection === 'yes' || status.interjection === 'no')) {
        select.value = status.interjection;
      }
      select.disabled = false;
    });
    select.addEventListener('change', function () {
      var picked = select.value;
      select.disabled = true;
      post(SAVE, { csrf_token: csrf(), interjection: picked, from: 'vm' }, function (data) {
        select.disabled = false;
        if (!data || data.ok === false) {
          loadStatus(function (status) {
            if (status && status.interjection) select.value = status.interjection;
          });
        }
      });
    });
  }

  function boot() {
    installAjax();
    placeVmControl();
  }

  if (window.jQuery) {
    window.jQuery(boot);
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
