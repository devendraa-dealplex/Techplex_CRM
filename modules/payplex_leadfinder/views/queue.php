<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>
      <?php if (!empty($simulated_mode)) { ?>
        <div class="alert alert-danger">
          <strong>Simulated mode is on.</strong>
          <?php echo html_escape(Leadfinder_transport::bannerText()); ?>
        </div>
      <?php } ?>

      <p class="text-muted">
        Scope: <strong><?php echo html_escape($band); ?></strong>.
        Nothing here is a CRM lead. A prospect becomes a lead only after
        verification and the conversion gate.
      </p>

      <form method="get" class="mbot15">
        <select name="status" class="form-control" style="max-width:240px;display:inline-block;">
          <option value="">All statuses</option>
          <?php foreach ($statuses as $s) { ?>
            <option value="<?php echo html_escape($s); ?>"
              <?php echo (isset($_GET['status']) && $_GET['status'] === $s) ? 'selected' : ''; ?>>
              <?php echo html_escape(Leadfinder_status::label($s)); ?>
            </option>
          <?php } ?>
        </select>
        <input type="text" name="q" class="form-control" style="max-width:240px;display:inline-block;"
               placeholder="Business, city or PIN"
               value="<?php echo html_escape((string) (isset($_GET['q']) ? $_GET['q'] : '')); ?>">
        <select name="dupe_state" class="form-control" style="max-width:200px;display:inline-block;">
          <option value="">Any duplicate state</option>
          <?php $verdicts = Leadfinder_dupe::allVerdicts(); ?>
          <?php foreach ($verdicts as $v) { ?>
            <option value="<?php echo html_escape($v); ?>"
              <?php echo (isset($_GET['dupe_state']) && $_GET['dupe_state'] === $v) ? 'selected' : ''; ?>>
              <?php echo html_escape(str_replace('_', ' ', $v)); ?>
            </option>
          <?php } ?>
        </select>
        <button type="submit" class="btn btn-default">Filter</button>
      </form>

      <?php if (empty($rows)) { ?>
        <div class="alert alert-info">
          No prospects in your queue. Run a search to add some.
        </div>
      <?php } else { ?>
      <?php
      /*
       * The bulk form wraps the table so the checkboxes belong to it. Every id
       * it submits is still authorised one at a time in the model — this form
       * decides which rows are OFFERED, never which rows may be changed.
       */
      echo form_open(admin_url('payplex_leadfinder/finder/bulk')); ?>
      <div class="table-responsive">
      <table class="table table-striped">
        <thead><tr>
          <th style="width:24px;"></th>
          <th>Business</th>
          <th class="hidden-xs">City</th>
          <th class="hidden-xs">Status</th>
          <th class="hidden-xs hidden-sm">Owner</th>
          <th>Phone</th>
          <th class="hidden-xs hidden-sm">Email</th>
          <th class="hidden-xs">Duplicate</th>
          <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r) { ?>
          <tr>
            <td>
              <input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>">
            </td>
            <td>
              <?php echo html_escape($r['business_name']); ?>
              <?php if (!empty($r['is_simulated'])) { ?>
                <span class="label label-danger">SIMULATED &mdash; do not call</span>
              <?php } ?>
              <?php if (isset($saved[(int) $r['id']])) { ?>
                <span class="label label-success" title="On your list">Saved</span>
              <?php } ?>
              <?php
              /*
               * The advisory flag. It annotates and never removes: an AI or
               * heuristic score may put this badge on a row, and the row is
               * still worked normally. Only a deterministic rule or a person
               * writes a waste decision.
               */
              if (!empty($r['review_flag'])) { ?>
                <span class="label label-warning">Review Required &mdash; Possible Waste</span>
              <?php } ?>
              <?php /* Narrow screens lose the City and Status columns, so the
                        two facts an employee needs to tell rows apart move here. */ ?>
              <div class="visible-xs-block text-muted small">
                <?php echo html_escape((string) $r['city']); ?>
                &middot; <?php echo html_escape(Leadfinder_status::label($r['status'])); ?>
              </div>
            </td>
            <td class="hidden-xs"><?php echo html_escape((string) $r['city']); ?></td>
            <td class="hidden-xs"><?php echo html_escape(Leadfinder_status::label($r['status'])); ?></td>
            <td class="hidden-xs hidden-sm"><?php echo ((int) $r['assigned_staff'] > 0)
                  ? html_escape(get_staff_full_name($r['assigned_staff'])) : '&mdash;'; ?></td>
            <td>
              <?php /* Masked in the list. The full number is on the prospect
                        itself, where opening it is an auditable act. */ ?>
              <?php echo !empty($r['phone_e164'])
                    ? html_escape(Payplex_phone::mask($r['phone_e164'])) : '&mdash;'; ?>
            </td>
            <td class="hidden-xs hidden-sm">
              <?php
              /*
               * An empty Email cell would read as "Google had none for this
               * business", which is a claim about the business. The Places API
               * has no email field at all, so the cell says where an email can
               * come from instead of leaving a blank to be misread.
               */
              echo !empty($r['verified_email'])
                   ? html_escape($r['verified_email'])
                   : '<span class="text-muted" title="'
                     . html_escape(Leadfinder_places::emailSourceNote())
                     . '">not from Google</span>'; ?>
            </td>
            <td class="hidden-xs">
              <?php echo html_escape((string) $r['dupe_state']); ?>
              <?php
              /*
               * A "possible duplicate" badge with nothing behind it is a warning
               * an employee cannot act on. When the classifier recorded what it
               * matched, say so.
               */
              if (!empty($r['dupe_matched_id'])) { ?>
                <br><span class="text-muted small">
                  matched #<?php echo (int) $r['dupe_matched_id']; ?>
                  <?php if (!empty($r['dupe_keys'])) { ?>
                    on <?php echo html_escape(str_replace(',', ', ', (string) $r['dupe_keys'])); ?>
                  <?php } ?>
                </span>
              <?php } ?>
            </td>
            <td>
              <?php if ((int) $r['claimed_by'] === 0) { ?>
                <a class="btn btn-default btn-xs"
                   href="<?php echo admin_url('payplex_leadfinder/finder/claim/' . (int) $r['id']); ?>">Claim</a>
              <?php } else { ?>
                <a class="btn btn-default btn-xs"
                   href="<?php echo admin_url('payplex_leadfinder/finder/release/' . (int) $r['id']); ?>">Release</a>
              <?php } ?>

              <?php
              /*
               * A POST, not a link. A detail call bills at the higher contact
               * SKU, and a GET that spends money is a GET that a prefetching
               * browser or a crawler can spend.
               */
              if (!empty($can_fetch_details) && empty($r['details_fetched_at'])
                  && (int) $r['claimed_by'] > 0) { ?>
                <?php echo form_open(admin_url('payplex_leadfinder/finder/fetch_details/' . (int) $r['id']),
                                     array('style' => 'display:inline')); ?>
                  <button type="submit" class="btn btn-default btn-xs"
                          title="Fetches phone and website from Google. This costs one detail call.">
                    Fetch details
                  </button>
                <?php echo form_close(); ?>
              <?php } elseif (!empty($r['details_fetched_at'])) { ?>
                <span class="text-muted small">details held</span>
              <?php } ?>

              <?php
              /*
               * SAVE. A bookmark, not a claim: several employees may save the
               * same prospect, because two people shortlisting one business is
               * not a conflict. It writes to this module's shortlist table and
               * creates no CRM lead — the only path into tblleads is an
               * approved conversion, which needs verification evidence, a
               * submission and a second person.
               */
              if (!isset($saved[(int) $r['id']])) {
                  echo form_open(admin_url('payplex_leadfinder/finder/save/' . (int) $r['id']),
                                 array('style' => 'display:inline')); ?>
                <button type="submit" class="btn btn-default btn-xs" title="Add to your list. This does not create a CRM lead.">Save</button>
                <?php echo form_close();
              } else {
                  echo form_open(admin_url('payplex_leadfinder/finder/unsave/' . (int) $r['id']),
                                 array('style' => 'display:inline')); ?>
                <button type="submit" class="btn btn-default btn-xs">Unsave</button>
                <?php echo form_close();
              } ?>

              <?php
              /*
               * WASTE. The reason is a select, not free text, and the list is
               * the nine the specification names. Notes become mandatory for
               * "Other" — enforced server-side, because a `required` attribute
               * is a convenience for the person typing and nothing at all to a
               * request that skips the form.
               *
               * The control is rendered only for somebody holding
               * `leadfinder_verify`; the endpoint checks it again.
               */
              if (!empty($can_verify) && empty($r['wasted_at'])) {
                  echo form_open(admin_url('payplex_leadfinder/finder/waste/' . (int) $r['id']),
                                 array('class' => 'lf-waste-form', 'style' => 'display:inline-block')); ?>
                <input type="hidden" name="confirm_waste" value="WASTE">
                <select name="reason" class="form-control input-sm" style="width:auto;display:inline-block">
                  <option value="">Waste reason&hellip;</option>
                  <?php foreach ($reasons as $key => $meta) { ?>
                    <option value="<?php echo html_escape($key); ?>"
                            data-notes="<?php echo !empty($meta['notes_required']) ? '1' : '0'; ?>">
                      <?php echo html_escape($meta['label']); ?>
                    </option>
                  <?php } ?>
                </select>
                <input type="text" name="notes" class="form-control input-sm" style="width:130px;display:inline-block"
                       maxlength="500" placeholder="Notes (required for Other)">
                <button type="submit" class="btn btn-warning btn-xs"
                        onclick="return confirm('Mark this prospect as waste? It leaves the active queue, cannot be converted, and its contact details are scheduled for deletion after the undo window. You can undo this for <?php echo (int) round($undo_window / 60); ?> minutes.');">
                  Waste
                </button>
                <?php echo form_close();
              } ?>

              <?php
              /*
               * DO NOT CONTACT. Separate from waste on purpose: it never
               * expires, it survives the purge and a key rotation, and it is
               * never deleted by the waste sweep. It asks how the request
               * reached us and when, because that is the record somebody will
               * be asked to produce.
               */
              if (!empty($can_verify) && empty($r['wasted_at'])) {
                  echo form_open(admin_url('payplex_leadfinder/finder/mark_dnc/' . (int) $r['id']),
                                 array('style' => 'display:inline-block')); ?>
                <input type="hidden" name="confirm_dnc" value="DNC">
                <select name="channel" class="form-control input-sm" style="width:auto;display:inline-block">
                  <option value="phone_call">Asked by phone</option>
                  <option value="email">Asked by email</option>
                  <option value="letter">Asked by letter</option>
                  <option value="in_person">Asked in person</option>
                  <option value="regulator">Regulator</option>
                  <option value="other">Other</option>
                </select>
                <input type="date" name="requested_on" class="form-control input-sm"
                       style="width:auto;display:inline-block"
                       value="<?php echo html_escape(date('Y-m-d')); ?>">
                <button type="submit" class="btn btn-danger btn-xs"
                        onclick="return confirm('Record that this business asked not to be contacted? This is permanent: it never expires, it survives the purge, and it cannot be undone from this screen.');">
                  Do not contact
                </button>
                <?php echo form_close();
              } ?>

              <?php
              /* UNDO. Rendered only while it would actually work; the endpoint
                 re-checks the window, the actor and whether the contact details
                 have already been cleared. */
              if (!empty($r['wasted_at']) && empty($r['undone_at']) && empty($r['pii_purged_at'])
                  && (string) $r['suppression_kind'] !== 'dnc'
                  && (int) $r['undo_deadline'] > time()) {
                  echo form_open(admin_url('payplex_leadfinder/finder/undo_waste/' . (int) $r['id']),
                                 array('style' => 'display:inline')); ?>
                <button type="submit" class="btn btn-info btn-xs">Undo waste</button>
                <?php echo form_close();
              } ?>

              <?php
              /*
               * Reassignment is a manager's action, so the control is not
               * rendered for anyone else. The endpoint checks the capability
               * again — hiding a button is a courtesy, never a permission.
               */
              if (!empty($is_manager) && (int) $r['claimed_by'] > 0) { ?>
                <?php echo form_open(admin_url('payplex_leadfinder/finder/reassign/' . (int) $r['id']),
                                     array('style' => 'display:inline')); ?>
                  <input type="number" name="to_staff_id" min="1" style="width:70px"
                         class="form-control input-sm" placeholder="staff">
                  <button type="submit" class="btn btn-default btn-xs">Reassign</button>
                <?php echo form_close(); ?>
              <?php } ?>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
      </div>

      <div class="mbot15">
        <select name="bulk_action" class="form-control" style="max-width:220px;display:inline-block;">
          <option value="claim">Claim selected</option>
          <option value="release">Release selected</option>
          <option value="mark_irrelevant">Mark selected irrelevant</option>
        </select>
        <button type="submit" class="btn btn-default"
                onclick="return confirm('Apply this action to every selected prospect? Rows you are not allowed to change will be refused and left exactly as they are.');">
          Apply
        </button>
      </div>
      <?php echo form_close(); ?>

      <?php
      /* Paging. The queue was a hard LIMIT 500 with no offset, so beyond 500
         rows the rest of it could not be reached at all. */
      if (!empty($paging) && $paging['pages'] > 1) {
          $qs = $_GET; ?>
        <nav><ul class="pagination">
          <?php for ($i = 1; $i <= $paging['pages']; $i++) {
              $qs['page'] = $i; ?>
            <li class="<?php echo $i === $paging['page'] ? 'active' : ''; ?>">
              <a href="?<?php echo html_escape(http_build_query($qs)); ?>"><?php echo $i; ?></a>
            </li>
          <?php } ?>
        </ul></nav>
      <?php } ?>

      <p class="text-muted small">
        <?php if (!empty($paging)) { ?>
          Showing page <?php echo (int) $paging['page']; ?> of <?php echo (int) $paging['pages']; ?>,
          <?php echo (int) $paging['total']; ?> prospects in scope.
        <?php } ?>
        <?php echo html_escape(Leadfinder_places::emailSourceNote()); ?>
      </p>
      <?php } ?>

      <p class="text-muted" style="font-size:12px;font-weight:400;" translate="no">Google Maps</p>
    </div></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
