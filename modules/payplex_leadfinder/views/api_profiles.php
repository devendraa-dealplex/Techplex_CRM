<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>
      <div class="alert alert-info">
        API keys are encrypted at rest and are never sent to the browser. The
        field below is always blank: leaving it blank keeps the stored key,
        and entering a value replaces it. There is no screen anywhere in this
        module that displays a key.
      </div>

      <?php if (!empty($profiles)) { ?>
      <div class="table-responsive">
      <table class="table table-striped">
        <thead><tr>
          <th>Connection</th><th>Project</th><th>Key</th><th>Fingerprint</th>
          <th>Active</th><th>Search (month)</th><th>Detail (month)</th><th>Today</th><th>Staff</th>
        </tr></thead>
        <tbody>
        <?php foreach ($profiles as $p) { ?>
          <tr>
            <td><?php echo html_escape($p['name']); ?></td>
            <td><?php echo html_escape((string) $p['gcp_project']); ?></td>
            <td>
              <?php if ($p['key_state'] === 'absent') { ?>
                <span class="text-danger">not configured</span>
              <?php } elseif ($p['key_state'] === 'unreadable') { ?>
                <span class="text-danger">stored but not decryptable</span>
              <?php } else { ?>
                <code><?php echo html_escape($p['key_masked']); ?></code>
              <?php } ?>
            </td>
            <td><code><?php echo html_escape($p['key_fingerprint']); ?></code></td>
            <td><?php echo ((int) $p['active'] === 1) ? 'yes' : 'no'; ?></td>
            <td><?php echo (int) $p['usage']['search_month']; ?>
                / <?php echo (int) $p['monthly_search_limit']; ?></td>
            <td><?php echo (int) $p['usage']['detail_month']; ?>
                / <?php echo (int) $p['monthly_detail_limit']; ?></td>
            <td><?php echo (int) $p['usage']['today']; ?>
                / <?php echo (int) $p['daily_usage_limit']; ?></td>
            <td><?php echo count($p['staff_ids']); ?></td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
      </div>
      <?php } else { ?>
        <div class="alert alert-warning">No connection profiles yet.</div>
      <?php } ?>

      <hr />
      <h5>Add or update a connection</h5>
      <?php echo form_open(admin_url('payplex_leadfinder/finder/api_profile_save')); ?>
        <input type="hidden" name="id" value="0" />
        <div class="row">
          <div class="col-md-4"><?php echo render_input('name', 'Connection name'); ?></div>
          <div class="col-md-4"><?php echo render_input('gcp_project', 'Google Cloud project'); ?></div>
          <div class="col-md-4"><?php echo render_input('billing_label', 'Billing-account label'); ?></div>
        </div>
        <div class="row">
          <div class="col-md-4">
            <?php /* type=password and autocomplete off: the browser must not
                     offer to remember it, and it must never render a value. */ ?>
            <?php echo render_input('api_key', 'Google API key (leave blank to keep the stored key)',
                                    '', 'password', array('autocomplete' => 'new-password')); ?>
          </div>
          <div class="col-md-2"><?php echo render_input('monthly_search_limit', 'Monthly search limit', '0', 'number'); ?></div>
          <div class="col-md-2"><?php echo render_input('monthly_detail_limit', 'Monthly detail limit', '0', 'number'); ?></div>
          <div class="col-md-2"><?php echo render_input('daily_usage_limit', 'Daily usage limit', '0', 'number'); ?></div>
          <div class="col-md-2"><?php echo render_input('expires_on', 'Expiry / rotation date', '', 'date'); ?></div>
          <div class="col-md-2"><?php echo render_input('effective_from', 'Effective from', '', 'date'); ?></div>
          <div class="col-md-2"><?php echo render_input('per_staff_daily_limit', 'Per-employee daily limit', '0', 'number'); ?></div>
        </div>
        <div class="row">
          <div class="col-md-8"><?php echo render_textarea('admin_notes', 'Admin notes'); ?></div>
          <div class="col-md-4">
            <div class="checkbox"><label>
              <input type="checkbox" name="active" value="1" /> Active
            </label></div>
          </div>
        </div>
        <button type="submit" class="btn btn-info">Save connection</button>
      <?php echo form_close(); ?>
    </div></div>
  </div></div>

  <?php
  /*
   * The manual retention control.
   *
   * Admin-only, POST-only, and carrying its own confirmation token on top of
   * the framework's CSRF protection — a maintenance job that clears data
   * permanently should not be one forged GET away, and this install has
   * already had to exempt one route from CSRF for a webhook.
   *
   * It is placed on the connections screen rather than the search screen
   * because this is the administrator's page: the people who search must never
   * be the people who can trigger a purge.
   */
  ?>
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-margin">Coordinate retention</h4>
      <hr class="hr-panel-heading" />
      <p class="help-block">
        Google's latitude and longitude are cleared <?php echo (int) $coords_max_days; ?>
        calendar days after they were fetched. The Place ID is kept indefinitely, and
        everything an employee verified on the call is kept — only the two numbers go.
        The sweep runs on the CRM cron; this button runs it now.
      </p>

      <?php if (!empty($retention_runs)) { ?>
        <table class="table table-condensed">
          <thead><tr><th>Started</th><th>Examined</th><th>Cleared</th><th>Status</th><th>How</th></tr></thead>
          <tbody>
          <?php foreach ($retention_runs as $r) { ?>
            <tr>
              <td><?php echo _dt(date('Y-m-d H:i:s', (int) $r['started_at'])); ?></td>
              <td><?php echo (int) $r['examined']; ?></td>
              <td><?php echo (int) $r['purged']; ?></td>
              <td>
                <span class="label label-<?php echo $r['status'] === 'ok' ? 'success' : 'danger'; ?>">
                  <?php echo html_escape($r['status']); ?>
                </span>
                <?php if (!empty($r['error'])) { ?>
                  <br /><small class="text-muted"><?php echo html_escape($r['error']); ?></small>
                <?php } ?>
              </td>
              <td><?php echo html_escape($r['mode']); ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      <?php } else { ?>
        <div class="alert alert-warning">
          The retention sweep has never run. Coordinates fetched more than
          <?php echo (int) $coords_max_days; ?> days ago are still stored.
        </div>
      <?php } ?>

      <?php if (!empty($expired_waiting)) { ?>
        <div class="alert alert-danger">
          <strong><?php echo (int) $expired_waiting; ?></strong> prospect(s) are past the
          retention window right now and still hold coordinates.
        </div>
      <?php } ?>

      <?php echo form_open(admin_url('payplex_leadfinder/finder/run_retention')); ?>
        <input type="hidden" name="confirm_retention" value="RUN" />
        <button type="submit" class="btn btn-danger"
                onclick="return confirm('Clear expired Google coordinates now? The Place ID and all verified fields are kept.');">
          Run retention cleanup
        </button>
      <?php echo form_close(); ?>
    </div></div>
  </div></div>

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop">Browser map key</h4>

      <?php
      /*
       * TWO KEYS, AND THEY ARE NOT INTERCHANGEABLE.
       *
       * The server key above calls Places from PHP: restricted by IP, enabled
       * for Places only, encrypted at rest, never in an HTTP response.
       *
       * This one loads the Maps JavaScript API, which works by putting the key
       * in a <script src> tag — so it IS in the page source on the search
       * screen, and no amount of care changes that. Its protection is a
       * different restriction: HTTP referrer pinned to this host, Maps
       * JavaScript API only, and its own billing budget, so that a key scraped
       * off the page cannot spend the Places allowance.
       *
       * There is no fallback between them and none to the Perfex core key.
       */
      ?>
      <div class="alert alert-info">
        <strong>These are two different keys and must stay that way.</strong><br>
        Server Places key — restrict by <code><?php echo html_escape($map_rule_server); ?></code>,
        enable Places API (New) only. Never appears in a page.<br>
        Browser map key — restrict by <code><?php echo html_escape($map_rule_browser); ?></code>
        to this exact hostname, enable Maps JavaScript API only, give it its own budget.
        This one is visible in the page source of the search screen, by design of the API.
      </div>

      <p>
        Status:
        <?php if (!empty($map_key_status['configured'])) { ?>
          <span class="label label-success">configured</span>
          fingerprint <code><?php echo html_escape((string) $map_key_status['fingerprint']); ?></code>
          <?php if (!empty($map_key_status['set_at'])) { ?>
            &middot; set <?php echo html_escape(_dt(date('Y-m-d H:i:s', (int) $map_key_status['set_at']))); ?>
            <?php if ((int) $map_key_status['set_by'] > 0) { ?>
              by <?php echo html_escape(get_staff_full_name((int) $map_key_status['set_by'])); ?>
            <?php } ?>
          <?php } ?>
        <?php } else { ?>
          <span class="label label-warning">not configured</span>
          &mdash; the search screen lists results and every action works; only the map is missing.
        <?php } ?>
      </p>

      <?php if (!empty($map_key_status['referrers'])) { ?>
        <p class="text-muted small">
          Intended referrer restriction, as recorded by the administrator:
          <code><?php echo html_escape((string) $map_key_status['referrers']); ?></code>.
          This is a note, not an enforcement point &mdash; only Google can enforce a referrer
          restriction, and a field here that looked like enforcement would be worse than none.
        </p>
      <?php } ?>

      <?php if ((int) $map_key_status['profile_id'] > 0) {
          echo form_open(admin_url('payplex_leadfinder/finder/save_map_key/'
                                   . (int) $map_key_status['profile_id'])); ?>
        <div class="row">
          <div class="col-md-6">
            <label class="control-label" for="browser_map_key">Browser map key</label>
            <input type="password" name="browser_map_key" id="browser_map_key" class="form-control"
                   autocomplete="off" placeholder="Leave blank to keep the stored key">
            <p class="text-muted small">
              Blank means keep. The key is never displayed back, never logged and never returned
              by any endpoint &mdash; only its fingerprint is shown.
            </p>
          </div>
          <div class="col-md-6">
            <label class="control-label" for="browser_map_referrers">Referrer restriction (note)</label>
            <input type="text" name="browser_map_referrers" id="browser_map_referrers"
                   class="form-control" maxlength="500"
                   value="<?php echo html_escape((string) $map_key_status['referrers']); ?>">
          </div>
        </div>
        <button type="submit" class="btn btn-info mtop15">Save map key</button>
        <?php echo form_close();
      } else { ?>
        <div class="alert alert-warning">
          Create an API connection above first. The map key is stored against a connection so
          that both credentials for one Google project live together.
        </div>
      <?php } ?>
    </div></div>
  </div></div>

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop">Suppression keyring and waste purge</h4>

      <?php if (empty($keyring['loaded'])) { ?>
        <div class="alert alert-danger">
          <strong>The suppression keyring cannot be read.</strong>
          Reason: <code><?php echo html_escape((string) $keyring['reason']); ?></code>.
          New suppression records and the contact-detail purge are both refused until it is
          restored. There is no fallback to a plain hash &mdash; a plain hash of a ten-digit
          mobile number is recovered by exhaustive search in minutes, so the fallback would
          quietly destroy the protection it appears to provide.
        </div>
      <?php } else { ?>
        <p>
          Keyring loaded &middot; active version
          <strong><?php echo html_escape((string) $keyring['active_version']); ?></strong>
          &middot; fingerprint <code><?php echo html_escape((string) $keyring['fingerprint']); ?></code>.
          The key itself is never displayed or logged.
        </p>
      <?php } ?>

      <p>
        Purge queue:
        <strong><?php echo (int) $purge_queue['pending']; ?></strong> waiting,
        <strong><?php echo (int) $purge_queue['due_now']; ?></strong> due now,
        <?php echo (int) $purge_queue['purged']; ?> completed<?php
        if ((int) $purge_queue['failed'] > 0) { ?>,
          <span class="text-danger"><?php echo (int) $purge_queue['failed']; ?> failed</span><?php
        } ?>.
        <a href="<?php echo admin_url('payplex_leadfinder/finder/waste_list'); ?>">Open waste and suppression</a>
      </p>

      <?php echo form_open(admin_url('payplex_leadfinder/finder/run_purge')); ?>
        <input type="hidden" name="confirm_purge" value="PURGE" />
        <button type="submit" class="btn btn-danger"
                onclick="return confirm('Run the contact-detail purge now? This permanently clears the contact details of prospects whose undo window has passed. Suppression records and Do Not Contact entries are kept.');">
          Run waste purge
        </button>
      <?php echo form_close(); ?>
    </div></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
