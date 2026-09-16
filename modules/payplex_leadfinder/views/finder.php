<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Find Business Leads — search, map and results.
 *
 * THE KEY IN THIS PAGE
 * --------------------
 * The Maps JavaScript API loads through a `<script src="...&key=...">` tag, so
 * when a map is drawn the browser map key IS in this page's source. There is no
 * loader that hides it and it would be dishonest to imply otherwise.
 *
 * What makes that acceptable is that it is a DIFFERENT credential from the one
 * that calls Places from the server: restricted by HTTP referrer to this host,
 * enabled only for Maps JavaScript, and on its own billing budget. The server
 * Places key never appears here, and neither does the Perfex core key. There is
 * no fallback between them — a fallback would put a key with a large budget and
 * no referrer restriction into a public page, which is the exact failure the
 * two-key split exists to prevent.
 *
 * The controller decides whether a key may be rendered, by exact route match.
 * This view renders whatever it was given and, when it was given nothing, says
 * so in words that name the fix.
 *
 * WHY THE LIST WORKS WITHOUT THE MAP
 * ----------------------------------
 * Every map failure state below carries `list_usable`, and every one of them is
 * true. A missing map must not take the results table with it: the map is how
 * you see where these businesses are, the table is how you work them, and only
 * one of those is the job.
 */
?>
<?php init_head(); ?>

<style>
/*
 * Layout is CSS, not JavaScript.
 *
 * The two-column split becomes one column at 991px, which is where the theme's
 * own grid folds. Doing it here rather than in script means the page is correct
 * before any script runs and stays correct if one fails — and the map pane, the
 * one thing that genuinely depends on a third-party script, is the only part
 * that can go missing.
 */
.lf-split { display: flex; flex-wrap: wrap; gap: 15px; align-items: stretch; }
.lf-split > .lf-map-pane  { flex: 1 1 420px; min-width: 0; }
.lf-split > .lf-list-pane { flex: 1 1 420px; min-width: 0; }
.lf-map { width: 100%; height: 460px; border-radius: 4px; background: #f4f5f7; }
.lf-map-msg { padding: 18px; }
.lf-legend { display: flex; flex-wrap: wrap; gap: 10px; margin: 8px 0 0; padding: 0; list-style: none; }
.lf-legend li { display: flex; align-items: center; gap: 5px; font-size: 12px; color: #6b7280; }
.lf-swatch { width: 11px; height: 11px; border-radius: 50%; display: inline-block; }
.lf-attrib { font-size: 12px; font-weight: 400; color: #6b7280; margin-top: 6px; }
.lf-row-actions form { display: inline-block; margin: 0 2px 2px 0; }
.lf-swipe-hint { display: none; }
@media (max-width: 991px) {
  .lf-map { height: 300px; }
  /* A horizontally scrolling table is invisible on a phone unless it is
     announced. The hint appears only where the table actually overflows. */
  .lf-swipe-hint { display: block; font-size: 12px; color: #6b7280; margin-bottom: 4px; }
}
@media (max-width: 767px) {
  .lf-split > .lf-map-pane { flex-basis: 100%; order: 2; }
  .lf-split > .lf-list-pane { flex-basis: 100%; order: 1; }
}
</style>

<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>

      <?php if (!empty($simulated_mode)) { ?>
        <div class="alert alert-danger">
          <strong>Simulated mode is on.</strong>
          <?php echo html_escape(Leadfinder_transport::bannerText()); ?>
        </div>
      <?php } ?>

      <?php if (!empty($blocked)) { ?>
        <div class="alert alert-danger"><?php echo html_escape($blocked); ?></div>
      <?php } ?>

      <?php if (!empty($error)) { ?>
        <div class="alert alert-warning"><?php echo html_escape($error); ?></div>
      <?php } ?>

      <?php if (!empty($notice)) { ?>
        <div class="alert alert-info"><?php echo html_escape($notice); ?></div>
      <?php } ?>

      <?php
      /*
       * What is left, above the button that spends it.
       *
       * The wording says "estimate" on purpose. This figure is read without a
       * lock, so two employees can both see "1 remaining" and only one of them
       * will get it — the reservation decides, not this line. Saying so here is
       * cheaper than an employee reporting a bug when the number they were
       * shown turns out not to have been a promise.
       */
      if (!empty($allowance)) { ?>
        <div class="alert alert-default" style="border:1px solid #eee;">
          <strong>Allowance:</strong> <?php echo html_escape($allowance['line']); ?>
          <?php if ($allowance['cost'] !== null) { ?>
            <span class="text-muted">
              &middot; estimated <?php echo html_escape($allowance['currency']); ?>
              <?php echo html_escape($allowance['cost']); ?> per search at the rate configured here.
            </span>
          <?php } else { ?>
            <span class="text-muted">
              &middot; no per-call rate is configured, so no cost estimate is shown.
            </span>
          <?php } ?>
          <br><span class="text-muted small">
            Read without a lock, so it is an estimate: if two people search at the
            same moment, only one gets the last call.
          </span>
        </div>
      <?php } ?>

      <?php if (empty($profiles)) { ?>
        <div class="alert alert-info">
          No API connection is assigned to you. An administrator assigns
          connections under <em>Configure Lead Finder API</em>.
        </div>
      <?php } else { ?>
      <?php echo form_open('', array('method' => 'post')); ?>
        <?php
        /* The idempotency token. A refresh or a double click re-posts the same
           value, which resolves to the reservation already made rather than
           spending a second call. */ ?>
        <input type="hidden" name="request_token" value="<?php echo html_escape($request_token); ?>">
        <div class="row">
          <div class="col-md-4"><?php echo render_input('keyword', 'Business keyword'); ?></div>
          <div class="col-md-4"><?php echo render_input('category', 'Business category'); ?></div>
          <div class="col-md-4"><?php echo render_input('product', 'Product / service'); ?></div>
        </div>
        <div class="row">
          <div class="col-md-3"><?php echo render_input('city', 'City'); ?></div>
          <div class="col-md-3"><?php echo render_input('state', 'State'); ?></div>
          <div class="col-md-3"><?php echo render_input('pin_code', 'PIN code'); ?></div>
          <div class="col-md-3"><?php echo render_input('radius_m', 'Radius (metres)', '', 'number'); ?></div>
        </div>
        <div class="row">
          <div class="col-md-3">
            <?php echo render_input('max_results', 'Maximum results', '20', 'number'); ?>
            <p class="text-muted small">Google returns at most 60 across all pages.</p>
          </div>
          <div class="col-md-3"><?php echo render_input('campaign', 'Campaign'); ?></div>
          <div class="col-md-3"><?php echo render_input('language', 'Preferred language'); ?></div>
          <div class="col-md-3">
            <label for="profile_id" class="control-label">API connection</label>
            <select name="profile_id" id="profile_id" class="form-control">
              <?php foreach ($profiles as $p) { ?>
                <option value="<?php echo (int) $p['id']; ?>"><?php echo html_escape($p['name']); ?></option>
              <?php } ?>
            </select>
          </div>
        </div>
        <button type="submit" class="btn btn-info mtop15"
                <?php echo !empty($blocked) ? 'disabled' : ''; ?>>Search</button>
      <?php echo form_close(); ?>
      <?php } ?>
    </div></div>
  </div></div>

  <?php if (!empty($results)) { ?>
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">

      <p>
        <strong><?php echo count($results); ?></strong> result(s).
        These are in the <a href="<?php echo admin_url('payplex_leadfinder/finder/queue'); ?>">Prospect
        Verification Queue</a> only &mdash; nothing has been added to Leads.
        <?php
        /*
         * Suppressed results are counted, not listed. Listing them would be a
         * way of asking "is this business on the suppression list", which is
         * the question the whole HMAC design exists to make unanswerable from
         * the outside.
         */
        if (!empty($suppressed_count)) { ?>
          <br><span class="text-muted">
            <?php echo (int) $suppressed_count; ?> result(s) were hidden because that business was
            previously rejected or asked not to be contacted.
          </span>
        <?php } ?>
      </p>

      <div class="lf-split">

        <div class="lf-map-pane">
          <?php if (!empty($map['allowed'])) { ?>
            <div id="lf-map" class="lf-map"
                 data-zoom="<?php echo (int) $map_zoom; ?>"
                 data-cluster-min="<?php echo (int) $map_clustering['min_markers']; ?>"
                 data-cluster-maxzoom="<?php echo (int) $map_clustering['max_zoom']; ?>"></div>
            <?php
            /*
             * The markers are emitted as JSON, not built in PHP string
             * concatenation, and they carry no contact details — a name, a
             * point, a state and an id. The drawer fetches the rest, and the
             * paid detail call is never made by opening it.
             */
            $markers = array();
            foreach ($results as $r) {
                if ($r['latitude'] === null || $r['longitude'] === null) { continue; }

                $sup = isset($suppression[(int) $r['id']]) ? $suppression[(int) $r['id']] : array();

                $markers[] = array(
                    'id'    => (int) $r['id'],
                    'name'  => (string) $r['business_name'],
                    'lat'   => (float) $r['latitude'],
                    'lng'   => (float) $r['longitude'],
                    'state' => Leadfinder_map::stateFor(array(
                        'status'         => (string) $r['status'],
                        'claimed_by'     => (int) $r['claimed_by'],
                        'saved_to_queue' => isset($saved[(int) $r['id']]),
                        'dupe_possible'  => (isset($sup['confidence']) && $sup['confidence'] === 'possible')
                                            || (string) $r['dupe_state'] === 'possible',
                    )),
                );
            }
            ?>
            <script type="application/json" id="lf-markers"><?php
              echo json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
                                       | JSON_HEX_APOS | JSON_HEX_QUOT);
            ?></script>
            <script type="application/json" id="lf-marker-states"><?php
              echo json_encode($map_states, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            ?></script>
          <?php } else { ?>
            <?php
            /*
             * No key, no map, and the page says which. `list_usable` is true
             * for every one of these states, and the table below proves it.
             */
            ?>
            <div class="lf-map">
              <div class="lf-map-msg">
                <strong><?php echo html_escape($map_error['title']); ?></strong>
                <p class="text-muted"><?php echo html_escape($map_error['body']); ?></p>
                <p class="text-muted small"><?php echo html_escape($map_error['action']); ?></p>
                <p class="text-muted small">
                  Reason: <code><?php echo html_escape((string) $map['reason']); ?></code>
                </p>
              </div>
            </div>
          <?php } ?>

          <ul class="lf-legend">
            <?php foreach ($map_states as $key => $meta) { ?>
              <li>
                <span class="lf-swatch" style="background: <?php echo html_escape($meta['color']); ?>"></span>
                <?php echo html_escape($meta['label']); ?>
              </li>
            <?php } ?>
          </ul>

          <?php
          /*
           * Attribution is a term of the Places API, not decoration, and it is
           * rendered whether or not the map drew: the results below came from
           * Google either way.
           */
          if (!empty($map_attribution['required'])) { ?>
            <p class="lf-attrib" translate="no"><?php echo html_escape($map_attribution['text']); ?></p>
          <?php } ?>
        </div>

        <div class="lf-list-pane">
          <?php
          /*
           * The bulk form wraps the table so the checkboxes belong to it. Every
           * id it submits is authorised one at a time in the model — this form
           * decides which rows are OFFERED, never which rows may be changed.
           */
          echo form_open(admin_url('payplex_leadfinder/finder/bulk_save')); ?>
          <p class="lf-swipe-hint">Swipe the table sideways to see every column.</p>
          <div class="table-responsive">
            <table class="table table-striped">
              <thead><tr>
                <th style="width:24px;"></th>
                <th>Business</th>
                <th class="hidden-xs">Category</th>
                <th class="hidden-xs hidden-sm">Address</th>
                <th class="hidden-xs">Status</th>
                <th></th>
              </tr></thead>
              <tbody>
              <?php foreach ($results as $r) { ?>
                <tr>
                  <td><input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>"></td>
                  <td>
                    <?php echo html_escape($r['business_name']); ?>
                    <?php if (isset($saved[(int) $r['id']])) { ?>
                      <span class="label label-success">Saved</span>
                    <?php } ?>
                    <?php
                    /*
                     * A phone-only suppression match is shown, never acted on.
                     * One switchboard serves a shopping centre, a franchise
                     * group and everybody else on that line, so treating a
                     * phone hit as proof would silently suppress businesses
                     * nobody rejected.
                     */
                    if (isset($suppression[(int) $r['id']])
                        && $suppression[(int) $r['id']]['confidence'] === 'possible') { ?>
                      <span class="label label-warning"
                            title="A previously rejected business shares this contact detail. Check before working it.">
                        Possible match
                      </span>
                    <?php } ?>
                    <div class="visible-xs-block text-muted small">
                      <?php echo html_escape((string) $r['category']); ?>
                    </div>
                  </td>
                  <td class="hidden-xs"><?php echo html_escape((string) $r['category']); ?></td>
                  <td class="hidden-xs hidden-sm"><?php echo html_escape((string) $r['address']); ?></td>
                  <td class="hidden-xs"><?php echo html_escape((string) $r['business_status']); ?></td>
                  <td class="lf-row-actions">
                    <?php if (!empty($r['id']) && !isset($saved[(int) $r['id']])) {
                        echo form_open(admin_url('payplex_leadfinder/finder/save/' . (int) $r['id'])); ?>
                      <input type="hidden" name="return_to" value="index">
                      <button type="submit" class="btn btn-default btn-xs"
                              title="Add to your list. This does not create a CRM lead.">Save</button>
                      <?php echo form_close();
                    } ?>
                    <?php if (!empty($r['id'])) { ?>
                      <a class="btn btn-default btn-xs"
                         href="<?php echo admin_url('payplex_leadfinder/finder/claim/' . (int) $r['id']); ?>">Claim</a>
                    <?php } ?>
                    <?php if (!empty($r['google_maps_uri'])) { ?>
                      <a class="btn btn-link btn-xs" target="_blank" rel="noopener"
                         href="<?php echo html_escape($r['google_maps_uri']); ?>">Open in Google Maps</a>
                    <?php } ?>
                  </td>
                </tr>
              <?php } ?>
              </tbody>
            </table>
          </div>

          <button type="submit" class="btn btn-default">Save selected to my list</button>
          <span class="text-muted small">
            Saving adds prospects to your own list. It creates no CRM lead and contacts nobody.
          </span>
          <?php echo form_close(); ?>
        </div>

      </div>

      <p class="text-muted small mtop15"><?php echo html_escape($email_note); ?></p>
    </div></div>
  </div></div>
  <?php } ?>

  <?php if (!empty($attribution) && empty($results)) { ?>
    <div class="row"><div class="col-md-12">
      <p class="lf-attrib" translate="no">Google Maps</p>
    </div></div>
  <?php } ?>

</div></div>

<?php if (!empty($results) && !empty($map['allowed'])) { ?>
<script>
/*
 * The map loader.
 *
 * The key is in this tag, which is how the Maps JavaScript API works — see the
 * comment at the top of this file for why that is acceptable for THIS key and
 * would not be for the server Places key.
 *
 * Everything below fails quietly into the no-map state: if Google does not
 * load, `lfInitMap` never runs, the map area stays as it is, and the table and
 * every row action keep working. That is the whole reason the list is not
 * rendered by the map.
 */
window.lfInitMap = function () {
  var el = document.getElementById('lf-map');
  if (!el || !window.google || !google.maps) { return; }

  var markers = JSON.parse(document.getElementById('lf-markers').textContent || '[]');
  var states  = JSON.parse(document.getElementById('lf-marker-states').textContent || '{}');

  var map = new google.maps.Map(el, {
    zoom: parseInt(el.getAttribute('data-zoom'), 10) || 12,
    center: markers.length ? { lat: markers[0].lat, lng: markers[0].lng } : { lat: 20.59, lng: 78.96 },
    mapTypeControl: false,
    streetViewControl: false
  });

  var bounds = new google.maps.LatLngBounds();

  markers.forEach(function (m) {
    var s = states[m.state] || states['new'];

    var pin = new google.maps.Marker({
      position: { lat: m.lat, lng: m.lng },
      map: map,
      title: m.name,
      zIndex: s ? s.z : 10,
      icon: {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 7,
        fillColor: s ? s.color : '#2563eb',
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 2
      }
    });

    /*
     * The info window carries the name and the state, and nothing else. It
     * never triggers a Place Details fetch: that call bills at a higher rate
     * and is an explicit, confirmed action, not something that happens because
     * somebody clicked a pin to see where it was.
     */
    var info = new google.maps.InfoWindow({
      content: '<strong>' + (m.name || '').replace(/[<>&]/g, '') + '</strong><br>'
             + '<span style="color:#6b7280">' + (s ? s.label : '') + '</span>'
    });

    pin.addListener('click', function () { info.open(map, pin); });

    bounds.extend(pin.getPosition());
  });

  if (markers.length > 1) { map.fitBounds(bounds); }
};
</script>
<script async defer
        src="https://maps.googleapis.com/maps/api/js?key=<?php echo rawurlencode($map_key); ?>&callback=lfInitMap"></script>
<?php } ?>

<?php init_tail(); ?>
</body></html>
