# Attachment points and the injection problem

## The problem this module had to solve first

On the staging installation, the sibling module `payplex_aicalling` registers a lead-view
hook and **renders nothing at all**. Its dashboard and "My Calls" page both instruct the
user to "Open a lead and use Call Now", but no such button exists anywhere in the lead
modal DOM — verified against a lead with a phone number and a lead without one.

That is the same class of extension point this module needs for two of its central
interactions. So `payplex_meetings` never assumes the hook works.

## The dual-path design

| Surface | Path A (server) | Path B (client, guaranteed) |
|---|---|---|
| Leads list Engagement column | `leads_table_columns` filter | `assets/js/payplex_meetings.js` → `initLeadsColumn()` |
| Lead profile Meetings tab | `lead_view_tabs` / `lead_view_tabs_content` actions | `injectLeadTab()` |
| Calendar feed | `calendar_feed()` controller endpoint | n/a |
| Sidebar, activation, language | Standard module API | n/a |

**They can never double-render.** Path A stamps `data-pm-server-rendered="1"` on what it
outputs. Path B checks for that attribute and stands down. If the core fires the hook, the
JS does nothing; if it doesn't, the JS is the whole feature.

## Why naive DOM injection fails (and what this does instead)

Three things break a one-shot `$(document).ready()` injection on these two surfaces:

1. **The leads list is a DataTable.** It redraws on pagination, search and sort, discarding
   injected cells every time. → We bind `draw.dt` and repaint on every draw.
2. **The lead modal is populated by AJAX after page load.** At document-ready the modal is
   an empty shell, so there is nothing to append to. → We listen for `shown.bs.modal` on
   `#lead-modal` *and* attach a `MutationObserver`, because on some builds the modal is
   reused and re-filled without firing the event again.
3. **Row shape differs between Perfex versions.** The lead id may be a data attribute, a
   link href, or the first cell. → `rowLeadId()` tries each in order and, if it still can't
   tell, appends an empty cell so the column count stays consistent rather than corrupting
   the table layout.

## Performance

The Engagement column issues **one** request per table draw carrying every visible lead id,
answered by a single aggregate `GROUP BY` query (`lead_indicators()`). There is deliberately
no per-row query — that pattern is what makes a leads list crawl at a few thousand rows.
Results are cached client-side for the session and invalidated after a booking.

## What to confirm in the Phase 1 spike

- Whether this Perfex build actually fires `leads_table_columns`, and with what signature.
- Whether the lead modal exposes a supported tab hook, and what it is called here.
- **Why `payplex_aicalling` fails** — if the cause is a module-registration bug rather than a
  missing hook, the same bug may affect any module on this install, and that is worth
  knowing before more modules are written.

Update this file with the findings. Do not delete the client-side path on the strength of
one working hook: it is the reason this module degrades instead of disappearing.
