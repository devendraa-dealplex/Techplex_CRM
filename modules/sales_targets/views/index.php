<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h4><?php echo _l('sales_targets_title'); ?></h4>
                                <p class="text-muted"><?php echo _l('sales_targets_list_hint'); ?></p>
                            </div>
                            <?php if ($is_manager_view) { ?>
                            <div class="col-md-6 text-right">
                                <a href="<?php echo admin_url('sales_targets/manage'); ?>" class="btn btn-primary">
                                    <i class="fa-solid fa-plus"></i> <?php echo _l('sales_targets_add_new'); ?>
                                </a>
                            </div>
                            <?php } ?>
                        </div>
                        <hr>
                        <?php if (empty($records)) { ?>
                            <p class="text-muted"><?php echo _l('sales_targets_no_records'); ?></p>
                        <?php } else { ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <?php if ($is_manager_view) { ?><th><?php echo _l('sales_targets_col_staff'); ?></th><?php } ?>
                                        <th><?php echo _l('sales_targets_col_period'); ?></th>
                                        <th>Status</th>
                                        <th><?php echo _l('sales_targets_col_target'); ?></th>
                                        <th>Other KPIs</th>
                                        <th><?php echo _l('sales_targets_col_achieved'); ?></th>
                                        <th><?php echo _l('sales_targets_col_progress'); ?></th>
                                        <?php if ($is_manager_view) { ?><th></th><?php } ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($records as $row) {
                                    $percent = $row['target_value'] > 0 ? (int) round(($row['achieved'] / $row['target_value']) * 100) : 0;
                                    $bar_percent = min($percent, 100);
                                    if ($percent >= 100) {
                                        $bar_class = 'progress-bar-success';
                                    } elseif ($percent >= 50) {
                                        $bar_class = 'progress-bar-info';
                                    } else {
                                        $bar_class = 'progress-bar-warning';
                                    }
                                ?>
                                    <tr>
                                        <?php if ($is_manager_view) { ?>
                                        <td><?php echo e($row['firstname'] . ' ' . $row['lastname']); ?></td>
                                        <?php } ?>
                                        <td><?php echo date('d M Y', strtotime($row['period_start'])) . ' - ' . date('d M Y', strtotime($row['period_end'])); ?></td>
                                        <td>
                                            <?php
                                              $st = (string) $row['status'];
                                              $cls = $st === 'Active' ? 'success' : ($st === 'Draft' ? 'default'
                                                   : ($st === 'Submitted' ? 'warning' : ($st === 'Cancelled' ? 'danger' : 'info')));
                                            ?>
                                            <span class="label label-<?php echo $cls; ?>"><?php echo e($st); ?></span>
                                            <?php if ($st === 'Active' && empty($row['approved_by'])) { ?>
                                              <br><small class="text-danger" title="This target reached Active before approval was enforced">approved by nobody</small>
                                            <?php } ?>
                                        </td>
                                        <td><?php echo (int) $row['target_value']; ?></td>
                                        <td>
                                            <?php
                                              $extra = array();
                                              foreach (($row['metrics'] ?? array()) as $mrow) {
                                                  if ($mrow['kpi_key'] === 'converted_leads') { continue; }
                                                  $extra[] = str_replace('_', ' ', $mrow['kpi_key']) . ': '
                                                           . rtrim(rtrim(number_format((float) $mrow['target_value'], 2), '0'), '.');
                                              }
                                            ?>
                                            <?php if ($extra) { ?>
                                              <small><?php echo e(implode(' · ', $extra)); ?></small>
                                            <?php } else { ?>
                                              <span class="text-muted"><small>&mdash;</small></span>
                                            <?php } ?>
                                        </td>
                                        <td><?php echo (int) $row['achieved']; ?></td>
                                        <td style="min-width: 160px;">
                                            <div class="progress" style="margin-bottom: 0;">
                                                <div class="progress-bar <?php echo $bar_class; ?>" data-percent="<?php echo $percent; ?>" style="width: <?php echo $bar_percent; ?>%;">
                                                    <?php echo $percent; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <?php if ($is_manager_view) { ?>
                                        <td class="text-right">
                                            <a href="<?php echo admin_url('sales_targets/manage/' . $row['id']); ?>" class="btn btn-default btn-icon">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </a>
                                            <a href="<?php echo admin_url('sales_targets/delete/' . $row['id']); ?>" class="btn btn-danger btn-icon">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        </td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
