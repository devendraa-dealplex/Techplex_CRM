<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head(); ?>
<div id="wrapper"><div class="content">
    <h4 class="tw-font-semibold tw-mb-3">Workplaces
        <?php if (staff_can('create', ATT_MODULE)) { ?><button class="btn btn-primary pull-right" onclick="attWp()">Add Workplace</button><?php } ?></h4>
    <div class="panel_s"><div class="panel-body table-responsive">
    <table class="table table-striped">
        <thead><tr><th>Name</th><th>Address</th><th>Pincode</th><th>Coordinates</th><th>Radius</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $w) { ?>
            <tr>
                <td><?= html_escape($w['name']); ?></td><td><?= html_escape($w['address']); ?></td><td><?= html_escape($w['pincode']); ?></td>
                <td><?= html_escape($w['latitude'] . ', ' . $w['longitude']); ?></td><td><?= (int) $w['radius_m']; ?> m</td>
                <td class="text-right">
                    <?php if (staff_can('edit', ATT_MODULE)) { ?><button class="btn btn-default btn-xs" data-wp='<?= html_escape(json_encode($w)); ?>' onclick="attWp(this)">Edit</button><?php } ?>
                    <?php if (staff_can('delete', ATT_MODULE)) { echo form_open(admin_url(ATT_MODULE . '/workplace_delete/' . $w['id']), ['style' => 'display:inline', 'onsubmit' => "return confirm('Delete this workplace?')"]); ?>
                        <button class="btn btn-danger btn-xs">Delete</button><?= form_close(); } ?>
                </td>
            </tr>
        <?php } if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted">No workplaces yet.</td></tr><?php } ?>
        </tbody>
    </table>
    </div></div>
</div></div>

<div class="modal fade" id="attWpModal"><div class="modal-dialog"><div class="modal-content">
<?= form_open(admin_url(ATT_MODULE . '/workplace_save'), ['id' => 'attWpForm']); ?>
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">Workplace / Geofence</h4></div>
    <div class="modal-body">
        <input type="hidden" name="id">
        <div class="form-group"><label>Name *</label><input name="name" class="form-control" required></div>
        <div class="row">
            <div class="col-sm-8 form-group"><label>Address</label><input name="address" class="form-control"></div>
            <div class="col-sm-4 form-group"><label>Pincode</label><input name="pincode" class="form-control" maxlength="12"></div>
        </div>
        <div class="row">
            <div class="col-sm-4 form-group"><label>Latitude *</label><input name="latitude" class="form-control" required></div>
            <div class="col-sm-4 form-group"><label>Longitude *</label><input name="longitude" class="form-control" required></div>
            <div class="col-sm-4 form-group"><label>Radius (m) *</label><input type="number" name="radius_m" min="10" max="5000" value="100" class="form-control" required></div>
        </div>
        <button type="button" class="btn btn-default btn-sm" onclick="attHere()">Use my current location</button>
        <span class="text-muted" id="attHereMsg"></span>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
<?= form_close(); ?>
</div></div></div>

<?php init_tail(); ?>
<script>
function attWp(btn) {
    var f = $('#attWpForm')[0], w = btn && btn.dataset.wp ? JSON.parse(btn.dataset.wp) : null;
    f.reset();
    f.id.value = w ? w.id : '';
    if (w) { ['name', 'address', 'pincode', 'latitude', 'longitude', 'radius_m'].forEach(function (k) { f[k].value = w[k] || ''; }); }
    $('#attHereMsg').text('');
    $('#attWpModal').modal('show');
}
function attHere() {
    var f = $('#attWpForm')[0];
    if (!window.isSecureContext) {
        $('#attHereMsg').text('Browsers only ask for location on https:// or localhost. Open this page as https://' + location.host + location.pathname + ' (accept the certificate warning) and retry.');
        return;
    }
    if (!navigator.geolocation) { $('#attHereMsg').text('Location is not supported by this browser.'); return; }
    $('#attHereMsg').text('Waiting for you to allow location in the browser prompt...');
    navigator.geolocation.getCurrentPosition(function (p) {
        f.latitude.value = p.coords.latitude.toFixed(7);
        f.longitude.value = p.coords.longitude.toFixed(7);
        var acc = 'Accuracy ~' + Math.round(p.coords.accuracy) + ' m';
        $('#attHereMsg').text(acc + ' · finding address...');
        // Reverse-geocode with OpenStreetMap Nominatim (free, no key; fair-use limits apply).
        fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&lat=' + p.coords.latitude + '&lon=' + p.coords.longitude)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.display_name) { f.address.value = d.display_name; if (d.address && d.address.postcode) { f.pincode.value = d.address.postcode; } $('#attHereMsg').text(acc); }
                else { $('#attHereMsg').text(acc + ' · address not found, enter it manually'); }
            })
            .catch(function () { $('#attHereMsg').text(acc + ' · could not fetch address, enter it manually'); });
    }, function (e) {
        $('#attHereMsg').text(e.code === 1 ? 'Location is blocked. Click the lock/location icon in the address bar, set Location to Allow, then retry.'
            : e.code === 3 ? 'Location request timed out. Retry.' : 'Location unavailable on this device.');
    }, { enableHighAccuracy: true, timeout: 20000 });
}
</script>
</body></html>
