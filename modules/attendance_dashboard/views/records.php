<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head(); ?>
<div id="wrapper"><div class="content">
    <h4 class="tw-font-semibold tw-mb-3">Attendance Records</h4>
    <p class="text-muted">Records are created only by the camera + location + time verified flow. They cannot be added or edited here.</p>
    <?php $this->load->view('attendance_dashboard/_filters', ['f' => $f, 'workplaces' => $workplaces, 'showStatus' => true]); ?>
    <div class="panel_s"><div class="panel-body"><?php $this->load->view('attendance_dashboard/_records_table', ['rows' => $rows]); ?></div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
