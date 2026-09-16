<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * One piece of evidence, viewed.
 *
 * The bytes are NOT in this page. The page carries a description and, for a
 * recording, a <video> element pointing at the streaming route — which asks
 * Contract_authz again on every request. Embedding the file, or a link to it,
 * would create a URL that outlives the permission that produced it.
 *
 * The watermark is rendered over the player rather than beside it. It does not
 * stop a screen recording, because nothing does; it makes a leaked frame
 * traceable to one person and one session, which is the realistic control.
 */
?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<style>
/* Scoped to this screen. The overlay must not be selectable or clickable —
   selectable text can be deleted from the DOM before a screenshot, and a
   clickable overlay swallows the player's own controls. */
.cv-evidence-stage { position: relative; max-width: 100%; }
.cv-evidence-stage video { width: 100%; max-width: 100%; display: block; background: #000; }
.cv-watermark {
  position: absolute; inset: 0; pointer-events: none; user-select: none;
  display: flex; align-items: center; justify-content: center;
  font: 600 13px/1.4 system-ui, sans-serif; color: rgba(255,255,255,.42);
  text-shadow: 0 1px 2px rgba(0,0,0,.6); transform: rotate(-18deg); letter-spacing: .04em;
}
.cv-watermark span { padding: 4px 10px; border: 1px solid rgba(255,255,255,.28); border-radius: 3px; }
</style>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s cv-exec"><div class="panel-body">

      <h4 class="no-mtop">
        <?php echo html_escape((string) $meta[$evidence_type]['label']); ?>
      </h4>
      <p class="text-muted small"><?php echo html_escape((string) $meta[$evidence_type]['means']); ?></p>

      <?php if (empty($evidence)) { ?>
        <div class="alert alert-warning">Nothing is stored for this evidence type.</div>
      <?php } else { ?>

        <?php if ($evidence_type === 'video_kyc_recording') { ?>
          <div class="cv-evidence-stage">
            <video controls controlsList="nodownload" disablepictureinpicture preload="none"
                   src="<?php echo admin_url('payplex_contract_verification/signing/evidence_stream/'
                         . (int) $contract['id']); ?>"></video>
            <div class="cv-watermark" aria-hidden="true">
              <span><?php echo html_escape((string) $watermark); ?></span>
            </div>
          </div>
          <p class="text-muted small mtop10">
            Downloading is a separate permission and is refused for recordings until an approved
            lawful basis and retention period exist. Viewing is logged, and so is every refusal.
          </p>
        <?php } ?>

        <h5 class="mtop20">About this file</h5>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <tr><td>Type</td><td><?php echo html_escape((string) $evidence['content_type']); ?></td></tr>
          <tr><td>Size</td><td><?php echo (int) $evidence['bytes']; ?> bytes</td></tr>
          <tr><td>Hash</td>
              <td><code><?php echo html_escape((string) $evidence['sha256_short']); ?></code>
                <?php if (!empty($evidence['hash_verified'])) { ?>
                  &mdash; verified
                <?php } else { ?>
                  <span class="text-warning">&mdash; not verified</span>
                <?php } ?>
              </td></tr>
          <tr><td>Encrypted at rest</td>
              <td><?php echo !empty($evidence['is_encrypted']) ? 'Yes' : 'No'; ?></td></tr>
        </table>
        </div><p class="cv-swipe">Swipe to view more</p></div>

      <?php } ?>

      <p class="mtop20"><a href="<?php echo html_escape((string) $back_url); ?>">Back</a></p>

    </div></div>
  </div></div>
</div></div>
