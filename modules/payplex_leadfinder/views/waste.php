<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Waste and suppression.
 *
 * WHAT THIS SCREEN DELIBERATELY DOES NOT SHOW
 * -------------------------------------------
 * A suppression key. Not truncated, not as a fingerprint, not "for support".
 * The keys are HMACs of a business's phone number and place id, and the only
 * person who benefits from seeing one is somebody checking whether a particular
 * business is on the list — which is precisely what the HMAC exists to prevent.
 *
 * The counts, the states and the pepper VERSION are shown, because those are
 * what an administrator needs to answer "is suppression working" and "can this
 * key version be retired yet".
 */
?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>

      <?php
      /*
       * The keyring banner is first because everything below it is meaningless
       * without one. A screen that listed suppressions while the module could
       * not read the keyring would be describing a protection that is not
       * currently running.
       */
      if (empty($keyring['loaded'])) { ?>
        <div class="alert alert-danger">
          <strong>The suppression keyring cannot be read.</strong>
          Reason: <code><?php echo html_escape((string) $keyring['reason']); ?></code>.
          New suppression records and the contact-detail purge are both refused until it is
          restored. Nothing falls back to a plain hash: a plain hash of a ten-digit mobile is
          recovered by exhaustive search in minutes, so a fallback would destroy the protection
          it appears to provide — silently.
        </div>
      <?php } else { ?>
        <div class="alert alert-info">
          Keyring loaded. Active version
          <strong><?php echo html_escape((string) $keyring['active_version']); ?></strong>,
          fingerprint <code><?php echo html_escape((string) $keyring['fingerprint']); ?></code>.
          The key itself is never displayed, logged or returned by any endpoint.
        </div>
      <?php } ?>
    </div></div>
  </div></div>

  <div class="row">
    <div class="col-md-4 col-sm-6">
      <div class="panel_s"><div class="panel-body">
        <h5 class="no-mtop">Purge queue</h5>
        <table class="table table-condensed">
          <tbody>
            <tr><td>Waiting</td><td class="text-right"><strong><?php echo (int) $purge_queue['pending']; ?></strong></td></tr>
            <tr><td>Due now</td><td class="text-right"><strong><?php echo (int) $purge_queue['due_now']; ?></strong></td></tr>
            <tr><td>Purged</td><td class="text-right"><?php echo (int) $purge_queue['purged']; ?></td></tr>
            <tr><td>Failed</td><td class="text-right">
              <?php if ((int) $purge_queue['failed'] > 0) { ?>
                <span class="label label-danger"><?php echo (int) $purge_queue['failed']; ?></span>
              <?php } else { echo '0'; } ?>
            </td></tr>
          </tbody>
        </table>
        <?php
        /*
         * A failed purge stays queued and says so. A queue that silently
         * dropped its failures would report an empty backlog while still
         * holding data it promised to destroy.
         */
        if ((int) $purge_queue['failed'] > 0) { ?>
          <p class="text-danger small">
            Failed rows stay queued and are retried. The usual cause is a missing suppression
            record — the purge refuses to clear contact details when nothing would be able to
            recognise that business again.
          </p>
        <?php } ?>
      </div></div>
    </div>

    <div class="col-md-4 col-sm-6">
      <div class="panel_s"><div class="panel-body">
        <h5 class="no-mtop">Suppression records by key version</h5>
        <?php if (empty($versions)) { ?>
          <p class="text-muted">None yet.</p>
        <?php } else { ?>
          <table class="table table-condensed">
            <tbody>
            <?php foreach ($versions as $v => $n) { ?>
              <tr>
                <td><code><?php echo html_escape((string) $v); ?></code></td>
                <td class="text-right"><?php echo (int) $n; ?></td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
          <p class="text-muted small">
            A key version cannot be retired while any record still references it. Re-keying moves
            records that still hold their source identifiers; records whose details were already
            purged can never be re-keyed and keep their original version for good.
          </p>
        <?php } ?>
      </div></div>
    </div>

    <div class="col-md-4 col-sm-12">
      <div class="panel_s"><div class="panel-body">
        <h5 class="no-mtop">Retention settings</h5>
        <table class="table table-condensed">
          <tbody>
            <tr>
              <td>Undo window</td>
              <td class="text-right"><?php echo (int) round($undo_window['undo_window_seconds'] / 60); ?> min</td>
            </tr>
            <tr>
              <td>Waste suppression</td>
              <td class="text-right">
                <?php if ((int) $undo_window['suppression_days'] > 0) { ?>
                  <?php echo (int) $undo_window['suppression_days']; ?> days
                <?php } else { ?>
                  <span class="label label-warning">not set</span>
                <?php } ?>
              </td>
            </tr>
            <tr>
              <td>Contact-detail retention</td>
              <td class="text-right">
                <?php if ((int) $undo_window['pii_retention_days'] > 0) { ?>
                  <?php echo (int) $undo_window['pii_retention_days']; ?> days
                <?php } else { ?>
                  <span class="label label-warning">not set</span>
                <?php } ?>
              </td>
            </tr>
          </tbody>
        </table>
        <?php
        /*
         * The RESOLVED decision, not the raw rows. A screen that shows "7 days"
         * beside a purge that is actually refusing to run is worse than one
         * that shows nothing: it answers the question somebody came to ask,
         * wrongly.
         */
        if (empty($retention['purge_allowed'])) { ?>
          <p class="text-danger small">
            <strong>The purge is not running.</strong>
            <?php echo html_escape((string) $retention['alert']['message']); ?>
          </p>
        <?php } else { ?>
          <p class="text-muted small">
            Contact details are purged <?php echo (int) $retention['pii_days']; ?> days after the
            decision; the suppression record is kept for
            <?php echo (int) $retention['suppression_days']; ?> days.
            Do Not Contact is not governed by either figure &mdash; it does not expire, and it is
            corrected only through a separate authorised administrative or legal revocation.
          </p>
        <?php } ?>
      </div></div>
    </div>
  </div>

  <?php if (!empty($is_admin)) { ?>
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h5 class="no-mtop">Retention periods</h5>

      <?php
      /*
       * Validated as a PAIR on the server. Individually sensible values can
       * still be an incoherent pair: contact details kept longer than the
       * suppression they belong to means holding a rejected business's number
       * after the reason for holding any record of them has expired.
       *
       * The HTML min/max below are a courtesy to the person typing. They are
       * not the control — the control is Leadfinder_retention_policy, which
       * refuses blanks, zero, negatives, decimals and oversized values, and
       * says which one it refused.
       */
      echo form_open(admin_url('payplex_leadfinder/finder/save_retention')); ?>
        <input type="hidden" name="confirm_retention_change" value="SET">
        <input type="hidden" name="return_to" value="waste">
        <div class="row">
          <?php foreach ($retention_fields as $key => $meta) { ?>
            <div class="col-md-5">
              <label class="control-label" for="<?php echo html_escape($key); ?>">
                <?php echo html_escape($meta['label']); ?>
              </label>
              <input type="number" class="form-control"
                     id="<?php echo html_escape($key); ?>"
                     name="<?php echo html_escape($key); ?>"
                     min="<?php echo (int) $meta['min']; ?>"
                     max="<?php echo (int) $meta['max']; ?>" step="1"
                     value="<?php echo html_escape((string) ($key === 'waste_pii_retention_days'
                             ? $retention['raw_pii'] : $retention['raw_suppression'])); ?>">
              <p class="text-muted small"><?php echo html_escape($meta['means']); ?></p>
            </div>
          <?php } ?>
        </div>
        <button type="submit" class="btn btn-info"
                onclick="return confirm('Change the retention periods? This governs when contact details are permanently destroyed. Do Not Contact records are unaffected.');">
          Save retention periods
        </button>
        <span class="text-muted small">
          Writes exactly these two settings. The suppression keyring is not read or changed.
        </span>
      <?php echo form_close(); ?>
    </div></div>
  </div></div>
  <?php } ?>

  <?php if (!empty($can_purge)) { ?>
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h5 class="no-mtop">Administrative actions</h5>
      <?php
      /*
       * POST with a typed confirmation, on top of the framework's CSRF layer.
       * Both, because a destructive maintenance job asked for by a forged
       * GET-shaped request is exactly what these two layers exist to prevent —
       * and this install has already needed CSRF disabled on one route in
       * another module, so "the framework will catch it" is not an assumption
       * to rest a purge on.
       */
      echo form_open(admin_url('payplex_leadfinder/finder/run_purge'), array('class' => 'form-inline mbot15')); ?>
        <input type="hidden" name="confirm_purge" value="PURGE">
        <button type="submit" class="btn btn-danger"
                onclick="return confirm('Run the contact-detail purge now? This permanently clears the contact details of prospects whose undo window has passed. Suppression records and Do Not Contact entries are kept.');">
          Run purge now
        </button>
        <span class="text-muted small">
          Same job as the scheduled run: same lock, same keyring check, same refusal when the
          retention period has not been set.
        </span>
      <?php echo form_close(); ?>

      <?php echo form_open(admin_url('payplex_leadfinder/finder/rekey_tombstones'), array('class' => 'form-inline')); ?>
        <label class="text-muted small" for="from_version">Re-key records from version</label>
        <input type="text" name="from_version" id="from_version" class="form-control input-sm"
               style="max-width:90px" placeholder="v1">
        <button type="submit" class="btn btn-default"
                onclick="return confirm('Re-key suppression records onto the active key version? This adds records under the new version and removes nothing.');">
          Re-key
        </button>
      <?php echo form_close(); ?>
    </div></div>
  </div></div>
  <?php } ?>

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h5 class="no-mtop">Wasted and suppressed prospects</h5>

      <?php if (empty($rows)) { ?>
        <div class="alert alert-info">Nothing has been marked as waste in your scope.</div>
      <?php } else { ?>
      <div class="table-responsive">
        <table class="table table-striped">
          <thead><tr>
            <th>Business</th><th>City</th><th class="hidden-xs">Reason</th>
            <th class="hidden-xs">Kind</th><th class="hidden-xs">Decided</th>
            <th>State</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r) { ?>
            <tr>
              <td>
                <?php echo html_escape((string) $r['business_name']); ?>
                <?php if (!empty($r['is_simulated'])) { ?>
                  <span class="label label-danger">SIMULATED</span>
                <?php } ?>
                <div class="visible-xs-block text-muted small">
                  <?php echo html_escape(isset($reasons[$r['waste_reason']])
                        ? $reasons[$r['waste_reason']]['label'] : (string) $r['waste_reason']); ?>
                </div>
              </td>
              <td><?php echo html_escape((string) $r['city']); ?></td>
              <td class="hidden-xs">
                <?php echo html_escape(isset($reasons[$r['waste_reason']])
                      ? $reasons[$r['waste_reason']]['label'] : (string) $r['waste_reason']); ?>
                <?php if (!empty($r['waste_notes'])) { ?>
                  <div class="text-muted small"><?php echo html_escape((string) $r['waste_notes']); ?></div>
                <?php } ?>
              </td>
              <td class="hidden-xs">
                <?php if ((string) $r['suppression_kind'] === 'dnc') { ?>
                  <span class="label label-danger">Do not contact</span>
                <?php } else { ?>
                  <span class="label label-default">Waste</span>
                <?php } ?>
              </td>
              <td class="hidden-xs">
                <?php echo $r['wasted_at'] ? html_escape(_dt(date('Y-m-d H:i:s', (int) $r['wasted_at']))) : '&mdash;'; ?>
                <div class="text-muted small">
                  <?php echo (int) $r['wasted_by'] > 0
                        ? html_escape(get_staff_full_name((int) $r['wasted_by'])) : 'system'; ?>
                </div>
              </td>
              <td>
                <?php if (!empty($r['pii_purged_at'])) { ?>
                  <span class="label label-default">Contact details cleared</span>
                <?php } elseif (!empty($r['undo_deadline']) && (int) $r['undo_deadline'] > time()) { ?>
                  <span class="label label-info">Undo available</span>
                <?php } else { ?>
                  <span class="text-muted small">Awaiting purge</span>
                <?php } ?>
              </td>
              <td class="text-right">
                <?php
                /*
                 * The undo button is rendered only while it would work, and the
                 * endpoint checks the same three things again — window, actor,
                 * purge state. Hiding a button is a courtesy; the refusal is in
                 * the model.
                 *
                 * There is no undo control at all for a Do Not Contact record.
                 * Reversing that is a deliberate administrative act, not
                 * something to reach by clicking the wrong row twice.
                 */
                if ((string) $r['suppression_kind'] !== 'dnc'
                    && empty($r['pii_purged_at'])
                    && !empty($r['undo_deadline']) && (int) $r['undo_deadline'] > time()) {
                    echo form_open(admin_url('payplex_leadfinder/finder/undo_waste/' . (int) $r['id']),
                                   array('style' => 'display:inline')); ?>
                    <input type="hidden" name="return_to" value="waste">
                    <button type="submit" class="btn btn-default btn-xs">Undo</button>
                  <?php echo form_close();
                } ?>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>

      <?php if (!empty($paging) && $paging['pages'] > 1) { $qs = $_GET; ?>
        <nav><ul class="pagination">
          <?php for ($i = 1; $i <= $paging['pages']; $i++) { $qs['page'] = $i; ?>
            <li class="<?php echo $i === $paging['page'] ? 'active' : ''; ?>">
              <a href="?<?php echo html_escape(http_build_query($qs)); ?>"><?php echo $i; ?></a>
            </li>
          <?php } ?>
        </ul></nav>
      <?php } ?>
      <?php } ?>

      <p class="text-muted small">
        Waste suppression stops a rejected business being re-offered by a later search. Do Not
        Contact never expires and is never removed by the purge — that record outlives the
        prospect it was made against, which is the whole reason it is stored separately.
      </p>
    </div></div>
  </div></div>

</div></div>
<?php init_tail(); ?>
</body></html>
