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
    var btn = $(this).prop('disabled', true).text('Placing…');

    var data = $.extend({
      agent_id: form.find('.pp-agent').val(),
      language: form.find('.pp-language').val(),
      objective: form.find('.pp-objective').val(),
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

  $(document).on('click', '.pp-consent-grant', function () {
    var row = $(this).closest('tr');
    // preserve the DND flag: granting consent is not a reason to clear it
    consentWrite(row, 'granted', parseInt(row.data('dnd'), 10) === 1, $(this));
  });

  $(document).on('click', '.pp-consent-withdraw', function () {
    var row = $(this).closest('tr');
    consentWrite(row, 'withdrawn', parseInt(row.data('dnd'), 10) === 1, $(this));
  });

  $(document).on('click', '.pp-consent-dnd', function () {
    var row = $(this).closest('tr');
    var on  = parseInt(row.data('dnd'), 10) === 1;
    // preserve the consent state: toggling DND is not a consent decision
    consentWrite(row, String(row.data('state') || 'granted'), !on, $(this));
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
