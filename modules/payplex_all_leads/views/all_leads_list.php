<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">

            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:14px">
              <h4 class="no-margin" style="color:#12507F;font-weight:600">
                All Leads <span class="text-muted" style="font-size:14px">(<?php echo (int) $total; ?>)</span>
              </h4>
              <a href="<?php echo admin_url('leads'); ?>" class="btn btn-default btn-sm">Open Leads module</a>
            </div>

            <!-- Filters -->
            <div class="row" style="margin-bottom:8px">
              <div class="col-md-3 col-sm-4">
                <select id="pp_f_status" class="form-control input-sm">
                  <option value="">All Statuses</option>
                  <?php foreach ($statuses as $s): ?>
                    <option value="<?php echo html_escape($s['name']); ?>"><?php echo html_escape($s['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3 col-sm-4">
                <select id="pp_f_source" class="form-control input-sm">
                  <option value="">All Sources</option>
                  <?php foreach ($sources as $s): ?>
                    <option value="<?php echo html_escape($s['name']); ?>"><?php echo html_escape($s['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3 col-sm-4">
                <select id="pp_f_assigned" class="form-control input-sm">
                  <option value="">All Assigned</option>
                  <?php foreach ($assigned as $a): ?>
                    <?php if (trim($a['an']) !== ''): ?>
                      <option value="<?php echo html_escape(trim($a['an'])); ?>"><?php echo html_escape(trim($a['an'])); ?></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3 col-sm-12">
                <div class="dropdown">
                  <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" id="pp_colbtn" style="width:100%">
                    Columns <span class="caret"></span>
                  </button>
                  <ul class="dropdown-menu" id="pp_colmenu" style="padding:6px 12px;max-height:260px;overflow:auto" onclick="event.stopPropagation()">
                    <!-- column toggles injected by JS -->
                  </ul>
                </div>
              </div>
            </div>
            <div class="row" style="margin-bottom:10px">
              <div class="col-md-3 col-sm-4">
                <input type="date" id="pp_f_from" class="form-control input-sm" title="Added from">
              </div>
              <div class="col-md-3 col-sm-4">
                <input type="date" id="pp_f_to" class="form-control input-sm" title="Added to">
              </div>
              <div class="col-md-2 col-sm-4">
                <span class="text-muted" style="font-size:11px;line-height:30px">Added date range</span>
              </div>
              <div class="col-md-4 col-sm-12" style="text-align:right">
                <button type="button" id="pp_clear" class="btn btn-default btn-sm">Clear filters</button>
              </div>
            </div>

            <!-- Bulk actions bar -->
            <div id="pp_bulkbar" style="display:none;background:#f4f8fb;border:1px solid #dbe6ef;border-radius:4px;padding:8px 12px;margin-bottom:10px">
              <span id="pp_selcount" style="font-weight:600;color:#12507F">0 selected</span>
              <span id="pp_selall_wrap" style="display:none">
                &nbsp;&mdash;&nbsp;<a href="#" id="pp_selall">Select all <span id="pp_selall_n">0</span> matching</a>
                <a href="#" id="pp_selall_clear" style="display:none">Clear selection</a>
              </span>
              &nbsp;
              <button type="button" class="btn btn-info btn-xs" id="pp_bulk_mail">Email selected</button>
              <button type="button" class="btn btn-default btn-xs" id="pp_bulk_copyemail">Copy emails</button>
              <button type="button" class="btn btn-default btn-xs" id="pp_bulk_copyphone">Copy phones</button>
              <span class="text-muted" style="font-size:11px;margin-left:6px">Opens your own mail app — no auto-blasting.</span>
            </div>

            <div style="margin-bottom:8px">
              <a href="#" id="pp_export" class="btn btn-success btn-sm">Export filtered CSV</a>
              <span class="text-muted" style="font-size:11px">Exports every lead matching the current filters/search (all pages).</span>
            </div>

            <div id="pp_load_error" class="alert alert-danger hide"></div>

            <div class="table-responsive">
              <table id="pp_all_leads" class="table table-striped table-bordered" style="width:100%">
                <thead>
                  <tr>
                    <th style="width:24px"><input type="checkbox" id="pp_checkall"></th>
                    <th>#</th>
                    <th>Name</th>
                    <th>Company</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Source</th>
                    <th>Assigned</th>
                    <?php if (!empty($has_value)): ?><th>Value</th><?php endif; ?>
                    <th>Tags</th>
                    <th>Last Contact</th>
                    <th>Added</th>
                    <th>Converted</th>
                    <th style="width:110px">Actions</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>

<script>
(function() {
  "use strict";
  var HAS_VALUE = <?php echo !empty($has_value) ? 'true' : 'false'; ?>;
  var AJAX_URL     = '<?php echo admin_url('payplex_all_leads/all_leads/ajax'); ?>';
  var CONTACTS_URL = '<?php echo admin_url('payplex_all_leads/all_leads/contacts'); ?>';
  var EXPORT_URL   = '<?php echo admin_url('payplex_all_leads/all_leads/export'); ?>';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
  }
  function esc(s) {
    return (s === null || s === undefined) ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function encAttr(s) { return encodeURIComponent(s === null || s === undefined ? '' : String(s)); }

  ready(function() {
    if (typeof jQuery === 'undefined' || !jQuery.fn.DataTable) { return; }
    var $ = jQuery;

    // selection state (persists across server-side page changes)
    var selected = {};      // id -> {email, phone}
    var allMatching = false;

    function filterParams() {
      return {
        f_status:   $('#pp_f_status').val() || '',
        f_source:   $('#pp_f_source').val() || '',
        f_assigned: $('#pp_f_assigned').val() || '',
        f_from:     $('#pp_f_from').val() || '',
        f_to:       $('#pp_f_to').val() || ''
      };
    }

    var textRender = $.fn.dataTable.render.text();

    var columns = [
      { data: 'id', orderable: false, searchable: false, render: function(d, t, row) {
          return '<input type="checkbox" class="pp_rowchk" data-id="' + esc(row.id) +
                 '" data-email="' + esc(row.email) + '" data-phone="' + esc(row.phone) + '">';
        } },
      { data: 'id' },
      { data: 'name', render: function(d, t, row) {
          var nm = (d && String(d).length) ? esc(d) : '(no name)';
          return '<a href="' + esc(row.detail_url) + '">' + nm + '</a>';
        } },
      { data: 'company', render: textRender },
      { data: 'email', render: textRender },
      { data: 'phone', render: textRender },
      { data: 'status_name', render: textRender },
      { data: 'source_name', render: textRender },
      { data: 'assigned_name', render: textRender }
    ];
    if (HAS_VALUE) { columns.push({ data: 'lead_value', render: textRender }); }
    columns.push({ data: 'tags', render: textRender });
    columns.push({ data: 'lastcontact', render: textRender });
    columns.push({ data: 'dateadded', render: textRender });
    columns.push({ data: 'date_converted', render: textRender });
    columns.push({ data: null, orderable: false, searchable: false, render: function(d, t, row) {
        var phoneClean = String(row.phone || '').replace(/[^0-9+]/g, '');
        var h = '<a href="' + esc(row.detail_url) + '" class="btn btn-default btn-xs" title="Open lead">Open</a>';
        if (row.email) { h += ' <a href="mailto:' + encAttr(row.email) + '" class="btn btn-default btn-xs" title="Email">&#9993;</a>'; }
        if (phoneClean) {
          h += ' <a href="tel:' + esc(phoneClean) + '" class="btn btn-default btn-xs" title="Call">&#9742;</a>';
          h += ' <a href="https://wa.me/' + esc(phoneClean.replace(/^\+/, '')) + '" target="_blank" rel="noopener" class="btn btn-default btn-xs" title="WhatsApp">WA</a>';
        }
        return h;
      } });

    var lastCol = columns.length - 1;

    /*
     * Perfex bakes "table-loading" into every DataTables wrapper it creates:
     *
     *   $.fn.dataTable.ext.classes.sWrapper =
     *       'dataTables_wrapper form-inline dt-bootstrap table-loading'
     *
     * and the stylesheet behind that class is not a spinner, it is
     *
     *   .table-loading table tbody tr,
     *   .table-loading table thead th { opacity: 0 !important; }
     *
     * so every row and every header is rendered and then made invisible. Perfex's
     * own initDataTable() helper strips the class once the table is up; a module
     * that calls $.fn.DataTable directly — as this view does, because it needs
     * server-side columns Perfex's helper does not build — never gets that step,
     * and the class stays on forever.
     *
     * The result is the worst shape a fault can take: the request succeeds, the
     * JSON is correct, thirteen rows are in the DOM, no error reaches the console,
     * and the user sees grey bars that never resolve. Nothing is broken except
     * what they can see.
     *
     * Cleared after init, after every draw, and after a failed request — the last
     * of those matters most, because a 500 from the endpoint would otherwise leave
     * the page in exactly this state with no way out.
     */
    function clearTableLoading() {
      $('#pp_all_leads').closest('.dataTables_wrapper').removeClass('table-loading');
      $('#pp_all_leads').removeClass('dt-table-loading');
    }

    var dt = $('#pp_all_leads').DataTable({
      serverSide: true,
      processing: true,
      searchDelay: 400,
      order: [[1, 'desc']],
      pageLength: 25,
      lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
      columns: columns,
      columnDefs: [{ orderable: false, targets: [0, lastCol] }],
      language: { search: 'Search:', processing: 'Loading…' },
      initComplete: clearTableLoading,
      ajax: {
        url: AJAX_URL,
        type: 'GET',
        data: function(d) { $.extend(d, filterParams()); },
        error: function(xhr) {
          clearTableLoading();
          $('#pp_load_error')
            .text('Could not load leads (' + (xhr.status || 'network error') + '). Reload the page to try again.')
            .removeClass('hide');
        }
      }
    });

    // Re-apply selection checkboxes + header state after each server draw.
    dt.on('draw', function() {
      clearTableLoading();
      $('#pp_all_leads tbody .pp_rowchk').each(function() {
        $(this).prop('checked', !!selected[$(this).data('id')]);
      });
      syncHeader();
      refreshBar();
    });

    /* A request that returns but carries an error is the same problem. */
    dt.on('xhr.dt error.dt', clearTableLoading);

    // Filters → reload from server.
    $('#pp_f_status, #pp_f_source, #pp_f_assigned, #pp_f_from, #pp_f_to').on('change', function() {
      clearSelection();
      dt.ajax.reload();
    });
    $('#pp_clear').on('click', function() {
      $('#pp_f_status,#pp_f_source,#pp_f_assigned,#pp_f_from,#pp_f_to').val('');
      clearSelection();
      dt.search('').ajax.reload();
    });

    // ---- Column show/hide (by header text, index-independent) ----
    var hideable = { 'Company': 1, 'Source': 1, 'Value': 1, 'Tags': 1, 'Last Contact': 1, 'Added': 1, 'Converted': 1, 'Phone': 1, 'Status': 1, 'Assigned': 1 };
    var $menu = $('#pp_colmenu');
    dt.columns().every(function() {
      var idx = this.index();
      var label = $.trim($(this.header()).text());
      if (!label || !hideable[label]) { return; }
      var col = this;
      $menu.append(
        $('<li>').append(
          $('<label>').css({ 'font-weight': 400, 'margin': '2px 0', 'cursor': 'pointer', 'display': 'block', 'white-space': 'nowrap' }).append(
            $('<input type="checkbox">').attr('data-col', idx).prop('checked', col.visible())
          ).append(' ' + label)
        )
      );
    });
    $menu.on('change', 'input[data-col]', function() {
      dt.column(parseInt($(this).attr('data-col'), 10)).visible(this.checked);
    });

    // ---- Selection / bulk ----
    function pageChecks() { return $('#pp_all_leads tbody .pp_rowchk'); }
    function syncHeader() {
      var boxes = pageChecks();
      var checked = boxes.filter(':checked').length;
      $('#pp_checkall').prop('checked', boxes.length > 0 && checked === boxes.length);
    }
    function selCount() { return Object.keys(selected).length; }
    function clearSelection() {
      selected = {}; allMatching = false;
      pageChecks().prop('checked', false);
      $('#pp_checkall').prop('checked', false);
      refreshBar();
    }
    function refreshBar() {
      var info = dt.page.info();
      var filtered = info ? info.recordsDisplay : 0;
      if (allMatching) {
        $('#pp_selcount').text(filtered + ' selected (all matching)');
        $('#pp_bulkbar').css('display', 'block');
        $('#pp_selall_wrap').show();
        $('#pp_selall').hide(); $('#pp_selall_n').text(filtered);
        $('#pp_selall_clear').show();
        return;
      }
      var n = selCount();
      $('#pp_selcount').text(n + ' selected');
      $('#pp_bulkbar').css('display', n > 0 ? 'block' : 'none');
      // offer "select all matching" when the whole current page is selected and there is more.
      var boxes = pageChecks();
      var pageAllChecked = boxes.length > 0 && boxes.filter(':checked').length === boxes.length;
      if (pageAllChecked && filtered > boxes.length) {
        $('#pp_selall_wrap').show(); $('#pp_selall').show(); $('#pp_selall_n').text(filtered);
        $('#pp_selall_clear').hide();
      } else {
        $('#pp_selall_wrap').hide();
      }
    }

    $('#pp_all_leads').on('change', '.pp_rowchk', function() {
      var id = $(this).data('id');
      if (this.checked) { selected[id] = { email: $(this).data('email') || '', phone: $(this).data('phone') || '' }; }
      else { delete selected[id]; allMatching = false; }
      syncHeader(); refreshBar();
    });
    $('#pp_checkall').on('change', function() {
      var c = this.checked;
      pageChecks().each(function() {
        $(this).prop('checked', c);
        var id = $(this).data('id');
        if (c) { selected[id] = { email: $(this).data('email') || '', phone: $(this).data('phone') || '' }; }
        else { delete selected[id]; }
      });
      if (!c) { allMatching = false; }
      refreshBar();
    });
    $('#pp_selall').on('click', function(e) { e.preventDefault(); allMatching = true; refreshBar(); });
    $('#pp_selall_clear').on('click', function(e) { e.preventDefault(); clearSelection(); });

    function copyText(txt) {
      var ta = document.createElement('textarea');
      ta.value = txt; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
    }
    // Gather contacts for the current selection. cb(emails[], phones[]).
    function gather(cb) {
      if (allMatching) {
        $.getJSON(CONTACTS_URL, filterParams(), function(r) {
          cb((r && r.emails) || [], (r && r.phones) || []);
        }).fail(function() { alert('Could not load contacts from server.'); });
        return;
      }
      var emails = [], phones = [];
      $.each(selected, function(id, v) {
        if (v.email) { emails.push(v.email); }
        if (v.phone) { phones.push(v.phone); }
      });
      cb(emails, phones);
    }

    $('#pp_bulk_copyemail').on('click', function() {
      gather(function(emails) {
        if (!emails.length) { alert('No emails in selection.'); return; }
        copyText(emails.join(', ')); alert(emails.length + ' email(s) copied.');
      });
    });
    $('#pp_bulk_copyphone').on('click', function() {
      gather(function(emails, phones) {
        if (!phones.length) { alert('No phone numbers in selection.'); return; }
        copyText(phones.join(', ')); alert(phones.length + ' phone(s) copied.');
      });
    });
    $('#pp_bulk_mail').on('click', function() {
      gather(function(emails) {
        if (!emails.length) { alert('No emails in selection.'); return; }
        if (emails.length > 200) {
          alert(emails.length + ' recipients is too many for a mailto link. Use "Copy emails" or "Export filtered CSV" instead.');
          return;
        }
        window.location.href = 'mailto:?bcc=' + encodeURIComponent(emails.join(','));
      });
    });

    // Export = server CSV of ALL rows matching current filters/search.
    $('#pp_export').on('click', function(e) {
      e.preventDefault();
      var p = filterParams();
      p.search = dt.search() || '';
      var qs = $.param(p);
      window.location.href = EXPORT_URL + (EXPORT_URL.indexOf('?') >= 0 ? '&' : '?') + qs;
    });
  });
})();
</script>
