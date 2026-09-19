<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head(); ?>
<div id="wrapper"><div class="content">
    <h4 class="tw-font-semibold tw-mb-3">Attendance Dashboard</h4>
    <?php $this->load->view('attendance_dashboard/_filters', ['f' => $f, 'workplaces' => $workplaces, 'showStatus' => true]); ?>

    <div class="row">
    <?php foreach ([
        ['Total employees', $today['total'], 'default'],
        ['Present today', $today['present'], 'success'],
        ['Absent today', $today['absent'], 'danger'],
        ['Late arrivals today', $today['late'], 'warning'],
        ['Attendance % (range)', $pct . '%', 'info'],
    ] as $c) { ?>
        <div class="col-md-2 col-sm-4 col-xs-6" style="width:20%;min-width:150px">
            <div class="panel_s"><div class="panel-body">
                <div class="text-muted"><?= $c[0]; ?></div>
                <div class="tw-text-3xl tw-font-bold text-<?= $c[2]; ?>"><?= $c[1]; ?></div>
            </div></div>
        </div>
    <?php } ?>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="panel_s"><div class="panel-body">
                <h5 class="tw-font-semibold">Employee-wise attendance</h5>
                <?php $this->load->view('attendance_dashboard/_summary_table', ['rows' => $sum['employees'], 'label' => 'Employee']); ?>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="panel_s"><div class="panel-body">
                <h5 class="tw-font-semibold">Location-wise attendance</h5>
                <?php $this->load->view('attendance_dashboard/_summary_table', ['rows' => $sum['locations'], 'label' => 'Location']); ?>
            </div></div>
        </div>
    </div>

    <div class="panel_s"><div class="panel-body">
        <h5 class="tw-font-semibold">Recent attendance</h5>
        <?php $this->load->view('attendance_dashboard/_records_table', ['rows' => $recent]); ?>
    </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
