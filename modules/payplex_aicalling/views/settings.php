<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-8 col-md-offset-2">
  <div class="panel_s"><div class="panel-body">
    <h4 class="pp-title">AI Calling — Integration Settings</h4>
    <?php
    /*
     * The audit log's tamper-evidence was real but invisible: the chain was
     * written on every entry and nothing ever verified it. A control nobody can
     * see the result of is indistinguishable from one that is not running.
     */
    $c = isset($chain) ? $chain : null;
    ?>
    <?php if ($c === null): ?>
    <?php elseif (!empty($c['ok'])): ?>
      <p class="text-success" style="margin-bottom:14px;"><i class="fa fa-lock"></i>
        <strong>Audit log verified</strong> &mdash; <?php echo (int) $c['checked']; ?>
        entr<?php echo (int) $c['checked'] === 1 ? 'y' : 'ies'; ?> hash-chained and unaltered<?php
        echo empty($c['tail_proof']) ? '' : ', none missing from the end'; ?>.
        <?php if (!empty($c['legacy_format'])): ?>
          <br><small class="text-muted"><?php echo (int) $c['legacy_format']; ?>
          entr<?php echo (int) $c['legacy_format'] === 1 ? 'y was' : 'ies were'; ?> written before the
          hashing rule was tightened and <?php echo (int) $c['legacy_format'] === 1 ? 'is' : 'are'; ?>
          verified under the earlier format. New entries use the current one.</small>
        <?php endif; ?>
        <?php if (!empty($c['unchained'])): ?>
          <br><small class="text-muted"><?php echo (int) $c['unchained']; ?> earlier
          entr<?php echo (int) $c['unchained'] === 1 ? 'y was' : 'ies were'; ?> written before
          tamper-evidence existed and <?php echo (int) $c['unchained'] === 1 ? 'is' : 'are'; ?>
          not covered by the chain.</small>
        <?php endif; ?>
        <?php if (empty($c['tail_proof'])): ?>
          <br><small class="text-muted">The newest entries cannot yet be proved complete &mdash;
          the reference hash is recorded from the next entry onwards.</small>
        <?php endif; ?>
      </p>
    <?php else: ?>
      <div class="alert alert-danger">
        <i class="fa fa-exclamation-triangle"></i>
        <strong>Audit log integrity check FAILED.</strong><br>
        <small><?php echo html_escape($c['reason']); ?></small><br>
        <small>Treat call, consent and configuration records as unverified from that point
        and escalate before relying on them.</small>
      </div>
    <?php endif; ?>
    <p class="text-muted">Secrets are encrypted at rest. Provider (Twilio/Plivo/…) keys are <b>never</b> stored here — only the scoped Sonivo service credential.</p>
    <?php echo form_open(admin_url('payplex_aicalling/aicalling/settings'), ['id' => 'pp-settings-form']); ?>
      <div class="form-group"><label>Sonivo Base URL (HTTPS)</label>
        <input class="form-control" name="base_url" value="<?php echo html_escape(get_option('payplex_aicalling_base_url')); ?>" placeholder="https://calls.example.com"></div>
      <?php
        /*
         * Shows only WHETHER a secret is stored, never any part of its value or
         * length. Until this existed there was no way to tell a configured
         * install from one whose secrets had been silently blanked — which is
         * exactly what every settings save used to do, since "leave blank to
         * keep" wrote '' over the stored value.
         */
        /*
         * Ask whether the secret is USABLE, not whether something is stored.
         *
         * This read the raw option, which holds ciphertext and is therefore
         * non-empty whenever anything was ever saved — including a saved empty
         * string. The badge said "configured" while the webhook receiver
         * refused every event for want of a key, because the two were asking
         * different questions about the same setting.
         */
        $secretSet = function ($opt) { return Payplex_secret::isUsable($opt); };
        /*
         * "Configured" and "configured safely" are different questions, and the
         * badge only answered the first. read() falls back to the raw value
         * when decryption fails — right, because a legacy plaintext secret is
         * still a working secret and refusing it would break a live
         * integration — but the fallback is silent, so a credential sitting in
         * the options table in clear text showed exactly the same green badge
         * as an encrypted one.
         *
         * That was this install: the service JWT was readable while the two
         * secrets beside it were ciphertext, and nothing on this screen said so.
         */
        $secretBadge = function ($opt) use ($secretSet) {
            if (!$secretSet($opt)) {
                return '<span class="label label-danger">not set</span>';
            }
            if (Payplex_secret::atRestState($opt) === 'plaintext') {
                return '<span class="label label-warning">stored unencrypted</span>';
            }
            return '<span class="label label-success">configured</span>';
        };
      ?>
      <div class="form-group"><label>Service JWT</label> <?php echo $secretBadge('payplex_aicalling_service_jwt'); ?>
        <input class="form-control" type="password" name="service_jwt" placeholder="•••••• (leave blank to keep)"></div>
      <div class="form-group"><label>Request signing secret (Perfex → Sonivo)</label> <?php echo $secretBadge('payplex_aicalling_request_secret'); ?>
        <input class="form-control" type="password" name="request_secret" placeholder="•••••• (leave blank to keep)"></div>
      <div class="form-group"><label>Webhook secret (Sonivo → Perfex)</label> <?php echo $secretBadge('payplex_aicalling_webhook_secret'); ?>
        <input class="form-control" type="password" name="webhook_secret" placeholder="•••••• (leave blank to keep)"></div>
      <p class="text-muted"><small>
        Blank leaves the stored secret in place. The badge shows only whether one is stored —
        never its value or length.
      </small></p>
      <div class="row">
        <div class="col-sm-6 form-group"><label>Connect timeout (s)</label>
          <input class="form-control" name="timeout_connect" value="<?php echo html_escape(get_option('payplex_aicalling_timeout_connect') ?: 5); ?>"></div>
        <div class="col-sm-6 form-group"><label>Read timeout (s)</label>
          <input class="form-control" name="timeout_read" value="<?php echo html_escape(get_option('payplex_aicalling_timeout_read') ?: 15); ?>"></div>
      </div>
      <div class="form-group"><label>USD &rarr; INR rate <small class="text-muted">(for the dashboard balance display only; leave blank to show USD as reported)</small></label>
        <input class="form-control" name="usd_inr_rate" placeholder="e.g. 83" value="<?php echo html_escape(get_option('payplex_aicalling_usd_inr_rate')); ?>"></div>
      <hr>
      <h5 class="bold">Permitted calling window</h5>
      <?php
        $hs = get_option('payplex_aicalling_hours_start');
        $he = get_option('payplex_aicalling_hours_end');
        $fb = trim((string) get_option('payplex_aicalling_fallback_timezone'));
        $cov = isset($tz_coverage) ? $tz_coverage : null;
      ?>
      <p class="text-muted">
        Hours are measured in the <b>recipient&rsquo;s</b> local time, derived from each lead&rsquo;s
        country through the IANA timezone database &mdash; not in one company-wide timezone.
        A country that spans several zones cannot answer the question, and a lead with no country
        recorded gives nothing to derive from; in both cases the call is refused rather than placed
        against an assumed clock.
      </p>
      <div class="row">
        <div class="col-sm-3 form-group"><label>Start hour (inclusive)</label>
          <?php
          /*
           * The default comes from the controller, which reads it off the gate.
           * This field printed a literal 8 while Payplex_call_gates defaulted to
           * 9, so an unconfigured install displayed a window one hour wider than
           * the one actually enforced — and the displayed number is the one
           * anybody would have quoted. A settings screen showing a different
           * value from the code that decides is not a settings screen, it is a
           * rumour.
           */
          ?>
          <input class="form-control" name="hours_start" value="<?php echo html_escape($hs === '' || $hs === null ? $default_start_hour : $hs); ?>"></div>
        <div class="col-sm-3 form-group"><label>End hour (exclusive)</label>
          <input class="form-control" name="hours_end" value="<?php echo html_escape($he === '' || $he === null ? $default_end_hour : $he); ?>"></div>
        <div class="col-sm-6 form-group">
          <label>Fallback timezone <small class="text-muted">(used only when a lead&rsquo;s cannot be derived)</small></label>
          <select class="form-control" name="fallback_timezone">
            <option value="">&mdash; none: refuse instead of assuming &mdash;</option>
            <?php foreach (DateTimeZone::listIdentifiers() as $z): ?>
              <option value="<?php echo html_escape($z); ?>" <?php echo $z === $fb ? 'selected' : ''; ?>>
                <?php echo html_escape($z); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php if ($cov): ?>
        <?php if ((int) $cov['would_refuse'] > 0): ?>
          <div class="alert alert-warning" style="font-size:13px">
            <b><?php echo (int) $cov['would_refuse']; ?> lead(s) would have a call refused</b>
            because their local time cannot be determined:
            <?php echo (int) $cov['unknown']; ?> with no country recorded<?php
              if ((int) $cov['ambiguous'] > 0) {
                  echo ', ' . (int) $cov['ambiguous'] . ' in a country spanning several timezones ('
                     . html_escape(implode(', ', array_keys($cov['ambiguous_countries']))) . ')';
              }
            ?>.
            Set the country on those leads, or choose a fallback above and accept that those calls
            are judged against a clock that may not be theirs.
          </div>
        <?php else: ?>
          <div class="alert alert-success" style="font-size:13px">
            Every lead&rsquo;s local time can be determined<?php echo $cov['fallback'] ? ' or is covered by the fallback' : ''; ?>.
          </div>
        <?php endif; ?>
        <p class="text-muted" style="font-size:12px">
          <b>Leads by resolved timezone —</b>
          <?php
            $parts = array();
            foreach ($cov['by_zone'] as $z => $n) { $parts[] = html_escape($z) . ' (' . (int) $n . ')'; }
            echo $parts ? implode(', ', $parts) : 'none resolved';
          ?>.
          <?php if ($cov['fallback']): ?>
            Fallback in force: <code><?php echo html_escape($cov['fallback']); ?></code>.
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <hr>
      <h5 class="bold">Frequency &amp; suppression <small class="text-muted">(spec §4.4)</small></h5>
      <p class="text-muted">
        Leave a field blank to use the built-in conservative default, shown in the placeholder.
        A blank limit is never "no limit".
      </p>
      <div class="row">
        <div class="col-sm-3 form-group"><label>Max calls per lead per day</label>
          <input class="form-control" name="max_per_day" placeholder="default 3"
                 value="<?php echo html_escape(get_option('payplex_aicalling_max_per_day')); ?>"></div>
        <div class="col-sm-3 form-group"><label>Max per lead per week</label>
          <input class="form-control" name="max_per_week" placeholder="default 10"
                 value="<?php echo html_escape(get_option('payplex_aicalling_max_per_week')); ?>"></div>
        <div class="col-sm-3 form-group"><label>Cooldown (minutes)</label>
          <input class="form-control" name="cooldown_minutes" placeholder="default 240"
                 value="<?php echo html_escape(get_option('payplex_aicalling_cooldown_minutes')); ?>"></div>
        <div class="col-sm-3 form-group"><label>Max call duration (seconds)</label>
          <input class="form-control" name="max_duration_sec" placeholder="default 600"
                 value="<?php echo html_escape(get_option('payplex_aicalling_max_duration_sec')); ?>"></div>
      </div>
      <p class="text-muted"><small>
        Duplicate in-flight calls, numbers reported invalid on a previous attempt, and leads a
        person has taken over are suppressed automatically and need no configuration.
      </small></p>

      <hr>
      <h5 class="bold">Call classification <small class="text-muted">(what your backend's values mean)</small></h5>
      <p class="text-muted">
        These decide when a call is suppressed. They only work if they contain the words
        <em>your</em> telephony backend actually sends &mdash; a backend reporting
        <code>no_route</code> where this list says <code>unallocated</code> would keep redialling
        a dead number with nothing to show anything was wrong.
        Comma or line separated. <b>Clearing a box restores the built-in defaults</b>; there is
        deliberately no way to switch a suppression off by emptying it.
      </p>
      <?php
        $lists = [
          'in_flight_statuses' => [
            'label' => 'Statuses that mean a call is already under way',
            'help'  => 'Blocks a second, duplicate call to the same lead.',
            'def'   => Payplex_call_limits::defaultInFlightStatuses(),
          ],
          'invalid_dispositions' => [
            'label' => 'Dispositions that mean the number is unusable',
            'help'  => 'Stops the number being dialled again. Checked against disposition and failure_reason.',
            'def'   => Payplex_call_limits::defaultInvalidNumberDispositions(),
          ],
          'human_dispositions' => [
            'label' => 'Dispositions that mean a person took the lead over',
            'help'  => 'Stops the lead being auto-called after a human handoff.',
            'def'   => Payplex_call_limits::defaultHumanHandledDispositions(),
          ],
        ];
      ?>
      <?php foreach ($lists as $key => $l): ?>
        <?php
          $stored = (string) get_option('payplex_aicalling_' . $key);
          $resolved = Payplex_call_limits::resolveList($stored, $l['def']);
        ?>
        <div class="form-group">
          <label><?php echo html_escape($l['label']); ?></label>
          <?php if ($resolved['is_default']): ?>
            <span class="label label-info">using defaults</span>
          <?php endif; ?>
          <textarea class="form-control" rows="2" name="<?php echo html_escape($key); ?>"
                    placeholder="<?php echo html_escape(implode(', ', $l['def'])); ?>"><?php echo html_escape($stored); ?></textarea>
          <small class="text-muted">
            <?php echo html_escape($l['help']); ?>
            In force: <code><?php echo html_escape(implode(', ', $resolved['list'])); ?></code>
          </small>
        </div>
      <?php endforeach; ?>

      <?php
        $obs = isset($observed) ? $observed : ['statuses' => [], 'dispositions' => []];
        $unc = isset($unclassified) ? $unclassified : ['statuses' => [], 'dispositions' => []];
        $hasObs = $obs['statuses'] || $obs['dispositions'];
      ?>
      <?php if (!$hasObs): ?>
        <div class="alert alert-info" style="font-size:13px">
          No calls have been recorded yet, so there are no observed values to classify.
          Once calls run, the values your backend sends will be listed here.
        </div>
      <?php else: ?>
        <?php if ($unc['statuses'] || $unc['dispositions']): ?>
          <div class="alert alert-warning" style="font-size:13px">
            <b>Values received that nothing classifies.</b>
            These came back from your backend and match none of the lists above, so they trigger
            no suppression. If any of them mean &ldquo;already running&rdquo;, &ldquo;bad
            number&rdquo; or &ldquo;a human has this&rdquo;, add it to the matching list.
            <ul style="margin:6px 0 0">
              <?php foreach ($unc['statuses'] as $v => $c): ?>
                <li>status <code><?php echo html_escape($v); ?></code> &mdash; <?php echo (int) $c; ?> call(s)</li>
              <?php endforeach; ?>
              <?php foreach ($unc['dispositions'] as $v => $c): ?>
                <li>disposition <code><?php echo html_escape($v); ?></code> &mdash; <?php echo (int) $c; ?> call(s)</li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php else: ?>
          <div class="alert alert-success" style="font-size:13px">
            Every status and disposition received so far is either classified or a normal
            terminal state.
          </div>
        <?php endif; ?>
        <p class="text-muted" style="font-size:12px">
          <b>Observed so far —</b>
          statuses:
          <?php $p = []; foreach ($obs['statuses'] as $v => $c) { $p[] = html_escape($v) . ' (' . (int) $c . ')'; }
                echo $p ? implode(', ', $p) : 'none'; ?>;
          dispositions:
          <?php $p = []; foreach ($obs['dispositions'] as $v => $c) { $p[] = html_escape($v) . ' (' . (int) $c . ')'; }
                echo $p ? implode(', ', $p) : 'none'; ?>.
        </p>
      <?php endif; ?>

      <hr>
      <h5 class="bold">Calling budget</h5>
      <?php
        $num  = function ($v) { $v = trim((string) $v); return $v !== '' && is_numeric($v) && (float) $v > 0; };
        $acct = get_option('payplex_aicalling_account_budget');
        $agt  = get_option('payplex_aicalling_agent_budget');
        $budgetSet = $num($acct) || $num($agt);
      ?>
      <?php if (!$budgetSet): ?>
        <div class="alert alert-danger">
          <strong>No budget is set, so every call is refused.</strong>
          A budget is authorisation to spend, not a safety limit, so the module will not invent
          one. Setting <em>either</em> figure below authorises calling.
        </div>
      <?php elseif (!$num($acct) && $num($agt)): ?>
        <div class="alert alert-info">
          <strong>Authorised per agent only.</strong>
          Each agent may spend up to <?php echo html_escape($agt); ?> a month. There is no
          account-wide ceiling, so total exposure is that figure multiplied by however many agents
          place calls &mdash; add an account budget below if you want a hard overall cap.
        </div>
      <?php endif; ?>
      <div class="row" id="pp-budget-row">
        <div class="col-sm-6 form-group" id="pp-account-budget-group"><label>Account budget per month</label>
          <input class="form-control" id="pp-account-budget" name="account_budget" placeholder="required if no per-agent budget is set"
                 value="<?php echo html_escape(get_option('payplex_aicalling_account_budget')); ?>">
          <span class="help-block" style="display:none;color:#dc3545;">At least one of Account budget or Per-agent budget must be set — calling stays refused otherwise.</span>
        </div>
        <div class="col-sm-6 form-group" id="pp-agent-budget-group"><label>Per-agent budget per month <small class="text-muted">(optional if Account budget is set)</small></label>
          <input class="form-control" id="pp-agent-budget" name="agent_budget"
                 value="<?php echo html_escape(get_option('payplex_aicalling_agent_budget')); ?>"></div>
      </div>

      <hr>
      <h5 class="bold">Recording disclosure</h5>
      <?php
        $disclosure = (string) get_option('payplex_aicalling_recording_disclosure');
        $disclosureOk = trim($disclosure) !== ''
            && (string) get_option('payplex_aicalling_disclosure_confirmed') === '1';
      ?>
      <?php if (!$disclosureOk): ?>
        <div class="alert alert-danger">
          <strong>The recording disclosure is not configured and confirmed, so every call is refused.</strong>
          Write the wording that will be read to the person being called, then confirm it.
        </div>
      <?php endif; ?>
      <div class="form-group">
        <textarea class="form-control" rows="3" name="recording_disclosure"
                  placeholder="This call is recorded for quality and training purposes."><?php echo html_escape($disclosure); ?></textarea>
      </div>
      <div class="form-group"><label>
        <input type="checkbox" name="disclosure_confirmed" value="1" <?php echo $disclosureOk ? 'checked' : ''; ?>>
        I confirm this disclosure is approved and will be read on every call
      </label>
      <br><small class="text-muted">
        Changing the wording clears this confirmation — a confirmation carried over from text
        nobody has read is not a confirmation.
      </small></div>

      <hr>
      <div class="form-group"><label>
        <input type="checkbox" name="enabled" value="1" <?php echo get_option('payplex_aicalling_enabled') === '1' ? 'checked' : ''; ?>> Enable AI Calling (feature flag)
      </label></div>
      <div class="pp-webhook-hint">
        <b>Webhook URL to configure in Sonivo:</b>
        <code><?php echo site_url('payplex_aicalling/webhook'); ?></code>
      </div>
      <button class="btn btn-primary" type="submit">Save settings</button>
    <?php echo form_close(); ?>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?>
<script>
(function ($) {
  // At least one of account_budget / agent_budget is required — same rule the
  // backend already enforces (Payplex_call_limits::budget()), surfaced before submit
  // instead of only after reload via the red banner above.
  function budgetValid() {
    return $.trim($('#pp-account-budget').val()) !== '' || $.trim($('#pp-agent-budget').val()) !== '';
  }
  function showBudgetError(show) {
    $('#pp-account-budget-group, #pp-agent-budget-group').toggleClass('has-error', show);
    $('#pp-account-budget-group .help-block').toggle(show);
  }
  $('#pp-settings-form').on('submit', function (e) {
    if (!budgetValid()) {
      e.preventDefault();
      showBudgetError(true);
      $('#pp-account-budget').focus();
    }
  });
  $('#pp-account-budget, #pp-agent-budget').on('input', function () {
    if (budgetValid()) { showBudgetError(false); }
  });
})(jQuery);
</script>
</body></html>
