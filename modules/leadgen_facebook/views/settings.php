<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <h4 class="no-margin"><?= _l('leadgen_facebook_settings'); ?></h4>
            <hr class="hr-panel-heading" />

            <?php echo form_open(admin_url('leadgen_facebook')); ?>
            <div class="row">
              <div class="col-md-6">

                <h5><?= _l('facebook_section_credentials'); ?></h5>
                <?= render_input('settings[facebook_page_id]', _l('facebook_page_id'), get_option('facebook_page_id'), 'text'); ?>

                <?php
                /*
                 * Credential fields render EMPTY, always.
                 *
                 * They used to render with `value="<the real secret>"` and
                 * `type="password"`, which hides the characters on screen and
                 * hides them from nothing else: View Source, a saved page, a
                 * DOM-reading screenshot tool and any page cache all had the
                 * app secret and the page access token in plaintext.
                 *
                 * Leave a field blank to keep the stored value. The line under
                 * each one says whether something is stored, how long it is,
                 * and a short fingerprint — enough to confirm a paste landed
                 * and to tell staging's credential from production's, and not
                 * enough to use.
                 */
                foreach (array(
                    'facebook_app_secret'        => _l('facebook_app_secret'),
                    'facebook_page_access_token' => _l('facebook_page_access_token'),
                    'facebook_verify_token'      => _l('facebook_verify_token'),
                ) as $name => $label) { ?>
                  <div class="form-group">
                    <label for="<?= $name; ?>" class="control-label"><?= $label; ?></label>
                    <input type="password" name="settings[<?= $name; ?>]" id="<?= $name; ?>"
                           class="form-control" value="" autocomplete="new-password" />
                    <span class="help-block">
                      <?= _l('facebook_stored_value'); ?>:
                      <strong><?= html_escape($secret_state[$name]); ?></strong><br />
                      <?= _l('facebook_secret_blank_hint'); ?>
                      <label class="text-danger" style="font-weight:normal;">
                        <input type="checkbox" name="clear_secret[]" value="<?= $name; ?>" />
                        <?= _l('facebook_clear_stored_value'); ?>
                      </label>
                    </span>
                  </div>
                <?php } ?>

                <h5><?= _l('facebook_section_routing'); ?></h5>

                <?= render_input('settings[facebook_lead_source_name]', _l('facebook_lead_source_name'), get_option('facebook_lead_source_name'), 'text'); ?>
                <?= render_input('settings[facebook_lead_status_name]', _l('facebook_lead_status_name'), get_option('facebook_lead_status_name'), 'text'); ?>

                <?php if (!empty($lead_statuses)) { ?>
                  <?= render_select('settings[facebook_default_lead_status]', $lead_statuses, array('id', 'name'), 'facebook_default_lead_status', get_option('facebook_default_lead_status')); ?>
                  <p class="help-block"><?= _l('facebook_status_override_hint'); ?></p>
                <?php } ?>

                <h5><?= _l('facebook_section_assignment'); ?></h5>

                <?php
                $modes = array();

                foreach (Facebook_settings::ASSIGNMENT_MODES as $m) {
                    $modes[] = array('id' => $m, 'name' => _l('facebook_mode_' . $m));
                }
                ?>
                <?= render_select('settings[facebook_assignment_mode]', $modes, array('id', 'name'), 'facebook_assignment_mode', get_option('facebook_assignment_mode')); ?>

                <?php
                $staffOptions = array();

                foreach ($staff as $s) {
                    $staffOptions[] = array(
                        'id'   => $s['staffid'],
                        'name' => $s['firstname'] . ' ' . $s['lastname'] . ' (#' . $s['staffid'] . ')',
                    );
                }
                ?>
                <?= render_select('settings[facebook_default_assignee]', $staffOptions, array('id', 'name'), 'facebook_default_assignee', get_option('facebook_default_assignee')); ?>
                <?= render_input('settings[facebook_round_robin_pool]', _l('facebook_round_robin_pool'), get_option('facebook_round_robin_pool'), 'text'); ?>
                <p class="help-block"><?= _l('facebook_round_robin_hint'); ?></p>

                <?php if (!empty($pool_dropped)) { ?>
                  <div class="alert alert-warning">
                    <?= _l('facebook_pool_dropped'); ?>:
                    <strong><?= html_escape(implode(', ', $pool_dropped)); ?></strong>
                  </div>
                <?php } ?>

                <h5><?= _l('facebook_section_tagging'); ?></h5>
                <p class="help-block"><?= _l('facebook_tagging_section_hint'); ?></p>
                <?php
                /*
                 * A hand-rolled on/off control instead of render_select.
                 *
                 * WHY, AND HOW IT WAS FOUND
                 * -------------------------
                 * Read back from the live production form, the Messenger
                 * switch rendered its "no" option as value="" and, with '0'
                 * stored, selected a BLANK option rather than "no" — Perfex's
                 * render_select loses an id of '0'. So the one control whose
                 * whole job is to say whether something is off could not
                 * display "off", and submitting the form wrote '' back.
                 *
                 * Behaviourally that was harmless (every reader tests
                 * !== '1'), which is exactly why it survived. It is still the
                 * defect shape this whole module has been fixing: a control
                 * that cannot report its own state. An administrator opening
                 * this page must be able to see that tagging is on and
                 * Messenger is off, not two blank dropdowns.
                 */
                $onOff = function ($name, $current) {
                    $isOn = (string) $current === '1';
                    echo '<div class="form-group"><label for="' . $name . '" class="control-label">'
                        . htmlspecialchars(_l('facebook_' . $name), ENT_QUOTES, 'UTF-8')
                        . '</label><select name="settings[' . $name . ']" id="' . $name
                        . '" class="selectpicker" data-width="100%">'
                        . '<option value="0"' . ($isOn ? '' : ' selected') . '>'
                        . htmlspecialchars(_l('no'), ENT_QUOTES, 'UTF-8') . '</option>'
                        . '<option value="1"' . ($isOn ? ' selected' : '') . '>'
                        . htmlspecialchars(_l('yes'), ENT_QUOTES, 'UTF-8') . '</option>'
                        . '</select></div>';
                };
                ?>
                <?= render_input('settings[facebook_business_unit]', _l('facebook_business_unit'), get_option('facebook_business_unit'), 'text'); ?>
                <p class="help-block"><?= _l('facebook_business_unit_hint'); ?></p>

                <?= render_input('settings[facebook_page_name]', _l('facebook_page_name'), get_option('facebook_page_name'), 'text'); ?>
                <p class="help-block"><?= _l('facebook_page_name_hint'); ?></p>

                <?php $onOff('facebook_tagging_enabled', get_option('facebook_tagging_enabled')); ?>
                <p class="help-block"><?= _l('facebook_tagging_enabled_hint'); ?></p>

                <?php
                /*
                 * What a lead arriving now would actually be tagged with, computed
                 * from the same library the delivery path uses — not a description
                 * of it. A settings screen that describes intended behaviour
                 * instead of showing resolved behaviour is how the hard-coded
                 * status id survived as long as it did.
                 */
                $previewSource = trim((string) get_option('facebook_lead_source_name'));
                $previewTags = Facebook_tagging::tagsFor(
                    get_option('facebook_business_unit'),
                    $previewSource === '' ? 'Facebook Lead Ads' : $previewSource,
                    get_option('facebook_page_name')
                );
                ?>
                <p class="help-block">
                  <strong><?= _l('facebook_tags_preview'); ?>:</strong>
                  <?php if (empty($previewTags)) { ?>
                    <span class="text-danger"><?= _l('facebook_tags_none'); ?></span>
                  <?php } else { ?>
                    <?php foreach ($previewTags as $t) { ?>
                      <span class="label label-info"><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php } ?>
                  <?php } ?>
                </p>

                <h5><?= _l('facebook_section_duplicates'); ?></h5>
                <p class="help-block"><?= _l('facebook_section_duplicates_hint'); ?></p>

                <?php $onOff('facebook_duplicate_detection', get_option('facebook_duplicate_detection')); ?>
                <p class="help-block"><?= _l('facebook_duplicate_detection_hint'); ?></p>

                <?= render_input('settings[facebook_shared_numbers]', _l('facebook_shared_numbers'), get_option('facebook_shared_numbers'), 'text'); ?>
                <p class="help-block"><?= _l('facebook_shared_numbers_hint'); ?></p>

                <?= render_input('settings[facebook_shared_number_threshold]', _l('facebook_shared_number_threshold'), get_option('facebook_shared_number_threshold'), 'number'); ?>
                <p class="help-block"><?= _l('facebook_shared_number_threshold_hint'); ?></p>

                <h5><?= _l('facebook_section_other'); ?></h5>
                <?php $onOff('facebook_messenger_enabled', get_option('facebook_messenger_enabled')); ?>
                <p class="help-block"><?= _l('facebook_messenger_hint'); ?></p>

                <?= render_input('settings[facebook_rate_limit_per_minute]', _l('facebook_rate_limit_per_minute'), get_option('facebook_rate_limit_per_minute'), 'number'); ?>
                <?= render_input('settings[facebook_health_window_hours]', _l('facebook_health_window_hours'), get_option('facebook_health_window_hours'), 'number'); ?>

                <button type="submit" class="btn btn-primary"><?= _l('submit'); ?></button>
              </div>

              <div class="col-md-6">
                <div class="alert alert-info">
                  <strong><?= _l('facebook_webhook_url'); ?></strong><br />
                  <code><?= html_escape($webhook_url); ?></code>
                  <hr />
                  <?= _l('facebook_webhook_hint'); ?>
                </div>

                <div class="panel_s">
                  <div class="panel-body">
                    <h5 class="no-margin"><?= _l('facebook_current_behaviour'); ?></h5>
                    <hr class="hr-panel-heading" />
                    <table class="table table-condensed">
                      <tbody>
                        <tr>
                          <td><?= _l('facebook_lead_source_name'); ?></td>
                          <td>
                            <strong><?= html_escape($resolved_source_name); ?></strong>
                            <?php if ($resolved_source_id === null) { ?>
                              <span class="label label-warning"><?= _l('facebook_will_be_created'); ?></span>
                            <?php } else { ?>
                              <span class="text-muted">#<?= (int) $resolved_source_id; ?></span>
                            <?php } ?>
                          </td>
                        </tr>
                        <tr>
                          <td><?= _l('facebook_resolved_status'); ?></td>
                          <td>
                            <strong><?= html_escape($resolved_status['name']); ?></strong>
                            <?php if ($resolved_status['status_id'] !== null) { ?>
                              <span class="text-muted">#<?= (int) $resolved_status['status_id']; ?></span>
                            <?php } ?>
                            <br /><small class="text-muted"><?= html_escape($resolved_status['source']); ?></small>
                          </td>
                        </tr>
                        <tr>
                          <td><?= _l('facebook_resolved_assignment'); ?></td>
                          <td>
                            <?php if ($resolved_assignment['needs_review']) { ?>
                              <span class="label label-warning"><?= _l('facebook_review_queue'); ?></span>
                            <?php } else { ?>
                              <strong>#<?= (int) $resolved_assignment['staff_id']; ?></strong>
                            <?php } ?>
                            <br /><small class="text-muted"><?= html_escape($resolved_assignment['reason']); ?></small>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <a href="<?= admin_url('leadgen_facebook/deliveries'); ?>" class="btn btn-default btn-sm">
                      <?= _l('facebook_view_deliveries'); ?>
                    </a>
                    <a href="<?= admin_url('leadgen_facebook/review'); ?>" class="btn btn-default btn-sm">
                      <?= _l('facebook_view_review_queue'); ?>
                    </a>
                    <a href="<?= admin_url('leadgen_facebook/reports'); ?>" class="btn btn-default btn-sm">
                      <?= _l('facebook_view_reports'); ?>
                    </a>
                    <a href="<?= admin_url('leadgen_facebook/enrichment'); ?>" class="btn btn-default btn-sm">
                      <?= _l('facebook_view_enrichment'); ?>
                    </a>
                    <a href="<?= admin_url('leadgen_facebook/quarantine'); ?>" class="btn btn-default btn-sm">
                      <?= _l('facebook_view_quarantine'); ?>
                    </a>
                    <a href="<?= admin_url('leadgen_facebook/duplicates'); ?>" class="btn btn-default btn-sm">
                      <?= _l('facebook_view_duplicates'); ?>
                    </a>
                  </div>
                </div>

                <?php if (!empty($totals)) { ?>
                  <div class="panel_s">
                    <div class="panel-body">
                      <h5 class="no-margin"><?= _l('facebook_delivery_summary'); ?></h5>
                      <hr class="hr-panel-heading" />
                      <table class="table table-condensed">
                        <thead><tr>
                          <th><?= _l('facebook_outcome'); ?></th>
                          <th><?= _l('facebook_count'); ?></th>
                          <th><?= _l('facebook_last_seen'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($totals as $t) { ?>
                          <tr>
                            <td>
                              <span class="label label-<?= $t['accepted'] ? 'success' : 'danger'; ?>">
                                <?= html_escape($t['outcome']); ?>
                              </span>
                            </td>
                            <td><?= (int) $t['n']; ?></td>
                            <td><?= html_escape($t['last_seen']); ?></td>
                          </tr>
                        <?php } ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                <?php } ?>
              </div>
            </div>
            <?php echo form_close(); ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
