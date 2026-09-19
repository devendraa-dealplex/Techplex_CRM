/* Payplex Video KYC — staff dashboard behaviour.
 * Depends on jQuery + Bootstrap 3 (bundled by Perfex). CSRF is appended to every
 * jQuery POST by Perfex's own ajaxSetup (csrfData), so nothing to add here. */
(function ($) {
  'use strict';
  if (!$ || !window.KYC) { return; }

  var K = window.KYC;
  var table = $('#kyc-table');
  var state = { page: 1, perPage: parseInt(table.data('per-page'), 10) || 10, status: '', q: '' };
  var chosen = null;          // subject picked in the generate modal
  var current = null;         // request open in the review modal
  var reviewIntent = null;

  /* ------------------------------------------------------------- helpers */

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmt(d) {
    if (!d) { return '—'; }
    var t = new Date(String(d).replace(' ', 'T'));
    return isNaN(t) ? esc(d) : t.toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function bytes(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  var STATUS = {
    pending:     ['Pending',                  'default'],
    in_progress: ['Verification in progress', 'info'],
    submitted:   ['Awaiting review',          'warning'],
    approved:    ['Approved',                 'success'],
    rejected:    ['Rejected',                 'danger'],
    expired:     ['Expired',                  'default']
  };
  // Small language tag for non-English requests, e.g. "हिंदी".
  function langTag(code) {
    if (!code || code === 'en') { return ''; }
    var full = (K.languages && K.languages[code]) || code;
    var native = (full.match(/\(([^)]+)\)/) || [])[1] || full;
    return '<span class="label label-default kyc-lang-tag" title="' + esc(full) + '">' + esc(native) + '</span>';
  }
  function badge(s) { var m = STATUS[s] || [s, 'default']; return '<span class="label label-' + m[1] + '">' + esc(m[0]) + '</span>'; }

  var CHANNELS = { email: ['fa-envelope', 'Email'], sms: ['fa-commenting', 'SMS'], whatsapp: ['fa-whatsapp', 'WhatsApp'] };
  function channelIcons(map) {
    return Object.keys(CHANNELS).map(function (c) {
      var st = map && map[c];
      var cls = st === 'sent' ? 'kyc-ch-sent' : st === 'failed' ? 'kyc-ch-failed' : st === 'skipped' ? 'kyc-ch-skipped' : 'kyc-ch-none';
      var tip = CHANNELS[c][1] + ': ' + (st || 'not used');
      return '<i class="fa ' + CHANNELS[c][0] + ' kyc-ch ' + cls + '" title="' + esc(tip) + '"></i>';
    }).join(' ');
  }

  function toast(type, msg) {
    if (typeof window.alert_float === 'function') { window.alert_float(type, msg); } else { window.alert(msg); }
  }

  function fail(xhr) {
    var m = 'Something went wrong. Please try again.';
    try { m = JSON.parse(xhr.responseText).message || m; } catch (e) { /* non-JSON error page */ }
    return m;
  }

  /* ------------------------------------------------------ metrics + table */

  function loadStats() {
    if (!K.isDashboard) { return; }
    $.getJSON(K.base + '/stats').done(function (r) {
      $.each(r.stats, function (k, v) { $('[data-metric="' + k + '"]').text(v); });
    });
  }

  function actionButtons(r) {
    var b = '';
    var hasVideo = r.status === 'submitted' || r.status === 'approved' || r.status === 'rejected';
    b += '<button class="btn btn-default btn-xs" data-act="review" data-id="' + r.id + '" title="' + (hasVideo ? 'Play video / review' : 'Details') + '">' +
         '<i class="fa ' + (hasVideo ? 'fa-play' : 'fa-eye') + '"></i> ' + (hasVideo ? 'Review' : 'Details') + '</button> ';
    if (r.status === 'submitted' && K.can.review) {
      b += '<button class="btn btn-success btn-xs" data-act="review" data-intent="approve" data-id="' + r.id + '" title="Review, then approve"><i class="fa fa-check"></i></button> ';
      b += '<button class="btn btn-danger btn-xs" data-act="review" data-intent="reject" data-id="' + r.id + '" title="Review, then reject"><i class="fa fa-times"></i></button> ';
    }
    if (K.can.generate && r.status !== 'approved') {
      b += '<button class="btn btn-default btn-xs" data-act="resend" data-id="' + r.id + '" title="Resend link (issues a fresh link)"><i class="fa fa-repeat"></i></button>';
    }
    return b;
  }

  function loadList() {
    var tbody = table.find('tbody');
    $.getJSON(K.base + '/list_requests', { page: state.page, per_page: state.perPage, status: state.status, q: state.q })
      .done(function (r) {
        if (!r.rows.length) {
          tbody.html('<tr><td colspan="5" class="text-muted">No KYC requests found.</td></tr>');
        } else {
          tbody.html(r.rows.map(function (x) {
            return '<tr>' +
              '<td><strong>' + esc(x.customer_name) + '</strong> ' + langTag(x.script_language) + '<br><small class="text-muted">' + esc(x.customer_email || x.customer_phone || '—') + '</small></td>' +
              '<td>' + fmt(x.created_at) + '<br><small class="text-muted">expires ' + fmt(x.expires_at) + '</small></td>' +
              '<td>' + badge(x.status) + '</td>' +
              '<td>' + channelIcons(x.channels) + '</td>' +
              '<td class="text-right kyc-actions">' + actionButtons(x) + '</td></tr>';
          }).join(''));
        }
        pager(r);
      })
      .fail(function (xhr) { tbody.html('<tr><td colspan="5" class="text-danger">' + esc(fail(xhr)) + '</td></tr>'); });
  }

  function pager(r) {
    var pages = Math.max(1, Math.ceil(r.total / r.per_page));
    if (K.isDashboard || pages <= 1) { $('#kyc-pager').html(K.isDashboard ? '' : '<small class="text-muted">' + r.total + ' request(s)</small>'); return; }
    $('#kyc-pager').html(
      '<button class="btn btn-default btn-xs" data-page="' + (r.page - 1) + '"' + (r.page <= 1 ? ' disabled' : '') + '>&laquo; Prev</button> ' +
      '<small class="text-muted">Page ' + r.page + ' of ' + pages + ' · ' + r.total + ' request(s)</small> ' +
      '<button class="btn btn-default btn-xs" data-page="' + (r.page + 1) + '"' + (r.page >= pages ? ' disabled' : '') + '>Next &raquo;</button>');
  }

  function refreshAll() { loadStats(); loadList(); }

  $('#kyc-pager').on('click', '[data-page]', function () { state.page = parseInt($(this).data('page'), 10); loadList(); });
  $('#kyc-refresh').on('click', refreshAll);
  $('#kyc-filter-status').on('change', function () { state.status = $(this).val(); state.page = 1; loadList(); });
  var qTimer;
  $('#kyc-filter-q').on('input', function () {
    var v = $.trim($(this).val());
    clearTimeout(qTimer);
    qTimer = setTimeout(function () { state.q = v; state.page = 1; loadList(); }, 300);
  });

  table.on('click', '[data-act]', function () {
    var id = $(this).data('id');
    if ($(this).data('act') === 'review') { openReview(id, $(this).data('intent')); }
    else { resend(id, $(this)); }
  });

  /* -------------------------------------------------------------- resend */

  function resend(id, btn) {
    if (!window.confirm('Send this customer a fresh link? The previous link will stop working immediately.')) { return; }
    btn.prop('disabled', true);
    $.post(K.base + '/resend/' + id, {}, null, 'json')
      .done(function (r) { toast('success', r.message || 'Link re-sent.'); })
      .fail(function (xhr) { toast('danger', fail(xhr)); })
      .always(function () { btn.prop('disabled', false); refreshAll(); });
  }

  /* ------------------------------------------------------ generate-link UI */

  function validMobile(v) {
    var d = String(v || '').replace(/\D+/g, '').replace(/^(?:0|91)(?=\d{10}$)/, '');
    return /^[6-9]\d{9}$/.test(d) || (/^\s*\+/.test(v || '') && String(v).replace(/\D+/g, '').length >= 8);
  }

  function subjectType() { return $('input[name="kyc_subject_type"]:checked').val(); }

  /* ---- script language ------------------------------------------------
   * Mirrors Payplex_kyc_scripts::render()/formatDate()/bodyFor() so the preview is what the server
   * will store. The server re-renders from the template on submit; this is only a preview. */
  function scriptLang() { return $('#kyc-language').val() || 'en'; }

  function langDate(lang) {
    var m = (K.langMonths[lang] || K.langMonths.en)[K.today.m - 1];
    return K.today.d + ' ' + m + ' ' + K.today.y;
  }

  function bodyFor(opt, lang) {
    var own = lang === 'en' ? opt.data('body') : opt.data('body-' + lang);
    own = $.trim(own || '');
    return { text: own !== '' ? own : K.langDefaults[lang], fallback: own === '' };
  }

  function renderScript(body, name, lang) {
    return String(body)
      .replace(/\{customer_name\}/g, name)
      .replace(/\{company_name\}/g, K.company)
      .replace(/\{company\}/g, K.company)
      .replace(/\{date\}/g, langDate(lang));
  }

  function updatePreview() {
    var lang = scriptLang();
    var opt = $('#kyc-template option:selected');
    $('#kyc-preview-lang').text(K.languages[lang] || lang);
    $('#kyc-script-preview').attr('lang', lang);
    var note = $('#kyc-lang-note').hide();

    if (!chosen) {
      $('#kyc-script-preview').text('Select a customer to preview the script.');
    } else {
      var b = bodyFor(opt, lang);
      $('#kyc-script-preview').text(renderScript(b.text, chosen.name, lang));
      if (b.fallback && lang !== 'en') {
        note.text('This template has no ' + (K.languages[lang] || lang) + ' version, so the standard consent statement in that language is used.').show();
      }
    }
  }

  function updateChannelHints() {
    var hints = [];
    var map = { email: !!(chosen && chosen.email), sms: !!(chosen && validMobile(chosen.phone)), whatsapp: !!(chosen && validMobile(chosen.phone)) };
    $('#kyc-channels input').each(function () {
      var ok = !chosen || map[this.value];
      $(this).prop('disabled', !ok);
      if (!ok) { $(this).prop('checked', false); hints.push(this.value === 'email' ? 'no email on file' : 'no valid mobile number on file'); }
    });
    var uniq = hints.filter(function (h, i) { return hints.indexOf(h) === i; });
    $('#kyc-channel-hint').text(uniq.length ? 'Unavailable for this person: ' + uniq.join(', ') + '.' : '');
  }

  var searchTimer;
  function searchSubjects() {
    var q = $.trim($('#kyc-subject-q').val());
    $.getJSON(K.base + '/subjects', { type: subjectType(), q: q }).done(function (r) {
      var box = $('#kyc-subject-results');
      if (!r.items.length) { box.html('<div class="kyc-sr-empty">No matches.</div>'); return; }
      box.html(r.items.map(function (s, i) {
        return '<a href="#" class="kyc-sr" data-i="' + i + '"><strong>' + esc(s.name) + '</strong> <small class="text-muted">' +
               esc([s.email, s.phone].filter(Boolean).join(' · ') || 'no contact details') + '</small></a>';
      }).join('')).data('items', r.items);
    });
  }

  $('#kyc-open-generate').on('click', function () {
    chosen = null;
    $('#kyc-subject-chosen').hide().empty();
    $('#kyc-subject-q').val('').show();
    $('#kyc-subject-results').empty();
    $('#kyc-generate-result').empty();
    $('#kyc-expiry').val(K.ttlHours);
    $('#kyc-language').val('en');
    $('#kyc-channels input').prop('disabled', false).prop('checked', true);
    updatePreview(); updateChannelHints();
    $('#kyc-generate-modal').modal('show');
    searchSubjects();
  });

  $('#kyc-subject-q').on('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(searchSubjects, 250); });
  $('input[name="kyc_subject_type"]').on('change', function () { chosen = null; $('#kyc-subject-chosen').hide(); $('#kyc-subject-q').show(); updatePreview(); updateChannelHints(); searchSubjects(); });
  $('#kyc-subject-results').on('click', '.kyc-sr', function (e) {
    e.preventDefault();
    chosen = $('#kyc-subject-results').data('items')[$(this).data('i')];
    $('#kyc-subject-results').empty();
    $('#kyc-subject-q').hide();
    $('#kyc-subject-chosen').html('<strong>' + esc(chosen.name) + '</strong> <small class="text-muted">' + esc([chosen.email, chosen.phone].filter(Boolean).join(' · ')) +
      '</small> <a href="#" id="kyc-subject-clear" class="pull-right">change</a>').show();
    $('#kyc-channels input').prop('disabled', false).prop('checked', true);
    updatePreview(); updateChannelHints();
  });
  $(document).on('click', '#kyc-subject-clear', function (e) {
    e.preventDefault(); chosen = null; $('#kyc-subject-chosen').hide(); $('#kyc-subject-q').show().focus();
    updatePreview(); updateChannelHints(); searchSubjects();
  });
  $('.kyc-presets').on('click', 'a', function (e) { e.preventDefault(); $('#kyc-expiry').val($(this).data('hours')); });
  $('#kyc-template').on('change', updatePreview);
  $('#kyc-language').on('change', updatePreview);

  function submitGenerate(force) {
    var channels = $('#kyc-channels input:checked').map(function () { return this.value; }).get();
    if (!chosen) { $('#kyc-generate-result').html('<div class="alert alert-warning">Choose who to verify first.</div>'); return; }
    if (!channels.length) { $('#kyc-generate-result').html('<div class="alert alert-warning">Choose at least one channel.</div>'); return; }
    var btn = $('#kyc-generate-submit').prop('disabled', true);
    $.post(K.base + '/generate_link', {
      customer_type: subjectType(), customer_id: chosen.id, template_id: $('#kyc-template').val(), script_language: scriptLang(),
      expires_hours: $('#kyc-expiry').val(), channels: channels, force: force ? 1 : ''
    }, null, 'json')
      .done(function (r) {
        var rows = $.map(r.results, function (v, ch) {
          var cls = v.status === 'sent' ? 'success' : v.status === 'skipped' ? 'warning' : 'danger';
          return '<li class="text-' + cls + '"><strong>' + esc(CHANNELS[ch][1]) + '</strong>: ' + esc(v.status) + (v.error ? ' — ' + esc(v.error) : '') + '</li>';
        }).join('');
        $('#kyc-generate-result').html('<div class="alert alert-success"><strong>Request created.</strong><ul class="kyc-result-list">' + rows + '</ul></div>');
        refreshAll();
      })
      .fail(function (xhr) {
        var body = {}; try { body = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
        if (xhr.status === 409 && body.code === 'open_request_exists' && window.confirm(body.message + '\n\nCreate another anyway?')) { submitGenerate(true); return; }
        $('#kyc-generate-result').html('<div class="alert alert-danger">' + esc(body.message || fail(xhr)) + '</div>');
      })
      .always(function () { btn.prop('disabled', false); });
  }
  $('#kyc-generate-submit').on('click', function () { submitGenerate(false); });

  /* ---------------------------------------------------------- review modal */

  function openReview(id, intent) {
    reviewIntent = intent || null;
    $('#kyc-review-msg').empty();
    $.getJSON(K.base + '/detail/' + id).done(function (r) {
      current = r.request;
      renderReview(current);
      $('#kyc-review-modal').modal('show');
    }).fail(function (xhr) { toast('danger', fail(xhr)); });
  }

  function renderReview(q) {
    var v = q.video;
    // Player: audio + video controls, only if a recording exists AND the viewer may watch it.
    if (v && v.url) {
      $('#kyc-video-wrap').html('<video controls playsinline preload="metadata" controlsList="nodownload" src="' + esc(v.url) + '"></video>');
    } else if (v) {
      $('#kyc-video-wrap').html('<div class="kyc-novideo">You do not have permission to watch recordings.</div>');
    } else {
      $('#kyc-video-wrap').html('<div class="kyc-novideo"><i class="fa fa-clock-o"></i><br>No video submitted yet.</div>');
    }
    $('#kyc-video-meta').html(v
      ? '<small class="text-muted">' + esc(v.mime_type) + ' · ' + bytes(v.file_size) + (v.duration_sec != null ? ' · ' + v.duration_sec + 's' : '') +
        ' · uploaded ' + fmt(v.uploaded_at) + ' from ' + esc(v.uploaded_ip || '?') + '<br>SHA-256 <code>' + esc((v.sha256 || '').slice(0, 16)) + '…</code></small>'
      : '');

    $('#kyc-customer').html(
      '<dt>Name</dt><dd>' + esc(q.customer_name) + ' <span class="label label-default">' + esc(q.rel_type) + '</span></dd>' +
      '<dt>Email</dt><dd>' + esc(q.customer_email || '—') + '</dd>' +
      '<dt>Phone</dt><dd>' + esc(q.customer_phone || '—') + '</dd>' +
      '<dt>Status</dt><dd>' + badge(q.status) + '</dd>' +
      '<dt>Link sent</dt><dd>' + fmt(q.created_at) + ' (×' + q.send_count + ')</dd>' +
      '<dt>Expires</dt><dd>' + fmt(q.expires_at) + '</dd>');
    $('#kyc-script').text(q.dynamic_script).attr('lang', q.script_language || 'en');
    $('#kyc-script-lang').text((K.languages && K.languages[q.script_language]) || 'English');

    $('#kyc-notif-log').html(q.notifications.length ? q.notifications.map(function (n) {
      var cls = n.status === 'sent' ? 'success' : n.status === 'failed' ? 'danger' : 'muted';
      return '<li class="text-' + cls + '"><i class="fa ' + CHANNELS[n.channel][0] + '"></i> ' + esc(n.status) +
             (n.recipient ? ' → ' + esc(n.recipient) : '') + (n.error ? ' <small>(' + esc(n.error) + ')</small>' : '') + '</li>';
    }).join('') : '<li class="text-muted">Nothing sent yet.</li>');

    var reviewable = q.status === 'submitted' && K.can.review;
    $('#kyc-decision-block').toggle(reviewable);
    $('#kyc-review-actions').toggle(reviewable);
    $('#kyc-checklist input').prop('checked', false);
    $('#kyc-notes').val('');
    $('#kyc-decision-block').toggleClass('kyc-intent-approve', reviewIntent === 'approve').toggleClass('kyc-intent-reject', reviewIntent === 'reject');

    if (v && (q.status === 'approved' || q.status === 'rejected')) {
      var c = v.checklist || {};
      $('#kyc-decided').show().html('<h5 class="kyc-sec">Decision</h5><p>' + badge(q.status) + ' by <strong>' + esc(v.verified_by || 'unknown') + '</strong> on ' + fmt(v.verified_at) +
        '</p>' + (v.review_notes ? '<blockquote>' + esc(v.review_notes) + '</blockquote>' : '') +
        '<small class="text-muted">' + Object.keys(c).map(function (k) { return (c[k] ? '✔ ' : '✘ ') + k.replace(/_/g, ' '); }).join(' · ') + '</small>');
    } else { $('#kyc-decided').hide().empty(); }
  }

  // Stop playback when the modal closes — otherwise audio keeps playing invisibly.
  $('#kyc-review-modal').on('hidden.bs.modal', function () { $('#kyc-video-wrap').empty(); current = null; });

  function decide(decision) {
    if (!current) { return; }
    var data = { request_id: current.id, decision: decision, notes: $('#kyc-notes').val() };
    $('#kyc-checklist input').each(function () { if (this.checked) { data['check_' + $(this).data('check')] = 1; } });
    if (decision === 'reject' && !$.trim(data.notes)) { $('#kyc-review-msg').html('<div class="alert alert-warning">Add a reason before rejecting.</div>'); return; }
    if (decision === 'approve' && $('#kyc-checklist input:not(:checked)').length) { $('#kyc-review-msg').html('<div class="alert alert-warning">Tick every verification check before approving.</div>'); return; }
    if (!window.confirm((decision === 'approve' ? 'Approve' : 'Reject') + ' this KYC? This is recorded against your name.')) { return; }
    $('#kyc-approve, #kyc-reject').prop('disabled', true);
    $.post(K.base + '/review', data, null, 'json')
      .done(function () { $('#kyc-review-modal').modal('hide'); toast('success', 'KYC ' + (decision === 'approve' ? 'approved' : 'rejected') + '.'); refreshAll(); })
      .fail(function (xhr) { $('#kyc-review-msg').html('<div class="alert alert-danger">' + esc(fail(xhr)) + '</div>'); })
      .always(function () { $('#kyc-approve, #kyc-reject').prop('disabled', false); });
  }
  $('#kyc-approve').on('click', function () { decide('approve'); });
  $('#kyc-reject').on('click', function () { decide('reject'); });

  /* ---------------------------------------------------------------- boot */
  refreshAll();
  setInterval(function () { if (!$('.modal.in').length && !document.hidden) { refreshAll(); } }, 30000);   // near-live without hammering the server
})(window.jQuery);
