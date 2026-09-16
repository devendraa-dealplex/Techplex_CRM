<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <h4 class="no-margin"><?= html_escape($title); ?></h4>
            <p class="text-muted"><?= _l('facebook_review_intro'); ?></p>
            <hr class="hr-panel-heading" />

            <?php if ($source_id === null) { ?>
              <div class="alert alert-warning">
                <?= _l('facebook_source_missing'); ?>
                <strong><?= html_escape($source_name); ?></strong>
              </div>
            <?php } elseif (empty($rows)) { ?>
              <div class="alert alert-success"><?= _l('facebook_review_empty'); ?></div>
            <?php } else { ?>
              <div class="alert alert-warning">
                <strong><?= count($rows); ?></strong> <?= _l('facebook_review_count'); ?>
              </div>
              <div class="table-responsive">
                <table class="table table-striped table-condensed">
                  <thead>
                    <tr>
                      <th><?= _l('lead'); ?></th>
                      <th><?= _l('lead_name'); ?></th>
                      <th><?= _l('lead_email'); ?></th>
                      <th><?= _l('lead_phonenumber'); ?></th>
                      <th><?= _l('lead_date_created'); ?></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r) { ?>
                      <tr>
                        <td>#<?= (int) $r['id']; ?></td>
                        <td><?= html_escape($r['name']); ?></td>
                        <td><?= html_escape($r['email']); ?></td>
                        <td><?= html_escape($r['phonenumber']); ?></td>
                        <td class="text-nowrap"><?= html_escape($r['dateadded']); ?></td>
                        <td>
                          <a href="<?= admin_url('leads/index/' . (int) $r['id']); ?>"
                             class="btn btn-default btn-sm"><?= _l('facebook_open_lead'); ?></a>
                        </td>
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
