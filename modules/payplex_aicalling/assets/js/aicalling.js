/* Payplex AI Calling — front-end behaviour. Namespaced, no globals leaked.
   Depends on jQuery (bundled by Perfex). CSRF token passed from the view. */
(function ($) {
  'use strict';
  if (!$) { return; }

  function csrf() {
    return (window.PP_CSRF && window.PP_CSRF.name)
      ? { [window.PP_CSRF.name]: window.PP_CSRF.hash } : {};
  }

  function alertBox(el, type, msg) {
    el.html('<div class="pp-alert ' + (type === 'ok' ? 'ok' : 'err') + '">' + msg + '</div>');
  }

  // 10 digits, starting 6-9, after stripping spaces/hyphens/parens and an
  // optional leading 0, +91 or 91 trunk prefix. Mirrors the server-side
  // check in Payplex_call_limits::isIndianMobile() — kept in sync deliberately
  // so a rejection here is never a surprise once it reaches the server.
  function isIndianMobile(v) {
    var digits = String(v || '').replace(/\D+/g, '').replace(/^(?:0|91)(?=\d{10}$)/, '');
    return /^[6-9]\d{9}$/.test(digits);
  }

  // Health check on the dashboard.
  $(document).on('click', '#pp-health-btn', function () {
    var btn = $(this).prop('disabled', true).text('Checking…');
    $.getJSON(window.PP_HEALTH_URL).always(function () {
      btn.prop('disabled', false).text('Check health');
      window.location.reload();
    });
  });

  // Lead panel: toggle the call form.
  $(document).on('click', '.pp-call-now', function () {
    var panel = $(this).closest('.pp-lead-panel');
    panel.find('.pp-schedule-at-wrap').hide();
    panel.find('.pp-call-form').data('mode', 'now').slideDown(120);
  });
  $(document).on('click', '.pp-call-schedule', function () {
    var panel = $(this).closest('.pp-lead-panel');
    panel.find('.pp-schedule-at-wrap').show();
    panel.find('.pp-call-form').data('mode', 'schedule').slideDown(120);
  });
  $(document).on('click', '.pp-call-cancel', function () {
    $(this).closest('.pp-call-form').slideUp(120);
  });

  // Submit call.
  $(document).on('click', '.pp-call-submit', function () {
    var panel = $(this).closest('.pp-lead-panel');
    var form = panel.find('.pp-call-form');
    var leadId = panel.data('lead');
    var mode = form.data('mode');
    var result = form.find('.pp-call-result');
    var objective = $.trim(form.find('.pp-objective').val());

    if (!leadId || leadId <= 0) {
      alert('No lead is selected for this call.');
      return;
    }
    if (!objective) {
      alert('Please enter a call script/objective before placing the call.');
      return;
    }
    if (!isIndianMobile(panel.data('phone'))) {
      alert('This lead does not have a valid Indian mobile number (10 digits, starting with 6-9). '
        + 'Update the lead\'s phone number before calling.');
      return;
    }

    var btn = $(this).prop('disabled', true).text('Placing…');

    var data = $.extend({
      agent_id: form.find('.pp-agent').val(),
      language: form.find('.pp-language').val(),
      objective: objective,
      schedule_at: mode === 'schedule' ? form.find('.pp-schedule-at').val() : ''
    }, csrf());

    $.post(window.PP_START_URL + '/' + leadId, data, null, 'json')
      .done(function (res) {
        if (res && res.success) {
          alertBox(result, 'ok', 'Call ' + (data.schedule_at ? 'scheduled' : 'started') +
            ' (status: ' + (res.status || 'queued') + ').');
          setTimeout(loadHistory.bind(null, panel), 800);
        } else {
          alertBox(result, 'err', (res && res.message) || 'Could not place the call.');
        }
      })
      .fail(function (xhr) {
        alertBox(result, 'err', xhr.status === 403
          ? 'You are not authorized to place this call.'
          : 'Network error — the call was queued for retry.');
      })
      .always(function () { btn.prop('disabled', false).text('Place call'); });
  });

  function loadHistory(panel) {
    var box = panel.find('.pp-lead-history');
    if (!box.length) { return; }
    box.html('<em class="text-muted">Loading…</em>');
    // Server returns scoped history; here we just prompt a reload of the tab.
    box.html('<a href="' + window.PP_LEAD_HISTORY_URL + '?lead_id=' + panel.data('lead') +
      '">View this lead’s call history →</a>');
  }

  $(function () {
    $('.pp-lead-panel').each(function () { loadHistory($(this)); });
  });

  /* ------------------------------------------------------------------
   * Consent & DND controls.
   *
   * These three buttons shipped with no handler at all. The page published
   * PP_CONSENT_SET and PP_CSRF for a script that was never written, so
   * clicking "Toggle DND" — the control that puts a person on the
   * Do-Not-Disturb list — did nothing. The screen looked right and its
   * controls were dead.
   *
   * The consent ledger is append-only and each row carries BOTH the state
   * and the DND flag, so every write must send the value it is NOT changing.
   * Granting consent without sending the current DND would append a row with
   * dnd = 0 and silently take a person off the DND list.
   * ------------------------------------------------------------------ */

  function consentWrite(row, state, dnd, btn) {
    var data = $.extend({
      subject_type: row.data('type'),
      subject_id:   row.data('id'),
      channel:      row.data('channel'),
      state:        state,
      dnd:          dnd ? 1 : ''
    }, csrf());

    var label = btn.text();
    btn.prop('disabled', true).text('Saving…');

    $.post(window.PP_CONSENT_SET, data, null, 'json')
      .done(function (res) {
        if (res && res.success) {
          window.location.reload();   // re-read the ledger rather than guess
        } else {
          btn.prop('disabled', false).text(label);
          alert((res && res.message) ? res.message : 'The change was not saved.');
        }
      })
      .fail(function () {
        btn.prop('disabled', false).text(label);
        alert('The change could not be saved. Nothing was recorded.');
      });
  }

  // .closest('[data-type]') matches both a Consent-table <tr> and the lead panel's quick-toggle div.
  $(document).on('click', '.pp-consent-grant', function () {
    var row = $(this).closest('[data-type]');
    // preserve the DND flag: granting consent is not a reason to clear it
    consentWrite(row, 'granted', parseInt(row.data('dnd'), 10) === 1, $(this));
  });

  $(document).on('click', '.pp-consent-withdraw', function () {
    var row = $(this).closest('[data-type]');
    consentWrite(row, 'withdrawn', parseInt(row.data('dnd'), 10) === 1, $(this));
  });

  $(document).on('click', '.pp-consent-dnd', function () {
    var row = $(this).closest('[data-type]');
    var on  = parseInt(row.data('dnd'), 10) === 1;
    // preserve the consent state: toggling DND is not a consent decision
    consentWrite(row, String(row.data('state') || 'granted'), !on, $(this));
  });

  /* ------------------------------------------------------------------
   * Bulk consent/DND — the page used to only ever sync one lead per
   * click. Selecting rows and picking a bulk action sends one request
   * per (subject_type, channel) group to consent/bulk_set.
   * ------------------------------------------------------------------ */

  function selectedRows() {
    return $('.pp-consent-select:checked').closest('tr');
  }

  function refreshBulkBar() {
    var n = selectedRows().length;
    $('#pp-bulk-count').text(n + ' selected');
    $('.pp-bulk-action').prop('disabled', n === 0);
  }

  $(document).on('change', '#pp-select-all', function () {
    $('.pp-consent-select').prop('checked', $(this).is(':checked'));
    refreshBulkBar();
  });
  $(document).on('change', '.pp-consent-select', refreshBulkBar);

  $(document).on('click', '.pp-bulk-action', function () {
    var btn = $(this);
    var action = btn.data('action');
    var rows = selectedRows();
    if (!rows.length || !window.PP_CONSENT_BULK_SET) { return; }

    // group by (subject_type, channel): a bulk write must not mix ledgers.
    var groups = {};
    rows.each(function () {
      var r = $(this);
      var key = r.data('type') + '|' + r.data('channel');
      (groups[key] = groups[key] || { type: r.data('type'), channel: r.data('channel'), ids: [] })
        .ids.push(r.data('id'));
    });

    $('.pp-bulk-action').prop('disabled', true);
    var calls = Object.keys(groups).map(function (k) {
      var g = groups[k];
      return $.post(window.PP_CONSENT_BULK_SET, $.extend({
        action: action, subject_type: g.type, channel: g.channel, subject_ids: g.ids
      }, csrf()), null, 'json');
    });

    $.when.apply($, calls).always(function () { window.location.reload(); });
  });

  /* ------------------------------------------------------------------
   * Retry a failed call.
   *
   * Also shipped with no handler, and with no endpoint behind it either.
   * The retry goes through exactly the same gates as a first attempt —
   * consent, DND, calling hours, ownership, frequency, budget, disclosure
   * and the kill switch — so a retry can never reach a lead a first call
   * would have been refused for.
   * ------------------------------------------------------------------ */

  $(document).on('click', '.pp-retry', function () {
    var btn = $(this);
    var id  = btn.data('id');
    if (!window.PP_RETRY_URL) { return; }

    btn.prop('disabled', true).text('Retrying…');
    $.post(window.PP_RETRY_URL + '/' + id, csrf(), null, 'json')
      .done(function (res) {
        if (res && res.success) {
          window.location.reload();
        } else {
          btn.prop('disabled', false).text('Retry');
          alert((res && res.message) ? res.message : 'The call was not placed.');
        }
      })
      .fail(function () {
        btn.prop('disabled', false).text('Retry');
        alert('The retry could not be sent.');
      });
  });

})(window.jQuery);
