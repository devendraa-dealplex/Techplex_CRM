<?php defined('BASEPATH') or exit('No direct script access allowed');
/** Review modal shared by the Video KYC console and the customer's KYC tab. Expects $can. */ ?>
<!-- ======================================================= Video review modal -->
<div class="modal fade" id="kyc-review-modal" tabindex="-1" role="dialog" aria-labelledby="kyc-review-title">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
      <h4 class="modal-title" id="kyc-review-title">Review Video KYC</h4>
    </div>
    <div class="modal-body">
      <div class="row">
        <div class="col-md-7">
          <div class="kyc-video-wrap" id="kyc-video-wrap"></div>
          <div class="kyc-video-meta" id="kyc-video-meta"></div>
        </div>
        <div class="col-md-5">
          <h5 class="kyc-sec">Customer</h5>
          <dl class="kyc-dl" id="kyc-customer"></dl>

          <h5 class="kyc-sec">Script they were asked to read <span class="label label-default" id="kyc-script-lang"></span></h5>
          <blockquote class="kyc-script" id="kyc-script"></blockquote>

          <div id="kyc-decision-block">
            <h5 class="kyc-sec">Verification checks</h5>
            <div id="kyc-checklist">
              <label><input type="checkbox" data-check="face_visible"> Face is clearly visible and matches the customer</label>
              <label><input type="checkbox" data-check="script_read"> Customer read the full script accurately</label>
              <label><input type="checkbox" data-check="audio_clear"> Audio is clear and understandable</label>
              <label><input type="checkbox" data-check="single_person"> Only one person is in the frame</label>
              <label><input type="checkbox" data-check="live_person"> Looks like a live person (not a photo, screen or recording)</label>
            </div>
            <div class="form-group mtop10">
              <label for="kyc-notes">Note to the customer <small class="text-muted">(required for Reject / Ask again; shown to the customer)</small></label>
              <textarea id="kyc-notes" class="form-control" rows="3" maxlength="2000"></textarea>
            </div>
          </div>
          <div id="kyc-decided" style="display:none"></div>
          <div id="kyc-review-msg"></div>

          <h5 class="kyc-sec">Delivery log</h5>
          <ul class="kyc-log" id="kyc-notif-log"></ul>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      <?php if ($can['review']): ?>
      <span id="kyc-review-actions">
        <button type="button" class="btn btn-danger" id="kyc-reject"><i class="fa fa-times"></i> Reject</button>
        <button type="button" class="btn btn-warning" id="kyc-resubmit"><i class="fa fa-repeat"></i> Ask again</button>
        <button type="button" class="btn btn-success" id="kyc-approve"><i class="fa fa-check"></i> Approve</button>
      </span>
      <?php endif; ?>
    </div>
  </div></div>
</div>
