<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<style>
/*
 * Wide tables scroll inside themselves; the page body never scrolls sideways.
 *
 * A horizontal scrollbar on <body> is not a cosmetic problem: it pushes the
 * filters and the action buttons at the top of the page off to one side, and a
 * phone user loses the controls rather than the columns.
 *
 * min-width is what makes the scrolling real. Without it the browser squeezes
 * nine or eleven columns into 390px and the result is not a scrollbar - it is
 * cells overlapping and words breaking mid-letter, which reads as a rendering
 * fault rather than a table that needs scrolling.
 *
 * The hint is shown only below the same breakpoint at which min-width
 * guarantees the table is wider than its container, so it cannot advertise
 * scrolling that is not there. Tables with a single column get the container
 * (so they can never push the body sideways) but no hint, because there is
 * nothing sideways to go to.
 */
.pp-xtable { overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; }
.pp-xtable > table { margin-bottom: 0; }
.pp-xhint { display: none; margin: 0 0 6px; padding: 5px 9px; font-size: 12px;
             color: #6b6b6b; background: #f5f5f5; border-left: 3px solid #9b9b9b;
             border-radius: 2px; }
@media (max-width: 991px) {
  .pp-xscroll { border-right: 1px dashed #c4c4c4; }
  .pp-xscroll > table          { min-width: 780px; }
  .pp-xscroll--wide > table    { min-width: 980px; }
  .pp-xscroll--narrow > table  { min-width: 560px; }
}
</style>

<?php
  $cls = array('draft'=>'default','submitted'=>'info','manager_review'=>'info','finance_review'=>'info',
               'approved'=>'success','rejected'=>'danger','payout_processing'=>'warning',
               'paid'=>'success','failed'=>'danger','reversed'=>'danger','cancelled'=>'default');
  $bcls = array('under_3d'=>'default','3_7d'=>'info','7_14d'=>'warning','over_14d'=>'danger');
?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">

    <h4 style="color:#12507F;font-weight:600;margin:0 0 4px">Expense Claims</h4>
    <p class="text-muted" style="font-size:13px">
      <?php echo $view_all ? 'Every claim in the workforce.' : 'Your claims.'; ?>
      A claim needs a manager review, a finance review and an approval, and no two of those
      may be the same person &mdash; nor the claimant, administrators included.
    </p>

    <?php if (!$payable_hidden = false): ?>
      <div class="alert alert-<?php echo $bank['ok'] ? 'success' : 'warning'; ?>" style="font-size:13px">
        <b>Your bank details:</b> <?php echo $bank['ok'] ? 'verified and payable.' : html_escape($bank['reason']); ?>
        <?php if (!$bank['ok']): ?>
          <a href="<?php echo admin_url('payplex_staff/staff/bank'); ?>">Record them</a> &mdash;
          a claim can be approved without them, but it cannot be paid.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php
      /*
       * The one number that matters on a Monday is how many claims are sitting
       * waiting for a person. Everything else on this strip is context for it.
       * Drafts are excluded on purpose: a draft is the claimant's to finish and
       * is not a queue anybody else is holding up.
       */
      $d = $dash;
    ?>
    <div class="row" style="margin-bottom:14px">
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px;background:<?php echo $d['needs_someone'] ? '#FFF7ED' : '#F1F8F2'; ?>">
        <div style="font-size:26px;font-weight:600;color:<?php echo $d['needs_someone'] ? '#B26A00' : '#2E7D32'; ?>">
          <?php echo (int) $d['needs_someone']; ?></div>
        <div class="text-muted" style="font-size:12px">waiting for a decision
          <?php if ($d['oldest_days'] > 0): ?><br>oldest: <b><?php echo (int) $d['oldest_days']; ?> days</b><?php endif; ?>
        </div>
      </div></div>
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px">
        <div style="font-size:22px;font-weight:600"><?php echo number_format((float) $d['value_waiting'], 2); ?></div>
        <div class="text-muted" style="font-size:12px">value in the queue</div>
      </div></div>
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px">
        <div style="font-size:22px;font-weight:600;color:#2E7D32"><?php echo number_format((float) $d['value_paid'], 2); ?></div>
        <div class="text-muted" style="font-size:12px">paid</div>
      </div></div>
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px">
        <div style="font-size:22px;font-weight:600"><?php echo (int) $d['drafts_excluded']; ?></div>
        <div class="text-muted" style="font-size:12px">drafts &mdash; the claimant's to finish,
          not counted as waiting</div>
      </div></div>
    </div>
    <p style="margin-bottom:14px">
      <?php foreach ($d['by_state'] as $st => $n): if (!$n) { continue; } ?>
        <span class="label label-<?php echo $cls[$st] ?? 'default'; ?>" style="font-size:11px;margin-right:5px">
          <?php echo html_escape(Workforce_expense::label($st)); ?>: <b><?php echo (int) $n; ?></b></span>
      <?php endforeach; ?>
      <a class="btn btn-default btn-xs" style="margin-left:8px"
         href="<?php echo admin_url('payplex_staff/staff/expenses_export'); ?>">Export CSV</a>
      <small class="text-muted">&nbsp;no bank details are in the export &mdash; that is the payout
        engine's bank file, a different document with a different permission</small>
    </p>

    <?php if ($view_all && $ageing['total_waiting'] > 0): ?>
      <h5 style="font-weight:600;margin-top:18px">Waiting for a decision</h5>
      <p class="text-muted" style="font-size:12px;margin-top:-6px">
        A pending list sorted by date does not say who is holding things up. This does.
      </p>
      <p>
        <?php foreach ($ageing['buckets'] as $b => $n): ?>
          <span class="label label-<?php echo $bcls[$b]; ?>" style="font-size:12px;margin-right:6px">
            <?php echo html_escape(Workforce_expense::ageingBuckets()[$b]); ?>: <b><?php echo (int) $n; ?></b>
          </span>
        <?php endforeach; ?>
      </p>
      <div class="pp-xhint">Swipe sideways to see the rest of the columns &rarr;</div>
      <div class="pp-xtable pp-xscroll pp-xscroll--narrow">
      <table class="table" style="font-size:12px">
        <thead><tr><th>Claim</th><th>Claimant</th><th>Amount</th><th>Waiting on</th><th>Days</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($ageing['rows'], 0, 10) as $r): ?>
          <tr>
            <td><a href="<?php echo admin_url('payplex_staff/staff/expense/' . (int) $r['id']); ?>">
                <?php echo html_escape($r['claim_ref']); ?></a></td>
            <td>#<?php echo (int) $r['claimant_id']; ?></td>
            <td><?php echo number_format((float) $r['amount'], 2); ?></td>
            <td><?php echo html_escape($r['ageing']['with']); ?></td>
            <td><span class="label label-<?php echo $bcls[$r['ageing']['bucket']]; ?>">
                <?php echo (int) $r['ageing']['days']; ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>

    <?php if ($can['submit']): ?>
      <h5 style="font-weight:600;margin-top:20px">Raise a claim</h5>
      <form method="post" class="form-inline" action="<?php echo admin_url('payplex_staff/staff/expense_new'); ?>">
        <?php echo form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash()); ?>
        <select name="category" class="form-control input-sm">
          <?php foreach (Workforce_expense::categories() as $k => $v): ?>
            <option value="<?php echo html_escape($k); ?>"><?php echo html_escape($v); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="payable_kind" class="form-control input-sm">
          <?php foreach (Workforce_expense::payableKinds() as $k => $v): ?>
            <option value="<?php echo html_escape($k); ?>"><?php echo html_escape($v['label']); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="number" step="0.01" name="amount" class="form-control input-sm" style="width:110px" placeholder="Amount">
        <input type="number" step="0.01" name="tax_amount" class="form-control input-sm" style="width:90px" placeholder="Tax">
        <input type="date" name="expense_date" class="form-control input-sm" title="Date the money was spent">
        <input type="text" name="purpose" class="form-control input-sm" style="width:300px" placeholder="What was it for?">
        <?php if ($view_all): ?>
          <input type="number" name="claimant_id" class="form-control input-sm" style="width:110px" placeholder="For staff #">
        <?php endif; ?>
        <button class="btn btn-primary btn-sm">Create draft</button>
      </form>
    <?php endif; ?>

    <h5 style="font-weight:600;margin-top:22px">Claims</h5>
    <div class="pp-xhint">Swipe sideways to see the rest of the columns &rarr;</div>
    <div class="pp-xtable pp-xscroll">
    <table class="table table-striped" style="font-size:13px">
      <thead><tr>
        <th>Ref</th><th>Claimant</th><th>Category</th><th>Kind</th><th>Date</th>
        <th>Amount</th><th>State</th><th>Waiting</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$claims): ?>
        <tr><td colspan="9" class="text-muted">No claims yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($claims as $c): $a = Workforce_expense::ageing($c); ?>
        <tr>
          <td><a href="<?php echo admin_url('payplex_staff/staff/expense/' . (int) $c['id']); ?>">
              <?php echo html_escape($c['claim_ref']); ?></a></td>
          <td>#<?php echo (int) $c['claimant_id']; ?><?php echo (int) $c['claimant_id'] === (int) $me ? ' <span class="text-muted">(you)</span>' : ''; ?></td>
          <td><?php echo html_escape(Workforce_expense::categories()[$c['category']] ?? $c['category']); ?></td>
          <td><?php echo html_escape(Workforce_expense::payableKinds()[$c['payable_kind']]['label'] ?? $c['payable_kind']); ?></td>
          <td><?php echo html_escape($c['expense_date']); ?></td>
          <td><b><?php echo number_format((float) $c['amount'], 2); ?></b></td>
          <td><span class="label label-<?php echo $cls[$c['state']] ?? 'default'; ?>">
              <?php echo html_escape(Workforce_expense::label($c['state'])); ?></span></td>
          <td><?php echo $a['waiting'] ? ((int) $a['days'] . 'd &middot; ' . html_escape($a['with'])) : '&mdash;'; ?></td>
          <td><a class="btn btn-default btn-xs"
                 href="<?php echo admin_url('payplex_staff/staff/expense/' . (int) $c['id']); ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

  </div></div>
</div></div></div>
<script>
(function () {
  /*
   * The hint is revealed only for a table that is ACTUALLY wider than its
   * container - measured in the browser, not inferred from a breakpoint.
   *
   * The first version keyed the hint off the same media query as the
   * min-widths, and I claimed that made it honest because the minimum
   * guaranteed an overflow. Measured at 768x1024 it did not: the ageing
   * table's 560px minimum sits comfortably inside a 718px container, so the
   * table could not move and the hint still said "swipe sideways". A control
   * that tells the reader to do something impossible is the same defect this
   * module keeps finding elsewhere, so it is now driven by the measurement
   * rather than by an assumption about it.
   *
   * Hidden is the default. If this never runs, the reader is told nothing
   * rather than told something untrue, and the table still scrolls.
   */
  function sync() {
    var wraps = document.querySelectorAll('.pp-xtable');
    for (var i = 0; i < wraps.length; i++) {
      var w = wraps[i], h = w.previousElementSibling;
      if (!h || String(h.className).indexOf('pp-xhint') === -1) { continue; }
      h.style.display = (w.scrollWidth > w.clientWidth + 1) ? 'block' : 'none';
    }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', sync); }
  else { sync(); }
  window.addEventListener('resize', sync);
  window.addEventListener('orientationchange', sync);
})();
</script>
<?php init_tail(); ?>
