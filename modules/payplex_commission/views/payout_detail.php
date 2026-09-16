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
  /*
   * The totals rows span eight columns and right-align their label, which on a
   * 390px screen puts the words "Still standing" some 600px off to the right.
   * The screenshot showed three apparently blank rows under the items - the
   * figures were reachable by scrolling, but nothing on screen said there was
   * anything there to reach. Left-aligning the label below the breakpoint puts
   * it at the visible edge; the figure stays in its own column with the rest.
   */
  .pp-xscroll tfoot td.text-right { text-align: left; }
}
</style>

<div id="wrapper">
    <div class="content">
        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                    <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                    <span class="label label-<?php echo Payplex_commission_payout::statusClass($batch['status']); ?>">
                        <?php echo html_escape($batch['status']); ?></span>
                </div>

                <p>
                    Period <?php echo html_escape($batch['period']); ?> &middot;
                    <?php echo (int) $batch['item_count']; ?> item(s) &middot;
                    gross <?php echo number_format((float) $batch['gross_total'], 2); ?> &middot;
                    TDS <?php echo number_format((float) $batch['tds_total'], 2); ?> &middot;
                    <?php /* "instructed" because this stored total is what the batch told the bank to
                             do and does not change when a payment is later reversed. What is still
                             standing is in the footer of the items table. */ ?>
                    <strong>net instructed <?php echo number_format((float) $batch['net_total'], 2); ?></strong>
                    <?php if ($batch['approval_reference']): ?>
                        &middot; approval <?php echo html_escape($batch['approval_reference']); ?>
                    <?php endif; ?>
                </p>

                <?php if ($blocked): ?>
                    <div class="alert alert-danger">
                        <strong>This batch cannot be approved yet.</strong>
                        <ul class="no-mbot">
                            <?php foreach ($blocked as $b): ?><li><?php echo html_escape($b); ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php foreach (Payplex_commission_payout::transitions()[$batch['status']] as $to): ?>
                    <?php echo form_open(admin_url('payplex_commission/commission/payout_transition'), array('style' => 'display:inline')); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $batch['id']; ?>">
                    <input type="hidden" name="to" value="<?php echo html_escape($to); ?>">
                    <?php if (in_array($to, array('draft', 'cancelled'), true)): ?>
                        <input type="hidden" name="reason" value="Returned from the batch screen">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-sm btn-<?php echo $to === 'cancelled' ? 'danger' : 'info'; ?>">
                        <?php echo html_escape($to); ?>
                    </button>
                    <?php echo form_close(); ?>
                <?php endforeach; ?>

                <?php
                /*
                 * The same rule the server enforces, read from the same place.
                 * This list was written out here and nowhere else: the button
                 * was correctly hidden for an unapproved batch while the route
                 * behind it served the payment file to anyone who asked for the
                 * URL. Hiding a control is not enforcing it.
                 */
                $exportGate = Payplex_commission_payout::canExport($batch);
                ?>
                <?php if ($exportGate['allowed']): ?>
                    <a href="<?php echo admin_url('payplex_commission/commission/export_payout/' . (int) $batch['id']); ?>"
                       class="btn btn-sm btn-success">Download masked CSV</a>
                <?php else: ?>
                    <span class="text-muted"><small><?php echo html_escape($exportGate['reason']); ?></small></span>
                <?php endif; ?>
            </div></div>
        </div></div>

        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <h5 class="bold no-mtop">Items</h5>
                <p class="text-muted">Account numbers are masked everywhere, including in the export.</p>
                <div class="pp-xhint">Swipe sideways to see the rest of the columns &rarr;</div>
                <div class="pp-xtable pp-xscroll pp-xscroll--wide">
                    <table class="table table-striped">
                        <thead><tr>
                            <th>Staff</th><th>Beneficiary</th><th>Account</th><th>IFSC</th><th>Verified</th>
                            <th>Gross</th><th>TDS</th><th>Other</th><th>Net</th><th>Status</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <?php $tds = Payplex_commission_payout::tdsState($it); ?>
                            <tr>
                                <td><?php echo (int) $it['staff_id']; ?>
                                    <?php if ((int) $it['staff_id'] === (int) $me): ?>
                                        <span class="label label-warning">you</span><?php endif; ?></td>
                                <td><?php echo html_escape($it['beneficiary_name'] ?: '—'); ?></td>
                                <td><code><?php echo html_escape($it['masked_account'] ?: '—'); ?></code></td>
                                <td><?php echo html_escape($it['ifsc'] ?: '—'); ?></td>
                                <td><?php echo (int) $it['bank_verified'] ? '<span class="text-success">yes</span>' : '<span class="text-danger">no</span>'; ?></td>
                                <td><?php echo number_format((float) $it['gross_payable'], 2); ?></td>
                                <td>
                                    <?php if ($tds === 'not_configured'): ?>
                                        <span class="label label-danger">not configured</span>
                                    <?php elseif ($tds === 'exempt'): ?>
                                        <span class="label label-default">exempt</span>
                                    <?php else: ?>
                                        <?php echo number_format((float) $it['tds_amount'], 2); ?>
                                        <small>(<?php echo (float) $it['tds_rate']; ?>%)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format((float) $it['other_deductions'], 2); ?></td>
                                <td class="bold"><?php echo number_format((float) $it['net_payable'], 2); ?></td>
                                <td><span class="label label-<?php echo Payplex_commission_payout::statusClass($it['status']); ?>">
                                    <?php echo html_escape($it['status']); ?></span>
                                    <?php if ((int) $it['attempt'] > 1): ?>
                                        <small>attempt <?php echo (int) $it['attempt']; ?></small><?php endif; ?>
                                    <?php if ($it['failure_reason']): ?>
                                        <br><small class="text-danger"><?php echo html_escape($it['failure_reason']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('payplex_commission/commission/payment_advice/' . (int) $it['id']); ?>"
                                       class="btn btn-default btn-xs" target="_blank">advice</a>
                                    <?php if ($it['status'] === 'failed'): ?>
                                        <?php echo form_open(admin_url('payplex_commission/commission/retry_payout_item'), array('style' => 'display:inline')); ?>
                                        <input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>">
                                        <input type="hidden" name="batch_id" value="<?php echo (int) $batch['id']; ?>">
                                        <button type="submit" class="btn btn-warning btn-xs">retry</button>
                                        <?php echo form_close(); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <?php if (in_array($batch['status'], array('draft', 'submitted'), true)): ?>
                            <tr><td colspan="11" style="background:#fafafa;">
                                <?php echo form_open(admin_url('payplex_commission/commission/update_payout_item'), array('class' => 'form-inline')); ?>
                                <input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>">
                                <input type="hidden" name="batch_id" value="<?php echo (int) $batch['id']; ?>">
                                <label>TDS rate %</label>
                                <input type="text" name="tds_rate" class="form-control input-sm" style="width:90px"
                                       value="<?php echo html_escape($it['tds_rate']); ?>">
                                <label><input type="checkbox" name="tds_exempt" value="1"
                                    <?php echo (int) $it['tds_exempt'] ? 'checked' : ''; ?>> exempt</label>
                                <label>Other deductions</label>
                                <input type="text" name="other_deductions" class="form-control input-sm" style="width:110px"
                                       value="<?php echo html_escape($it['other_deductions']); ?>">
                                <label><input type="checkbox" name="details_corrected" value="1"
                                    <?php echo (int) $it['details_corrected'] ? 'checked' : ''; ?>> details corrected</label>
                                <button type="submit" class="btn btn-default btn-xs">save</button>
                                <?php echo form_close(); ?>
                            </td></tr>
                            <?php endif; ?>

                            <?php if ($batch['status'] === 'exported' && $it['status'] !== 'paid'): ?>
                            <tr><td colspan="11" style="background:#fafafa;">
                                <?php echo form_open(admin_url('payplex_commission/commission/record_settlement'), array('class' => 'form-inline')); ?>
                                <input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>">
                                <input type="hidden" name="batch_id" value="<?php echo (int) $batch['id']; ?>">
                                <label>Bank reference (UTR)</label>
                                <input type="text" name="bank_reference" class="form-control input-sm">
                                <label>Amount actually paid</label>
                                <input type="text" name="paid_amount" class="form-control input-sm" style="width:120px"
                                       value="<?php echo html_escape($it['net_payable']); ?>">
                                <label>Date</label>
                                <input type="date" name="paid_at" class="form-control input-sm">
                                <button type="submit" class="btn btn-success btn-xs">record settlement</button>
                                <?php echo form_close(); ?>

                                <?php echo form_open(admin_url('payplex_commission/commission/record_failure'), array('class' => 'form-inline mtop10')); ?>
                                <input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>">
                                <input type="hidden" name="batch_id" value="<?php echo (int) $batch['id']; ?>">
                                <label>Or record a failure</label>
                                <select name="failure_reason" class="form-control input-sm">
                                    <?php foreach ($failures as $k => $label): ?>
                                        <option value="<?php echo $k; ?>"><?php echo html_escape($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="notes" class="form-control input-sm" placeholder="notes">
                                <button type="submit" class="btn btn-danger btn-xs">record failure</button>
                                <?php echo form_close(); ?>
                            </td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                        <?php
                        /*
                         * The batch had no total on screen at all. Two figures
                         * are shown rather than one, because after a reversal a
                         * single number is wrong whichever one it is: the
                         * instruction said 3,700.00 and 1,850.00 came back.
                         */
                        $ppTot = Payplex_commission_payout::itemTotals($items);
                        ?>
                        <tfoot>
                            <tr>
                                <td colspan="8" class="text-right text-muted">Instructed by this batch</td>
                                <td class="bold"><?php echo number_format($ppTot['instructed'], 2); ?></td>
                                <td colspan="2"></td>
                            </tr>
                            <?php if ($ppTot['reversed_count'] > 0): ?>
                            <tr>
                                <td colspan="8" class="text-right text-muted">
                                    Reversed and returned
                                    (<?php echo (int) $ppTot['reversed_count']; ?> payment<?php echo $ppTot['reversed_count'] === 1 ? '' : 's'; ?>)
                                </td>
                                <td class="bold text-danger">-<?php echo number_format($ppTot['reversed'], 2); ?></td>
                                <td colspan="2"></td>
                            </tr>
                            <tr>
                                <td colspan="8" class="text-right bold">Still standing</td>
                                <td class="bold text-success"><?php echo number_format($ppTot['standing'], 2); ?></td>
                                <td colspan="2"></td>
                            </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
            </div></div>
        </div></div>

        <div class="row">
            <div class="col-md-6">
                <div class="panel_s"><div class="panel-body">
                    <h5 class="bold no-mtop">Reconcile against the bank statement</h5>
                    <p class="text-muted">Paste one line per settlement: bank reference, amount.</p>
                    <?php echo form_open(admin_url('payplex_commission/commission/reconcile_payout')); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $batch['id']; ?>">
                    <textarea name="bank_rows" class="form-control" rows="6" placeholder="UTR123456,9000&#10;UTR123457,18000"></textarea>
                    <button type="submit" class="btn btn-info btn-sm mtop10">Reconcile</button>
                    <?php echo form_close(); ?>
                </div></div>
            </div>
            <div class="col-md-6">
                <div class="panel_s"><div class="panel-body">
                    <h5 class="bold no-mtop">Batch activity</h5>
                    <?php /* One column of wrapping text - contained so it can never push the
                             body sideways, but deliberately NO swipe hint: there is nothing
                             horizontally to reach, and an affordance that promises otherwise
                             is worse than none. */ ?>
                    <div class="pp-xtable">
                    <table class="table table-condensed">
                        <tbody>
                        <?php foreach ($events as $e): ?>
                            <tr><td>
                                <small class="text-muted"><?php echo html_escape($e->occurred_at); ?></small>
                                <span class="label label-default"><?php echo html_escape($e->action); ?></span>
                                <?php if ($e->from_state || $e->to_state): ?>
                                    <small><?php echo html_escape($e->from_state ?: '—'); ?> &rarr;
                                    <?php echo html_escape($e->to_state ?: '—'); ?></small>
                                <?php endif; ?>
                                by #<?php echo (int) $e->actor_id; ?>
                                <?php if ($e->reason): ?><br><small><?php echo html_escape($e->reason); ?></small><?php endif; ?>
                            </td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div></div>
            </div>
        </div>
    </div>
</div>
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
</body>
</html>
