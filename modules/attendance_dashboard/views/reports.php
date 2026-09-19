<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head();
$export = admin_url(ATT_MODULE . '/reports?' . http_build_query(['from' => $f['from'], 'to' => $f['to'], 'export' => 1])); ?>
<div id="wrapper"><div class="content">
    <h4 class="tw-font-semibold tw-mb-3">Reports
        <a href="<?= $export; ?>" class="btn btn-default pull-right">Export CSV</a></h4>
    <?php $this->load->view('attendance_dashboard/_filters', ['f' => $f, 'workplaces' => [], 'showText' => false]); ?>
    <div class="row">
        <div class="col-md-7"><div class="panel_s"><div class="panel-body">
            <h5 class="tw-font-semibold">Employee-wise</h5>
            <?php $this->load->view('attendance_dashboard/_summary_table', ['rows' => $sum['employees'], 'label' => 'Employee']); ?>
        </div></div></div>
        <div class="col-md-5"><div class="panel_s"><div class="panel-body">
            <h5 class="tw-font-semibold">Location-wise</h5>
            <?php $this->load->view('attendance_dashboard/_summary_table', ['rows' => $sum['locations'], 'label' => 'Location']); ?>
        </div></div></div>
    </div>
</div></div>
<?php init_tail(); ?>
</body></html>
