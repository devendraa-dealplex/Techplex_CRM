/* global jQuery, window, document */
/**
 * Payplex Meetings — admin client.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The server-side hooks in payplex_meetings.php add the Engagement column and the lead
 * tab *if* the installed Perfex fires those filters. On this installation a sibling module
 * (payplex_aicalling) registers exactly that kind of hook and renders nothing at all — its
 * documented "Call Now" button does not exist in the lead modal DOM.
 *
 * So this file is the guaranteed path, not a nicety. It:
 *   1. stands down immediately if the server already rendered (data-pm-server-rendered),
 *      so the two paths can never double-render;
 *   2. attaches to the leads DataTable through its own draw event, surviving pagination,
 *      search and sort — the three things that silently break naive DOM injection;
 *   3. watches for the lead modal being populated by AJAX, which is why a one-shot
 *      document-ready injection finds nothing.
 */
(function ($) {
    'use strict';

    if (!$ || !window.PayplexMeetings) {
        return;
    }

    var PM = window.PayplexMeetings;
    var indicatorCache = {};

    /* ------------------------------------------------------------- utilities */

    function post(url, data) {
        var payload = $.extend({}, data || {});
        payload[PM.csrfName] = PM.csrfHash;

        return $.ajax({
            url: url,
            type: 'POST',
            data: payload,
            dataType: 'json'
        }).done(function (res) {
            // Perfex rotates the CSRF hash per request; keep ours current or the second
            // action of a session silently 403s.
            if (res && res.csrf_hash) {
                PM.csrfHash = res.csrf_hash;
            }
        });
    }

    function esc(s) {
        return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
    }

    function alertMsg(type, msg) {
        if (typeof window.alert_float === 'function') {
            window.alert_float(type, msg);
        } else {
            window.console && window.console.log('[PayplexMeetings]', type, msg);
        }
    }

    /* ============================================== 1. leads Engagement column */

    /**
     * Find every table on the page that is a list of leads.
     *
     * Verified on this installation: the core list at /admin/leads renders
     * table.table-leads but its server side currently returns iTotalRecords 0, while the
     * `payplex_all_leads` module renders the same 13 leads into #pp_all_leads with an
     * entirely different class list. Matching one hard-coded selector would have put the
     * Engagement column only on the empty table.
     *
     * So: take the known selectors, then add any DataTable whose rows link to
     * /leads/index/<id>. That is what actually makes a table "a list of leads", and it
     * keeps working when someone adds another leads view next month.
     */
    function leadsTables() {
        var seen = [];
        var out = [];

        function add($t) {
            $t.each(function () {
                if (seen.indexOf(this) === -1) {
                    seen.push(this);
                    out.push($(this));
                }
            });
        }

        add($('table.table-leads, #leads-table, #pp_all_leads, table[id^="leads"]'));

        $('table').each(function () {
            var $t = $(this);
            if (seen.indexOf(this) !== -1) {
                return;
            }
            if ($t.find('tbody a[href*="/leads/index/"]').length) {
                add($t);
            }
        });

        return out;
    }

    function serverAlreadyRendered($table) {
        return $table.find('th[data-pm-server-rendered]').length > 0;
    }

    /**
     * Is this table driven by DataTables?
     *
     * This matters more than it looks. A server-side DataTable builds each row from an
     * array whose length must match the number of <th> in the header. Appending a header
     * cell to one makes DataTables ask the server row for a column that does not exist and
     * it then renders NOTHING -- the whole table goes blank:
     *
     *   DataTables warning: table id=leads - Requested unknown parameter '13' for row 0
     *
     * That is invisible while the table is empty and breaks the page the moment it has
     * rows, which is exactly how it got shipped once. So on a DataTable we never touch the
     * column structure: the engagement block is rendered INSIDE the last existing cell.
     */
    function isDataTable($table) {
        try {
            return !!($.fn.dataTable && $.fn.dataTable.isDataTable($table[0]));
        } catch (e) {
            return false;
        }
    }

    function ensureHeader($table) {
        if (isDataTable($table)) {
            return; // never change a DataTable's column count -- see isDataTable()
        }
        if ($table.find('th.pm-engagement-col').length) {
            return;
        }
        $table.find('thead tr').each(function () {
            $(this).append(
                '<th class="pm-engagement-col" data-pm-injected="1">' + esc(PM.lang.engagement) + '</th>'
            );
        });
    }

    /**
     * Where the engagement block goes for one row.
     * DataTable -> a wrapper div inside the last existing cell (no structural change).
     * Plain table -> a real extra cell.
     */
    function slotFor($tr, dt) {
        var existing = $tr.find('.pm-engagement-cell');
        if (existing.length) {
            return existing.first();
        }

        if (dt) {
            var $last = $tr.children('td').last();
            if (!$last.length) {
                return null;
            }
            return $('<div class="pm-engagement-cell pm-inline-slot"></div>').appendTo($last);
        }

        return $('<td class="pm-engagement-cell"></td>').appendTo($tr);
    }

    /**
     * Read the lead id from a row. Perfex puts it in different places across versions, so
     * try each and give up quietly rather than guessing wrong.
     */
    function rowLeadId($tr) {
        var id = $tr.data('lead-id') || $tr.attr('data-lead-id');
        if (id) {
            return parseInt(id, 10);
        }

        var $link = $tr.find('a[href*="/leads/index/"], a[onclick*="lead"]').first();
        if ($link.length) {
            var m = ($link.attr('href') || '').match(/\/leads\/index\/(\d+)/);
            if (m) {
                return parseInt(m[1], 10);
            }
        }

        var first = $.trim($tr.find('td').first().text());
        return /^\d+$/.test(first) ? parseInt(first, 10) : null;
    }

    function cellHtml(leadId, ind) {
        var bits = [];

        if (ind && ind.overdue !== null && ind.overdue !== undefined) {
            bits.push('<span class="pm-chip pm-chip-due"><i class="pm-dot"></i>' +
                esc(PM.lang.overdue) + ' ' + esc(ind.overdue) + 'd</span>');
        } else if (ind && ind.next) {
            bits.push('<span class="pm-chip pm-chip-up"><i class="pm-dot"></i>' + esc(ind.next) + '</span>');
        } else {
            bits.push('<span class="pm-chip pm-chip-none">' + esc(PM.lang.no_meeting) + '</span>');
        }

        if (ind && ind.total > 0) {
            bits.push('<div class="pm-counts">' + esc(ind.completed) + ' done · ' +
                esc(ind.no_show) + ' no-show</div>');
        }

        bits.push(
            '<div class="btn-group pm-actions">' +
            '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-toggle="dropdown" ' +
            'aria-haspopup="true" aria-expanded="false" data-pm-lead="' + leadId + '">' +
            esc(PM.lang.quick_actions) + ' <span class="caret"></span></button>' +
            menuHtml(leadId) +
            '</div>'
        );

        return bits.join('');
    }

    function menuHtml(leadId) {
        function item(action, label, enabled) {
            if (!enabled) {
                return '<li class="disabled"><a href="javascript:void(0)">' + esc(label) + '</a></li>';
            }
            return '<li><a href="javascript:void(0)" class="pm-action" data-pm-action="' + action +
                '" data-pm-lead="' + leadId + '">' + esc(label) + '</a></li>';
        }

        return '<ul class="dropdown-menu dropdown-menu-right pm-menu">' +
            '<li class="dropdown-header">Schedule</li>' +
            item('book', 'Book meeting', PM.canCreate) +
            item('followup', 'Schedule follow-up', PM.canCreate) +
            '<li role="separator" class="divider"></li>' +
            '<li class="dropdown-header">Record</li>' +
            item('complete', 'Mark completed', PM.canEdit) +
            item('noshow', 'Mark no-show', PM.canEdit) +
            item('notes', 'Add meeting notes', PM.canEdit) +
            '<li role="separator" class="divider"></li>' +
            '<li class="dropdown-header">View</li>' +
            item('list', 'All meetings', true) +
            item('calendar', 'Open in calendar', true) +
            item('ics', 'Download calendar file', true) +
            '</ul>';
    }

    function paintRows($table) {
        var ids = [];
        var map = {};
        var dt  = isDataTable($table);

        $table.find('tbody tr').each(function () {
            var $tr = $(this);
            if ($tr.find('.pm-engagement-cell').length || $tr.children('td').length < 2) {
                return;
            }

            var leadId = rowLeadId($tr);
            if (!leadId) {
                return; // unknown row shape: leave it entirely alone
            }

            var $slot = slotFor($tr, dt);
            if (!$slot) {
                return;
            }

            $slot.attr('data-pm-lead', leadId)
                 .html('<span class="pm-skeleton">' + esc(PM.lang.loading) + '</span>');

            map[leadId] = $tr;

            if (indicatorCache[leadId]) {
                renderCell($tr, leadId, indicatorCache[leadId]);
            } else {
                ids.push(leadId);
            }
        });

        if (!ids.length) {
            return;
        }

        // One request for the whole visible page — never one per row.
        post(PM.base + '/lead_indicators', { ids: ids })
            .done(function (res) {
                if (!res || !res.success) {
                    return;
                }
                $.each(res.data, function (leadId, ind) {
                    indicatorCache[leadId] = ind;
                    if (map[leadId]) {
                        renderCell(map[leadId], leadId, ind);
                    }
                });
            })
            .fail(function () {
                $.each(map, function (leadId, $tr) {
                    $tr.find('.pm-engagement-cell').first()
                       .html('<span class="pm-chip pm-chip-none">—</span>');
                });
            });
    }

    function renderCell($tr, leadId, ind) {
        $tr.find('.pm-engagement-cell').first().html(cellHtml(leadId, ind));
    }

    function initLeadsColumn() {
        $.each(leadsTables(), function (_, $table) {
            if ($table.data('pm-init')) {
                return;
            }
            if (serverAlreadyRendered($table)) {
                return; // the core hook fired for this table; stand down
            }
            $table.data('pm-init', true);

            ensureHeader($table);
            paintRows($table);

            // Re-paint on every DataTable draw so pagination, search and sort keep it.
            $table.on('draw.dt', function () {
                ensureHeader($table);
                paintRows($table);
            });
        });
    }

    /* ================================================ 2. lead profile meetings tab */

    function injectLeadTab() {
        var $modal = $('#lead-modal');
        if (!$modal.length || !$modal.hasClass('in')) {
            return;
        }
        if ($modal.find('#pm_lead_meetings').length) {
            return; // already there (server hook or a previous open)
        }

        var $tabs = $modal.find('ul.nav-tabs, .nav.nav-tabs').first();
        var $panes = $modal.find('.tab-content').first();
        if (!$tabs.length || !$panes.length) {
            return;
        }

        var leadId = null;
        var $del = $modal.find('a[href*="/leads/delete/"]').first();
        if ($del.length) {
            var m = ($del.attr('href') || '').match(/\/leads\/delete\/(\d+)/);
            if (m) {
                leadId = parseInt(m[1], 10);
            }
        }
        if (!leadId) {
            return;
        }

        $tabs.append(
            '<li role="presentation" data-pm-injected="1">' +
            '<a href="#pm_lead_meetings" aria-controls="pm_lead_meetings" role="tab" data-toggle="tab">' +
            '<i class="fa fa-calendar-check-o"></i> ' + esc(PM.lang.meetings_tab) + '</a></li>'
        );

        $panes.append(
            '<div role="tabpanel" class="tab-pane" id="pm_lead_meetings" data-pm-lead="' + leadId + '">' +
            '<div class="pm-tab-loading">' + esc(PM.lang.loading) + '</div></div>'
        );

        $modal.on('shown.bs.tab.pm', 'a[href="#pm_lead_meetings"]', function () {
            loadLeadTab(leadId);
        });
    }

    function loadLeadTab(leadId) {
        var $pane = $('#pm_lead_meetings');
        if ($pane.data('pm-loaded')) {
            return;
        }
        $pane.data('pm-loaded', true);

        $.get(PM.base + '/lead_tab/' + leadId)
            .done(function (html) { $pane.html(html); })
            .fail(function () { $pane.html('<p class="text-danger">Could not load meetings.</p>'); });
    }

    /* ==================================================== 3. booking drawer */

    function openBookDrawer(leadId) {
        if (!PM.canCreate) {
            return;
        }

        // The drawer is usually opened from inside the lead modal. Close that first so the
        // two panels never overlap, and drop its backdrop, which otherwise stays behind.
        var $leadModal = $('#lead-modal');
        if ($leadModal.length && $leadModal.hasClass('in')) {
            $leadModal.modal('hide');
        }

        var $drawer = $('#pm-drawer');
        if (!$drawer.length) {
            $drawer = $('<div id="pm-drawer" class="pm-drawer" role="dialog" aria-modal="true">' +
                '<div class="pm-drawer-backdrop"></div>' +
                '<div class="pm-drawer-panel"><div class="pm-drawer-body"></div></div></div>').appendTo('body');

            $drawer.on('click', '.pm-drawer-backdrop, [data-pm-close]', closeDrawer);
            $(document).on('keydown.pmdrawer', function (e) {
                if (e.key === 'Escape') { closeDrawer(); }
            });
        }

        $drawer.find('.pm-drawer-body').html('<div class="pm-tab-loading">' + esc(PM.lang.loading) + '</div>');
        $drawer.addClass('pm-open');

        $.get(PM.base + '/book_form/' + leadId)
            .done(function (html) {
                $drawer.find('.pm-drawer-body').html(html);
                $drawer.find('input,select,textarea').filter(':visible').first().trigger('focus');
            })
            .fail(function () {
                $drawer.find('.pm-drawer-body').html('<p class="text-danger">Could not load the booking form.</p>');
            });
    }

    function closeDrawer() {
        $('#pm-drawer').removeClass('pm-open');
    }

    /* --------------------------------------------------- booking form wiring */

    $(document).on('submit', '#pm-book-form', function (e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $form.find('[type="submit"]');
        var data = {};

        $.each($form.serializeArray(), function (_, f) { data[f.name] = f.value; });
        data.participants = JSON.stringify(collectParticipants($form));

        $btn.prop('disabled', true);

        post(PM.base + '/book', data)
            .done(function (res) {
                if (res.success) {
                    alertMsg('success', res.message);
                    closeDrawer();
                    indicatorCache = {};
                    window.location.href = res.redirect;
                    return;
                }

                renderConflicts($form, res);
                $btn.prop('disabled', false);
            })
            .fail(function () {
                alertMsg('danger', 'Request failed.');
                $btn.prop('disabled', false);
            });
    });

    function collectParticipants($form) {
        var out = [];
        $form.find('.pm-participant').each(function () {
            var $p = $(this);
            out.push({
                party_type: $p.data('party-type'),
                staff_id: $p.data('staff-id') || null,
                email: $p.data('email') || '',
                name: $p.data('name') || '',
                role: $p.find('.pm-role').val() || 'required',
                is_reminder_recipient: $p.find('.pm-remind').is(':checked'),
                is_summary_recipient: $p.find('.pm-summary').is(':checked')
            });
        });

        return out;
    }

    function renderConflicts($form, res) {
        var $box = $form.find('.pm-conflicts');
        if (!res.conflicts || !res.conflicts.length) {
            $box.html('<div class="pm-error">' + esc(res.message) + '</div>').show();
            return;
        }

        var html = '<div class="pm-warn"><strong>' + esc(res.message) + '</strong><ul>';
        $.each(res.conflicts, function (_, c) {
            html += '<li>' + esc(c.subject) + ' — ' + esc(c.start_local) + '–' + esc(c.end_local) +
                ' <span class="pm-muted">(' + esc(c.type) + ')</span></li>';
        });
        html += '</ul>';

        if (res.suggestions && res.suggestions.length) {
            html += '<div class="pm-suggest">Suggested: ';
            $.each(res.suggestions, function (_, s) {
                html += '<button type="button" class="btn btn-xs btn-default pm-slot" data-start="' +
                    esc(s.start_utc) + '">' + esc(s.start_local) + '</button> ';
            });
            html += '</div>';
        }

        if (PM.canOverride) {
            html += '<label class="pm-override"><input type="checkbox" name="override_conflict" value="1"> ' +
                'Override this conflict</label>' +
                '<input type="text" class="form-control input-sm" name="override_reason" ' +
                'placeholder="Reason (recorded in the audit log)">';
        }

        html += '</div>';
        $box.html(html).show();
    }

    /* ------------------------------------------------------- action dispatch */

    $(document).on('click', '.pm-action', function () {
        var leadId = $(this).data('pm-lead');
        var action = $(this).data('pm-action');

        switch (action) {
            case 'book':
            case 'followup':
                openBookDrawer(leadId);
                break;
            case 'list':
                window.location.href = PM.base + '?lead=' + leadId;
                break;
            case 'calendar':
                window.location.href = admin_calendar_url();
                break;
            default:
                openBookDrawer(leadId);
        }
    });

    function admin_calendar_url() {
        return PM.base.replace(/payplex_meetings\/meetings$/, 'utilities/calendar');
    }

    /* ============================================================ 4. bootstrap */

    $(function () {
        initLeadsColumn();

        // The lead modal is populated by AJAX after the page is ready, so a one-shot
        // injection on document-ready finds an empty modal. Watch for it instead.
        $(document).on('shown.bs.modal', '#lead-modal', function () {
            window.setTimeout(injectLeadTab, 60);
        });

        if (window.MutationObserver) {
            var host = document.getElementById('lead-modal');
            if (host) {
                new window.MutationObserver(function () {
                    injectLeadTab();
                }).observe(host, { childList: true, subtree: true });
            }
        }
    });
}(window.jQuery));
