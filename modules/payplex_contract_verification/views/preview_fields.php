<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>

      <div class="alert alert-info">
        These are the field placements as drawn, for contract version
        <code><?php echo html_escape((string) $version); ?></code>.
        They are stored in editor coordinates and translated to PDF coordinates at send time, so a
        correction to the page-geometry handling applies to fields that already exist.
      </div>

      <?php if (!$fields) { ?>
        <div class="alert alert-warning">
          No signature fields have been placed for this contract version. A contract sent with no
          fields gives the signer nothing to do &mdash; the provider accepts it and the customer is
          left confused.
        </div>
      <?php } else { ?>
      <div class="table-responsive">
        <table class="table table-striped">
          <thead><tr>
            <th>Page</th><th>Type</th><th class="hidden-xs">Signer</th>
            <th class="hidden-xs">X</th><th class="hidden-xs">Y</th>
            <th class="hidden-xs">W</th><th class="hidden-xs">H</th>
            <th>Required</th><th class="hidden-xs">Order</th>
          </tr></thead>
          <tbody>
          <?php foreach ($fields as $f) { ?>
            <tr>
              <td><?php echo (int) $f['page_number']; ?></td>
              <td><?php echo html_escape(isset($types[$f['field_type']])
                    ? $types[$f['field_type']]['label'] : (string) $f['field_type']); ?></td>
              <td class="hidden-xs"><code style="font-size:11px"><?php
                    echo html_escape(mb_substr((string) $f['signer_reference'], 0, 8)); ?></code></td>
              <td class="hidden-xs"><?php echo html_escape((string) $f['x']); ?></td>
              <td class="hidden-xs"><?php echo html_escape((string) $f['y']); ?></td>
              <td class="hidden-xs"><?php echo html_escape((string) $f['width']); ?></td>
              <td class="hidden-xs"><?php echo html_escape((string) $f['height']); ?></td>
              <td><?php echo !empty($f['is_required']) ? 'yes' : 'no'; ?></td>
              <td class="hidden-xs"><?php echo (int) $f['signing_order']; ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
      <p class="text-muted small">
        The signer column shows a truncated opaque reference, never a database id. Coordinate
        accuracy is proven by measuring rendered output on A4 portrait, A4 landscape, rotated and
        multi-page documents &mdash; not by trusting these numbers.
      </p>
      <?php } ?>
    </div></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
