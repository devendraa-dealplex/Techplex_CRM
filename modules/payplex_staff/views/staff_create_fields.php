<?php defined('BASEPATH') or exit('No direct script access allowed');
/*
 * Workforce profile fields shown on Setup > Staff > New Staff Member (administrators only).
 * Every input is prefixed wf_ so it can be told apart from the core staff columns; the
 * payplex_staff module strips them before Perfex inserts the staff row.
 *
 * Expects: $types (slug => label), $typeDefs (slug => elig map), $managers (core staff rows).
 */
$flags = array('salary' => 'Salary', 'commission' => 'Commission', 'expense' => 'Expense', 'tada' => 'TA/DA', 'attendance' => 'Attendance');
?>
<hr />
<h4 class="tw-mb-1 tw-text-lg tw-font-bold">Workforce profile</h4>
<p class="text-muted tw-mb-4">Same fields as Staff System &rarr; Classification &rarr; Classify Staff. Leave the employment type empty to skip; a placeholder profile flagged "classification required" is then created as before.</p>

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="wf_employment_type">Employment type</label>
            <select class="form-control" name="wf_employment_type" id="wf_employment_type">
                <option value="">— select —</option>
                <?php foreach ($types as $slug => $lbl) { ?>
                <option value="<?= e($slug); ?>"><?= e($lbl); ?></option>
                <?php } ?>
            </select>
            <p class="text-muted" id="wf_type_summary" style="font-size:11px"></p>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group"><label for="wf_employee_code">Employee code</label>
            <input class="form-control" name="wf_employee_code" id="wf_employee_code" maxlength="60"></div>
    </div>
</div>
<div class="row">
    <div class="col-md-6 form-group"><label for="wf_official_email">Official email</label>
        <input class="form-control" type="email" name="wf_official_email" id="wf_official_email" placeholder="defaults to the login email"></div>
    <div class="col-md-6 form-group"><label for="wf_official_mobile">Official mobile</label>
        <input class="form-control" name="wf_official_mobile" id="wf_official_mobile" maxlength="40"></div>
</div>
<div class="row">
    <div class="col-md-6 form-group"><label for="wf_department">Department</label>
        <input class="form-control" name="wf_department" id="wf_department" maxlength="100"></div>
    <div class="col-md-6 form-group"><label for="wf_designation">Designation</label>
        <input class="form-control" name="wf_designation" id="wf_designation" maxlength="100"></div>
</div>
<div class="row">
    <div class="col-md-6 form-group"><label for="wf_branch">Branch</label>
        <input class="form-control" name="wf_branch" id="wf_branch" maxlength="100"></div>
    <div class="col-md-6 form-group"><label for="wf_territory">Territory</label>
        <input class="form-control" name="wf_territory" id="wf_territory" maxlength="100"></div>
</div>
<div class="row">
    <div class="col-md-6 form-group"><label for="wf_reporting_manager_id">Reporting manager</label>
        <select class="form-control" name="wf_reporting_manager_id" id="wf_reporting_manager_id">
            <option value="">— none —</option>
            <?php foreach ($managers as $m) { ?>
            <option value="<?= (int) $m->staffid; ?>"><?= e(trim($m->firstname . ' ' . $m->lastname)); ?> (#<?= (int) $m->staffid; ?>)</option>
            <?php } ?>
        </select></div>
    <div class="col-md-6 form-group"><label for="wf_payout_frequency">Payout frequency</label>
        <input class="form-control" name="wf_payout_frequency" id="wf_payout_frequency" value="monthly" maxlength="40"></div>
</div>
<div class="row">
    <div class="col-md-6 form-group"><label for="wf_joining_date">Joining date</label>
        <input class="form-control" type="date" name="wf_joining_date" id="wf_joining_date"></div>
    <div class="col-md-6 form-group"><label for="wf_probation_end_date">Probation/contract end</label>
        <input class="form-control" type="date" name="wf_probation_end_date" id="wf_probation_end_date"></div>
</div>

<h5 class="tw-font-semibold">Eligibility (override type defaults)</h5>
<div class="row">
    <?php foreach ($flags as $k => $lbl) { ?>
    <div class="col-md-2 col-sm-4 form-group"><label style="font-size:12px"><?= e($lbl); ?></label>
        <select class="form-control input-sm" name="wf_<?= e($k); ?>_eligibility">
            <option value="">(type default)</option><option value="1">Yes</option><option value="0">No</option>
        </select></div>
    <?php } ?>
</div>
<div class="row">
    <div class="col-md-3 col-sm-6 form-group"><label for="wf_kyc_status">KYC status</label>
        <select class="form-control" name="wf_kyc_status" id="wf_kyc_status">
            <?php foreach (array('pending', 'submitted', 'verified', 'rejected') as $o) { ?><option value="<?= $o; ?>"><?= ucfirst($o); ?></option><?php } ?>
        </select></div>
    <div class="col-md-3 col-sm-6 form-group"><label for="wf_pan_status">PAN/Tax status</label>
        <select class="form-control" name="wf_pan_status" id="wf_pan_status">
            <?php foreach (array('pending', 'submitted', 'verified') as $o) { ?><option value="<?= $o; ?>"><?= ucfirst($o); ?></option><?php } ?>
        </select></div>
    <div class="col-md-3 col-sm-6 form-group"><label for="wf_target_plan">Target/KPI plan</label>
        <input class="form-control" name="wf_target_plan" id="wf_target_plan" maxlength="100"></div>
    <div class="col-md-3 col-sm-6 form-group"><label for="wf_commission_plan">Commission plan</label>
        <input class="form-control" name="wf_commission_plan" id="wf_commission_plan" maxlength="100"></div>
</div>
<p class="text-muted" style="font-size:12px">Bank details are captured separately (maker-checker). Payouts stay blocked until KYC and bank are verified. The profile starts in "draft" and still goes through submit &rarr; verify &rarr; approve.</p>
<hr />
<script>
(function () {
    var defs = <?= json_encode($typeDefs); ?>;
    var names = <?= json_encode($flags); ?>;
    var sel = document.getElementById('wf_employment_type');
    var out = document.getElementById('wf_type_summary');
    if (!sel || !out) { return; }
    sel.addEventListener('change', function () {
        var d = defs[sel.value];
        if (!d) { out.textContent = ''; return; }
        var parts = [];
        Object.keys(names).forEach(function (k) { parts.push(names[k] + ': ' + (d[k] === true ? 'yes' : (d[k] === false ? 'no' : 'admin decides'))); });
        out.textContent = 'Defaults — ' + parts.join(', ');
    });
})();
</script>
