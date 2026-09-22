/* Payplex Video KYC — customer capture page. Vanilla JS, no dependencies.
 *
 * Flow: validate token → explain → request camera+mic → show script → record
 * (MediaRecorder) → review/retake → upload with progress → done.
 */
(function () {
  'use strict';

  var C = window.KYC_PUBLIC || {};
  var $ = function (id) { return document.getElementById(id); };
  var SECTIONS = ['s-loading', 's-error', 's-intro', 's-record', 's-review', 's-done'];
  var token = new URLSearchParams(window.location.search).get('token') || '';

  var limits = { min_sec: 5, max_sec: 90, max_mb: 30 };
  var stream = null, recorder = null, chunks = [], blob = null, blobExt = 'webm';
  var objectUrl = null, startedAt = 0, duration = 0, tick = null, autoStop = null, uploading = false;

  /* ------------------------------------------------------------------ ui */

  function show(id) {
    SECTIONS.forEach(function (s) { $(s).hidden = (s !== id); });
    window.scrollTo(0, 0);
  }

  /* --------------------------------------------------------------- i18n
   * Strings come from the server (Payplex_kyc_scripts::ui) for en / hi / mr. The page starts in
   * English and switches as soon as the link is validated and the request's language is known. */
  var lang = 'en';
  var UI = C.ui || {};
  function t(key, vars) {
    var dict = UI[lang] || {}, en = UI.en || {};
    var str = dict[key] != null ? dict[key] : (en[key] != null ? en[key] : key);
    return String(str).replace(/\{(\w+)\}/g, function (m, k) { return vars && vars[k] != null ? vars[k] : m; });
  }
  function applyLang(code) {
    lang = UI[code] ? code : 'en';
    document.documentElement.lang = lang;
    var nodes = document.querySelectorAll('[data-i18n]');
    for (var i = 0; i < nodes.length; i++) { nodes[i].textContent = t(nodes[i].getAttribute('data-i18n')); }
  }

  function fatal(reason) {
    var known = ['invalid', 'expired', 'already_submitted', 'rejected', 'attempts_exhausted', 'unsupported', 'network'];
    var r = known.indexOf(reason) !== -1 ? reason : 'invalid';
    $('err-title').textContent = t('err_' + r + '_t');
    $('err-text').textContent = t('err_' + r);
    show('s-error');
  }

  function fmtTime(sec) { return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2); }

  /* ------------------------------------------------------------ validate */

  function validate() {
    if (!/^[a-f0-9]{64}$/.test(token)) { return fatal('invalid'); }
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      return fatal('unsupported');
    }
    fetch(C.validateUrl + encodeURIComponent(token), { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { valid: false, reason: 'invalid' }; }); })
      .then(function (j) {
        if (!j.valid) { return fatal(j.reason); }
        limits = j.limits || limits;
        if (j.home_url && $('btn-home')) { $('btn-home').setAttribute('href', j.home_url); }   // employees go to the staff area
        applyLang(j.language);
        $('in-hello').textContent = t('hello', { name: j.customer_name });
        $('in-intro').textContent = t('intro', { company: j.company || C.company });
        $('script-text').textContent = j.script;
        $('script-text').setAttribute('lang', lang);          // picks the right font/shaping for Devanagari
        $('rec-hint').textContent = t('rec_hint', { min: limits.min_sec, max: limits.max_sec });
        show('s-intro');
      })
      .catch(function () { fatal('network'); });
  }

  /* ---------------------------------------------------- camera / mic access */

  function openCamera() {
    var err = $('perm-error');
    err.hidden = true;
    return navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
      audio: { echoCancellation: true, noiseSuppression: true }
    }).then(function (s) {
      stream = s;
      $('preview').srcObject = s;
      $('btn-rec').hidden = false; $('btn-stop').hidden = true; $('rec-badge').hidden = true;
      show('s-record');
    }).catch(function (e) {
      var msg = t('perm_generic');
      if (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError')) { msg = t('perm_denied'); }
      else if (e && (e.name === 'NotFoundError' || e.name === 'OverconstrainedError')) { msg = t('perm_notfound'); }
      else if (e && e.name === 'NotReadableError') { msg = t('perm_busy'); }
      err.textContent = msg; err.hidden = false;
      show('s-intro');
    });
  }

  function stopTracks() {
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    $('preview').srcObject = null;
  }

  /* ------------------------------------------------------------- recording */

  /** First container/codec this browser can actually produce. Chrome/Firefox → WebM, Safari → MP4. */
  function pickMime() {
    var list = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4;codecs=avc1,mp4a.40.2', 'video/mp4'];
    for (var i = 0; i < list.length; i++) {
      if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(list[i])) { return list[i]; }
    }
    return '';
  }

  function startRecording() {
    chunks = []; blob = null;
    var mime = pickMime();
    var opts = { videoBitsPerSecond: 1200000, audioBitsPerSecond: 64000 };   // ≈ 9 MB/min: small, still legible
    if (mime) { opts.mimeType = mime; }
    try { recorder = new MediaRecorder(stream, opts); }
    catch (e) { try { recorder = new MediaRecorder(stream); } catch (e2) { return fatal('unsupported'); } }

    var type = (recorder.mimeType || mime || 'video/webm').split(';')[0];
    blobExt = type.indexOf('mp4') !== -1 ? 'mp4' : 'webm';
    recorder.ondataavailable = function (ev) { if (ev.data && ev.data.size) { chunks.push(ev.data); } };
    recorder.onstop = function () { finishRecording(type); };
    recorder.start(1000);                                // 1s slices: little is lost if the tab dies

    startedAt = Date.now();
    $('btn-rec').hidden = true;
    $('btn-stop').hidden = false; $('btn-stop').disabled = true;
    $('rec-badge').hidden = false; $('rec-timer').textContent = '0:00';
    tick = setInterval(function () {
      var s = Math.floor((Date.now() - startedAt) / 1000);
      $('rec-timer').textContent = fmtTime(s);
      if (s >= limits.min_sec) { $('btn-stop').disabled = false; }
    }, 250);
    autoStop = setTimeout(stopRecording, limits.max_sec * 1000);
  }

  function stopRecording() {
    if (!recorder || recorder.state === 'inactive') { return; }
    clearInterval(tick); clearTimeout(autoStop);
    duration = Math.round((Date.now() - startedAt) / 1000);
    recorder.stop();
  }

  function finishRecording(type) {
    $('rec-badge').hidden = true;
    stopTracks();
    blob = new Blob(chunks, { type: type });
    chunks = [];
    if (!blob.size) { blob = null; $('perm-error').textContent = t('nothing_recorded'); $('perm-error').hidden = false; return show('s-intro'); }

    if (objectUrl) { URL.revokeObjectURL(objectUrl); }
    objectUrl = URL.createObjectURL(blob);
    $('playback').src = objectUrl;
    $('upload-msg').hidden = true; $('upload-progress').hidden = true;
    $('btn-submit').disabled = false; $('btn-retake').disabled = false;
    if (blob.size > limits.max_mb * 1048576) {
      $('upload-msg').textContent = t('too_large');
      $('upload-msg').hidden = false; $('btn-submit').disabled = true;
    }
    show('s-review');
  }

  function retake() {
    if (uploading) { return; }
    if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
    $('playback').removeAttribute('src');
    blob = null;
    openCamera();
  }

  /* ---------------------------------------------------------------- upload */

  function submit() {
    if (!blob || uploading) { return; }
    uploading = true;
    $('btn-submit').disabled = true; $('btn-retake').disabled = true;
    $('upload-msg').hidden = true;
    $('upload-progress').hidden = false; $('upload-bar').style.width = '0%';

    var fd = new FormData();
    fd.append('token', token);
    fd.append(C.csrfName, C.csrfHash);
    fd.append('duration', String(duration));
    fd.append('video', blob, 'kyc.' + blobExt);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', C.uploadUrl);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.onprogress = function (e) { if (e.lengthComputable) { $('upload-bar').style.width = Math.round(e.loaded / e.total * 100) + '%'; } };
    xhr.onload = function () {
      uploading = false;
      var j = null; try { j = JSON.parse(xhr.responseText); } catch (e) { /* HTML error page, e.g. a CSRF or size rejection */ }
      if (j && j.success) {
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        blob = null;
        return show('s-done');
      }
      if (j && (j.reason === 'expired' || j.reason === 'already_submitted' || j.reason === 'attempts_exhausted' || j.reason === 'invalid')) {
        return fatal(j.reason);
      }
      var byCode = { too_large: 'too_large', not_video: 'err_not_video', no_file: 'err_no_file', store_failed: 'err_store' };
      failUpload(t(j && byCode[j.code] ? byCode[j.code] : 'upload_blocked'));
    };
    xhr.onerror = xhr.ontimeout = function () {
      uploading = false;
      failUpload(t('upload_network'));
    };
    xhr.timeout = 5 * 60 * 1000;
    xhr.send(fd);
  }

  function failUpload(msg) {
    $('upload-msg').textContent = msg; $('upload-msg').hidden = false;
    $('upload-progress').hidden = true;
    $('btn-submit').disabled = false; $('btn-retake').disabled = false;
  }

  /* ------------------------------------------------------------------ wiring */

  $('btn-start').addEventListener('click', openCamera);
  $('btn-rec').addEventListener('click', startRecording);
  $('btn-stop').addEventListener('click', stopRecording);
  $('btn-retake').addEventListener('click', retake);
  $('btn-submit').addEventListener('click', submit);

  // Don't let someone lose a recording (or interrupt an upload) by accident.
  window.addEventListener('beforeunload', function (e) {
    if ((recorder && recorder.state === 'recording') || blob || uploading) { e.preventDefault(); e.returnValue = ''; }
  });

  validate();
})();
