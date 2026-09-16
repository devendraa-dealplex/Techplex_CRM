<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">

            <h4 class="no-mtop">
              Place signature fields
              <small>&mdash; <?php echo html_escape($contract['subject']); ?></small>
            </h4>

            <p class="text-muted">
              Contract version <strong><?php echo html_escape($version); ?></strong>.
              Placements are stored in editor coordinates and translated to PDF coordinates at send
              time, so a correction to the page-geometry handling applies to fields that already exist.
            </p>

            <?php if (empty($drafts_ready)) { ?>
              <div class="alert alert-warning">
                <strong>The draft table is not installed.</strong>
                Migration <code>204_field_drafts.php</code> has not been applied, so there is nowhere
                to save work in progress. Everything else on this module continues to work; only this
                screen is unavailable.
              </div>
            <?php } else { ?>

            <div class="alert alert-info">
              <strong>This editor works on a declared page geometry.</strong>
              There is no PDF pipeline in this build, so the contract document cannot be rendered
              behind the boxes. Set the page size and rotation to match the document, then place
              fields. At send time the mapper re-applies the transform using the geometry read from
              the real PDF and refuses the batch if anything falls off the page &mdash; so a wrong
              declaration here produces a refusal, never a misplaced signature.
            </div>

            <?php if (empty($signers)) { ?>
              <div class="alert alert-warning">
                No signing request with signers exists for this contract yet, so fields are placed
                against <em>signer slots</em> (first signer, second signer &hellip;). Slots are
                resolved to real signers when the placements are approved after a request exists.
                Approving now keeps the work and leaves the fields unbound &mdash; they will be
                refused at send until there are signers to attach them to.
              </div>
            <?php } ?>

            <div class="row">
              <div class="col-md-4">

                <h5>Page geometry</h5>
                <div class="form-group">
                  <label class="control-label">Page width (pt)</label>
                  <input type="number" step="0.1" min="1" id="pgw" class="form-control" value="595.3">
                </div>
                <div class="form-group">
                  <label class="control-label">Page height (pt)</label>
                  <input type="number" step="0.1" min="1" id="pgh" class="form-control" value="841.9">
                </div>
                <div class="form-group">
                  <label class="control-label">Rotation</label>
                  <select id="rot" class="form-control">
                    <option value="0">0&deg;</option>
                    <option value="90">90&deg;</option>
                    <option value="180">180&deg;</option>
                    <option value="270">270&deg;</option>
                  </select>
                  <p class="text-muted small">
                    <code>/Rotate</code> is clockwise when displayed. At 90&deg; the top edge of the
                    portrait sheet is on the right.
                  </p>
                </div>
                <div class="form-group">
                  <label class="control-label">Editor scale</label>
                  <input type="number" step="0.05" min="0.05" id="scale" class="form-control" value="1">
                  <p class="text-muted small">
                    Stored with every placement. Minimum sizes are checked in points, after dividing
                    by this &mdash; not in screen pixels.
                  </p>
                </div>

                <hr>

                <h5>New field</h5>
                <div class="form-group">
                  <label class="control-label">Page number</label>
                  <input type="number" min="1" id="page" class="form-control" value="1">
                </div>
                <div class="form-group">
                  <label class="control-label">Field type</label>
                  <select id="ftype" class="form-control">
                    <?php foreach ($types as $key => $meta) { ?>
                      <option value="<?php echo html_escape($key); ?>"
                              data-bound="<?php echo $meta['signer_bound'] ? '1' : '0'; ?>">
                        <?php echo html_escape($meta['label']); ?><?php
                          echo $meta['signer_bound'] ? '' : ' (not signer-bound)'; ?>
                      </option>
                    <?php } ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="control-label">Signer</label>
                  <select id="slot" class="form-control">
                    <?php if (!empty($signers)) {
                            foreach ($signers as $i => $s) {
                              $n = (int) $s['signing_order'] > 0 ? (int) $s['signing_order'] : ($i + 1); ?>
                        <option value="<?php echo $n; ?>">
                          Signer <?php echo $n; ?> &mdash; <?php echo html_escape($s['full_name']); ?>
                        </option>
                    <?php   }
                          } else {
                            for ($n = 1; $n <= 5; $n++) { ?>
                        <option value="<?php echo $n; ?>">Signer slot <?php echo $n; ?></option>
                    <?php   }
                          } ?>
                  </select>
                </div>
                <div class="checkbox">
                  <label><input type="checkbox" id="req" checked> Required</label>
                </div>

                <p class="text-muted small">
                  Drag on the page to draw. Drag a box to move it; drag its bottom-right corner to
                  resize. Changes are saved when you release.
                </p>

                <div id="msg"></div>
              </div>

              <div class="col-md-8">
                <div id="stage"
                     style="position:relative;background:#fff;border:1px solid #ccc;overflow:auto;max-height:70vh;">
                  <div id="page-canvas"
                       style="position:relative;background:#fdfdfd;background-image:
                              linear-gradient(#f0f0f0 1px,transparent 1px),
                              linear-gradient(90deg,#f0f0f0 1px,transparent 1px);
                              background-size:20px 20px;"></div>
                </div>
                <p class="text-muted small mtop10">
                  Showing page <span id="curpage">1</span>. Boxes on other pages are listed below but
                  not drawn here.
                </p>
              </div>
            </div>

            <hr>

            <h5>Draft placements <span class="label label-default"><?php echo count($drafts); ?></span></h5>

            <?php if (!$drafts) { ?>
              <p class="text-muted">
                No draft placements. A contract sent with no fields gives the signer nothing to do.
              </p>
            <?php } else { ?>
              <div class="cv-tablewrap"><div class="table-responsive">
              <table class="table table-condensed">
                <thead><tr>
                  <th>Page</th><th>Type</th><th>Signer</th><th>x</th><th>y</th><th>w</th><th>h</th>
                  <th>Scale</th><th>Required</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($drafts as $d) { ?>
                  <tr>
                    <td><?php echo (int) $d['page_number']; ?></td>
                    <td><?php echo html_escape(isset($types[$d['field_type']])
                                 ? $types[$d['field_type']]['label'] : $d['field_type']); ?></td>
                    <td><?php echo (int) $d['signer_slot'] > 0
                                 ? 'Slot ' . (int) $d['signer_slot'] : '&mdash;'; ?></td>
                    <td><?php echo (float) $d['x']; ?></td>
                    <td><?php echo (float) $d['y']; ?></td>
                    <td><?php echo (float) $d['width']; ?></td>
                    <td><?php echo (float) $d['height']; ?></td>
                    <td><?php echo (float) $d['editor_scale']; ?></td>
                    <td><?php echo empty($d['is_required']) ? 'No' : 'Yes'; ?></td>
                    <td>
                      <?php echo form_open(admin_url('payplex_contract_verification/signing/field_delete/'
                                     . (int) $contract['id']), array('style' => 'display:inline')); ?>
                        <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                        <button type="submit" class="btn btn-xs btn-danger">Remove</button>
                      <?php echo form_close(); ?>
                    </td>
                  </tr>
                <?php } ?>
                </tbody>
              </table>
              </div><p class="cv-swipe">Swipe to view more</p></div>
            <?php } ?>

            <?php echo form_open(admin_url('payplex_contract_verification/signing/fields_approve/'
                           . (int) $contract['id']), array('style' => 'display:inline')); ?>
              <button type="submit" class="btn btn-primary"
                      <?php echo empty($can_approve) ? 'disabled' : ''; ?>>
                Approve these placements
              </button>
            <?php echo form_close(); ?>

            <?php echo form_open(admin_url('payplex_contract_verification/signing/fields_discard/'
                           . (int) $contract['id']), array('style' => 'display:inline')); ?>
              <button type="submit" class="btn btn-default">Discard drafts</button>
            <?php echo form_close(); ?>

            <?php echo form_open(admin_url('payplex_contract_verification/signing/fields_copy/'
                           . (int) $contract['id']), array('style' => 'display:inline')); ?>
              <button type="submit" class="btn btn-default">Copy approved set for editing</button>
            <?php echo form_close(); ?>

            <?php if (empty($can_approve)) { ?>
              <p class="text-muted small mtop10">
                You can draw and save placements, but approving them needs
                <code>contract_signing_approve</code>. Editing and approving are separate acts.
              </p>
            <?php } ?>

            <hr>
            <h5>Approved placements
              <span class="label label-success"><?php echo count($approved); ?></span></h5>
            <p class="text-muted">
              This is what the send path reads. Approving replaces it entirely with the draft set,
              so what is approved is exactly what was on screen.
            </p>

            <?php } /* drafts_ready */ ?>

            <a class="btn btn-default"
               href="<?php echo admin_url('payplex_contract_verification/signing/contract/'
                                          . (int) $contract['id']); ?>">Back to contract signing</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
/*
 * The save form is rendered once and reused by the canvas for every create,
 * move and resize. A single token-bearing POST form is used rather than a
 * background XHR, so placement writes go through exactly the same CSRF path as every other
 * action on this module -- and there is no second, weaker way in.
 */
?>
<?php echo form_open(admin_url('payplex_contract_verification/signing/field_save/'
               . (int) $contract['id']), array('id' => 'saveform', 'style' => 'display:none')); ?>
  <input type="hidden" name="id" id="f_id">
  <input type="hidden" name="page_number" id="f_page">
  <input type="hidden" name="signer_slot" id="f_slot">
  <input type="hidden" name="field_type" id="f_type">
  <input type="hidden" name="x" id="f_x">
  <input type="hidden" name="y" id="f_y">
  <input type="hidden" name="width" id="f_w">
  <input type="hidden" name="height" id="f_h">
  <input type="hidden" name="editor_scale" id="f_scale">
  <input type="hidden" name="is_required" id="f_req">
<?php echo form_close(); ?>

<script>
(function () {
  var drafts = <?php echo json_encode(array_map(function ($d) {
      return array(
          'id'     => (int) $d['id'],
          'page'   => (int) $d['page_number'],
          'x'      => (float) $d['x'],
          'y'      => (float) $d['y'],
          'w'      => (float) $d['width'],
          'h'      => (float) $d['height'],
          'type'   => (string) $d['field_type'],
          'slot'   => (int) $d['signer_slot'],
          'req'    => empty($d['is_required']) ? 0 : 1,
          'scale'  => (float) $d['editor_scale'],
      );
  }, $drafts)); ?>;

  var MIN_W = <?php echo (float) $min_width; ?>;
  var MIN_H = <?php echo (float) $min_height; ?>;

  var canvas = document.getElementById('page-canvas');
  var msg    = document.getElementById('msg');
  if (!canvas) { return; }

  function num(id, dflt) {
    var el = document.getElementById(id);
    if (!el) { return dflt; }
    var v = parseFloat(el.value);
    return isFinite(v) ? v : dflt;
  }

  /* Displayed page box. /Rotate is clockwise when displayed, so at 90 and 270
     the page presents with width and height exchanged. Drawing on a canvas of
     the wrong shape is how a field ends up off a rotated page. */
  function pageBox() {
    var w = num('pgw', 595.3), h = num('pgh', 841.9), s = num('scale', 1);
    var r = parseInt(document.getElementById('rot').value, 10) || 0;
    if (r === 90 || r === 270) { var t = w; w = h; h = t; }
    return { w: w * s, h: h * s, rot: r, scale: s };
  }

  function say(text, kind) {
    msg.innerHTML = text ? '<div class="alert alert-' + (kind || 'warning')
                           + '" style="padding:6px 10px;margin-top:10px;">' + text + '</div>' : '';
  }

  function currentPage() { return Math.max(1, parseInt(num('page', 1), 10)); }

  function render() {
    var box = pageBox();
    canvas.style.width  = box.w + 'px';
    canvas.style.height = box.h + 'px';
    canvas.innerHTML = '';
    document.getElementById('curpage').textContent = currentPage();

    drafts.forEach(function (d) {
      if (d.page !== currentPage()) { return; }
      var el = document.createElement('div');
      el.className = 'cv-field';
      el.setAttribute('data-id', d.id);
      el.style.cssText = 'position:absolute;border:2px solid #03a9f4;background:rgba(3,169,244,.15);'
        + 'cursor:move;font-size:11px;overflow:hidden;'
        + 'left:' + d.x + 'px;top:' + d.y + 'px;width:' + d.w + 'px;height:' + d.h + 'px;';
      el.innerHTML = '<span style="pointer-events:none;padding:1px 3px;display:block;">'
        + d.type + (d.slot ? ' #' + d.slot : '') + '</span>'
        + '<span class="cv-resize" style="position:absolute;right:0;bottom:0;width:12px;height:12px;'
        + 'background:#03a9f4;cursor:nwse-resize;"></span>';
      canvas.appendChild(el);
    });
  }

  /* Bounds are checked here for immediate feedback AND again server-side.
     This one is a convenience; the server's is the control. */
  function withinPage(x, y, w, h) {
    var box = pageBox();
    return x >= 0 && y >= 0 && (x + w) <= box.w + 0.5 && (y + h) <= box.h + 0.5;
  }

  function bigEnough(w, h) {
    var s = num('scale', 1);
    return (w / s) >= MIN_W && (h / s) >= MIN_H;
  }

  function submit(id, page, x, y, w, h) {
    var typeEl = document.getElementById('ftype');
    var bound  = typeEl.options[typeEl.selectedIndex].getAttribute('data-bound') === '1';
    document.getElementById('f_id').value    = id || '';
    document.getElementById('f_page').value  = page;
    document.getElementById('f_slot').value  = bound ? document.getElementById('slot').value : 0;
    document.getElementById('f_type').value  = typeEl.value;
    document.getElementById('f_x').value     = Math.round(x * 1000) / 1000;
    document.getElementById('f_y').value     = Math.round(y * 1000) / 1000;
    document.getElementById('f_w').value     = Math.round(w * 1000) / 1000;
    document.getElementById('f_h').value     = Math.round(h * 1000) / 1000;
    document.getElementById('f_scale').value = num('scale', 1);
    document.getElementById('f_req').value   = document.getElementById('req').checked ? 1 : 0;
    document.getElementById('saveform').submit();
  }

  /* ---- draw a new box ---- */
  var drawing = null;
  canvas.addEventListener('mousedown', function (e) {
    if (e.target !== canvas) { return; }
    var r = canvas.getBoundingClientRect();
    drawing = { x: e.clientX - r.left, y: e.clientY - r.top, el: null };
    e.preventDefault();
  });

  /* ---- move / resize an existing box ---- */
  var acting = null;
  canvas.addEventListener('mousedown', function (e) {
    var fld = e.target.closest ? e.target.closest('.cv-field') : null;
    if (!fld) { return; }
    var r = canvas.getBoundingClientRect();
    acting = {
      id: parseInt(fld.getAttribute('data-id'), 10),
      el: fld,
      mode: e.target.classList.contains('cv-resize') ? 'resize' : 'move',
      sx: e.clientX - r.left, sy: e.clientY - r.top,
      ox: parseFloat(fld.style.left), oy: parseFloat(fld.style.top),
      ow: parseFloat(fld.style.width), oh: parseFloat(fld.style.height)
    };
    e.preventDefault();
    e.stopPropagation();
  }, true);

  document.addEventListener('mousemove', function (e) {
    var r = canvas.getBoundingClientRect();
    var mx = e.clientX - r.left, my = e.clientY - r.top;

    if (drawing) {
      if (!drawing.el) {
        drawing.el = document.createElement('div');
        drawing.el.style.cssText = 'position:absolute;border:2px dashed #ff9800;'
          + 'background:rgba(255,152,0,.12);pointer-events:none;';
        canvas.appendChild(drawing.el);
      }
      drawing.el.style.left   = Math.min(drawing.x, mx) + 'px';
      drawing.el.style.top    = Math.min(drawing.y, my) + 'px';
      drawing.el.style.width  = Math.abs(mx - drawing.x) + 'px';
      drawing.el.style.height = Math.abs(my - drawing.y) + 'px';
      return;
    }

    if (acting) {
      if (acting.mode === 'move') {
        acting.el.style.left = (acting.ox + (mx - acting.sx)) + 'px';
        acting.el.style.top  = (acting.oy + (my - acting.sy)) + 'px';
      } else {
        acting.el.style.width  = Math.max(4, acting.ow + (mx - acting.sx)) + 'px';
        acting.el.style.height = Math.max(4, acting.oh + (my - acting.sy)) + 'px';
      }
    }
  });

  document.addEventListener('mouseup', function () {
    if (drawing) {
      var el = drawing.el;
      drawing = null;
      if (!el) { return; }
      var x = parseFloat(el.style.left), y = parseFloat(el.style.top),
          w = parseFloat(el.style.width), h = parseFloat(el.style.height);
      el.parentNode.removeChild(el);

      if (!bigEnough(w, h)) {
        say('Too small: minimum is ' + MIN_W + ' &times; ' + MIN_H
            + ' points at scale 1. Nothing was saved.');
        return;
      }
      if (!withinPage(x, y, w, h)) {
        say('That box falls outside the page. Nothing was saved.');
        return;
      }
      submit(0, currentPage(), x, y, w, h);
      return;
    }

    if (acting) {
      var a = acting; acting = null;
      var x = parseFloat(a.el.style.left), y = parseFloat(a.el.style.top),
          w = parseFloat(a.el.style.width), h = parseFloat(a.el.style.height);

      if (x === a.ox && y === a.oy && w === a.ow && h === a.oh) { return; }

      if (!bigEnough(w, h) || !withinPage(x, y, w, h)) {
        a.el.style.left = a.ox + 'px'; a.el.style.top = a.oy + 'px';
        a.el.style.width = a.ow + 'px'; a.el.style.height = a.oh + 'px';
        say('That change would put the field off the page or below the minimum size. Reverted.');
        return;
      }
      submit(a.id, currentPage(), x, y, w, h);
    }
  });

  ['pgw', 'pgh', 'rot', 'scale', 'page'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) { el.addEventListener('change', render); }
  });

  render();
})();
</script>
<?php init_tail(); ?>
