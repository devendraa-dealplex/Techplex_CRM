<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-6 col-md-offset-3">
    <h4 class="tw-font-semibold tw-mb-3">Mark Attendance</h4>
    <div class="panel_s"><div class="panel-body">
        <ol class="breadcrumb" id="steps" style="margin-bottom:15px">
            <li class="active">1 Identify</li><li>2 Camera</li><li>3 Verify</li><li>4 Confirm</li>
        </ol>

        <div id="alertBox" class="alert" style="display:none"></div>

        <div id="s1">
            <?php if ($lock) { ?>
                <?php if ($employees) { $me = reset($employees); ?>
                    <input type="hidden" id="emp" value="<?= (int) $me['id']; ?>">
                    <p class="lead">Marking attendance for <strong><?= html_escape($me['full_name']); ?></strong></p>
                <?php } else { ?>
                    <div class="alert alert-warning">Your login is not linked to an active employee record. Ask an administrator.</div>
                <?php } ?>
            <?php } else { ?>
            <label>Select employee (search by name or ID)</label>
            <select id="emp" class="form-control selectpicker" data-live-search="true" data-width="100%">
                <option value="">Choose...</option>
                <?php foreach ($employees as $e) { ?><option value="<?= (int) $e['id']; ?>"><?= html_escape($e['full_name'] . ' (' . $e['emp_code'] . ')'); ?></option><?php } ?>
            </select>
            <?php } ?>
            <div class="row tw-mt-3">
                <div class="col-xs-6"><button class="btn btn-success btn-block btn-lg start" data-type="in">Check In</button></div>
                <div class="col-xs-6"><button class="btn btn-warning btn-block btn-lg start" data-type="out">Check Out</button></div>
            </div>
            <p class="text-muted tw-mt-2"><small>Your browser will ask for camera and location permission. Both are required; attendance cannot be marked without them, and uploaded/old photos are not accepted.</small></p>
        </div>

        <div id="s2" style="display:none">
            <p><span class="label label-info" id="kind"></span> <strong id="who"></strong><br><small class="text-muted" id="meta"></small></p>
            <video id="cam" autoplay playsinline muted style="width:100%;border-radius:8px;background:#000"></video>
            <canvas id="snap" style="display:none"></canvas>
            <button class="btn btn-success btn-block tw-mt-2" id="capture">Capture &amp; verify location</button>
            <button class="btn btn-default btn-block" id="cancel">Cancel</button>
        </div>

        <div id="s4" style="display:none;text-align:center">
            <h3 id="resTitle"></h3><p id="resMsg"></p>
            <button class="btn btn-primary" onclick="location.reload()">Done</button>
        </div>
    </div></div>
</div></div></div></div>

<?php init_tail(); ?>
<script>
(function () {
    // Path only: always post to the origin the page was opened on (http/https, IP/host), so cookies + CSRF match.
    var base = new URL('<?= admin_url(ATT_MODULE); ?>', location.href).pathname.replace(/\/$/, ''), token = null, empId = null, stream = null, kind = 'in';
    function show(step) { $('#s1,#s2,#s4').hide(); $('#s' + step).show(); $('#steps li').removeClass('active').eq(step === 4 ? 3 : step - 1).addClass('active'); }
    function err(m) { $('#alertBox').removeClass('alert-success').addClass('alert-danger').text(m).show(); }
    function stop() { if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; } }
    function fail(m) { stop(); token = null; $('#resTitle').addClass('text-danger').removeClass('text-success').text('Attendance not recorded'); $('#resMsg').text(m); show(4); }

    $('.start').on('click', function () {
        $('#alertBox').hide();
        kind = $(this).data('type');
        empId = $('#emp').val();
        if (!empId) { return err('Select an employee.'); }
        if (!window.isSecureContext) { return err('Camera and location need a secure page. Open this site as https://' + location.host + location.pathname + ' (accept the certificate warning) or use localhost.'); }
        if (!navigator.mediaDevices || !navigator.geolocation) { return err('This browser does not support camera and location.'); }
        $.post(base + '/begin', { employee_id: empId, type: kind }, function (r) {
            if (!r.ok) { return err(r.reason); }
            token = r.token;
            $('#kind').text(kind === 'out' ? 'CHECK OUT' : 'CHECK IN'); $('#who').text(r.employee.name); $('#meta').text(r.employee.workplace + ' · ' + r.employee.window);
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }).then(function (s) {
                stream = s; $('#cam')[0].srcObject = s; show(2);
            }, function (e) {
                var why = {
                    NotAllowedError: 'Camera access is blocked. Click the camera/lock icon in the address bar, set Camera to Allow, then retry.',
                    NotFoundError: 'No camera was found on this device.',
                    NotReadableError: 'The camera is in use by another app or tab. Close it and retry.',
                    SecurityError: 'Camera needs a secure page: open the site via https:// or http://localhost.'
                }[e && e.name] || ('Camera error: ' + (e && e.name));
                err('Camera verification required. ' + why);
            });
        }, 'json').fail(function () { err('Request failed.'); });
    });

    $('#cancel').on('click', function () { stop(); location.reload(); });

    $('#capture').on('click', function () {
        var v = $('#cam')[0], c = $('#snap')[0], btn = $(this);
        if (!v.videoWidth) { return err('Camera not ready.'); }
        c.width = v.videoWidth; c.height = v.videoHeight;
        c.getContext('2d').drawImage(v, 0, 0);
        var image = c.toDataURL('image/jpeg', 0.85);
        btn.prop('disabled', true).text('Getting location...');
        navigator.geolocation.getCurrentPosition(function (p) {
            btn.text('Verifying...');
            $.post(base + '/submit', { employee_id: empId, type: kind, token: token, image: image, lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }, function (r) {
                stop();
                $('#resTitle').toggleClass('text-success', !!r.ok).toggleClass('text-danger', !r.ok).text(r.ok ? (kind === 'out' ? 'Check-out recorded' : 'Check-in recorded') : 'Attendance not recorded');
                $('#resMsg').text(r.reason + (r.distance_m != null ? ' (' + r.distance_m + ' m from workplace)' : ''));
                show(4);
            }, 'json').fail(function () { fail('Request failed.'); });
        }, function (e) { btn.prop('disabled', false).text('Capture & verify location'); err(e.code === 1 ? 'Location permission required. Click the lock icon in the address bar, set Location to Allow, and retry.' : 'Location unavailable or timed out. Retry.'); },
        { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    });
})();
</script>
</body></html>
