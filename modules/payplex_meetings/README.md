# Payplex Meetings — v0.1.0

Lead-based meeting booking, calendar, reminders and audit trail for Perfex CRM.

Built against the Phase 0 approval package. This release covers the foundation:
booking, conflict detection, participants, `.ics`, the reminder engine, the leads-list
Engagement column, the lead profile tab, the calendar feed, settings and the audit trail.

---

## Install

1. Copy the `payplex_meetings` folder into `modules/` in your Perfex installation.
2. Go to **Setup → Modules** and activate **Payplex Meetings**.
   Activation runs `install.php`, which creates ten tables and seeds default options.
   Every statement is `CREATE TABLE IF NOT EXISTS`, so re-activating is safe.
3. Go to **Meetings → Settings** and set the company time zone, working hours and
   reminder offsets.
4. Grant permissions per role under **Setup → Roles** (see the matrix below).
5. Install the cron entry — **reminders do not work without it**.

## Cron — usually nothing to do

**Verified on the techplex cPanel account: no new crontab entry is needed.** The account
already runs, for both hosts:

```
*/5 * * * * wget -q -O /dev/null https://support.techplex.in/cron/index
*/5 * * * * wget -q -O /dev/null https://staging.support.techplex.in/cron/index
```

Perfex's own cron *features* are day-granular, but the crontab *entry* fires every five
minutes — exactly the cadence the reminder ladder needs. The module hooks `after_cron_run`
and rides it, guarded so it does no work more than once a minute.

Only if your install's cron is coarser than every 5 minutes, add the standalone dispatcher:

```
*/5 * * * * /usr/bin/php /path/to/crm/index.php payplex_meetings/meetings_cron/run <TOKEN>
```

or, where CLI routing is unavailable:

```
*/5 * * * * curl -fsS "https://your-crm.example.com/payplex_meetings/meetings_cron/run/<TOKEN>"
```

`<TOKEN>` is generated per installation at activation and stored in the options table as
`pm_cron_token`. It is shown on the Settings page. **Do not commit it to source control.**

Health check for your monitoring:

```
GET /payplex_meetings/meetings_cron/status/<TOKEN>
→ {"success":true,"pending":12,"overdue":0,"failed_last_24h":0,"healthy":true}
```

`overdue > 0` means the dispatcher is not running. Alert on it — a silent scheduler is the
failure mode that costs the most.

## Permissions

Registered as the `payplex_meetings` feature. Suggested mapping onto the existing
ten-role matrix:

| Capability | super_admin | manager / field_manager | employee | auditor |
|---|---|---|---|---|
| view (all) | ✔ | — | — | ✔ |
| view_own | ✔ | ✔ | ✔ | ✔ |
| create | ✔ | ✔ | ✔ | — |
| edit | ✔ | ✔ | ✔ (own) | — |
| cancel | ✔ | ✔ | ✔ (own) | — |
| override_conflict | ✔ | ✔ | — | — |
| view_confidential | ✔ | ✔ | — | — |
| approve_summary | ✔ | ✔ | — | — |
| share_client_summary | ✔ | ✔ | — | — |
| config | ✔ | — | — | — |
| export | ✔ | — | — | ✔ |

`approve_summary` is deliberately withheld from `employee`: an author must not approve
their own summary. This mirrors the maker≠approver rule the finance domains already use.

Visibility is enforced **in SQL**, in `apply_visibility_scope()`, not in the view. A user
with neither `view` nor `view_own` gets a query that returns nothing — it fails closed.

## Design decisions worth knowing

**All datetimes are stored UTC.** The meeting's own `timezone` column renders them back.
This is what keeps time-zone conversion, cross-region participants and DST correct.

**Reminders are rows, not calculations.** Each send is materialised with a unique
`idempotency_key`. "Never send the same reminder twice" is a `UNIQUE` index, not a hope
about cron behaviour. The dispatcher claims work with a single atomic `UPDATE ... LIMIT`
stamping a per-run claim token — deliberately not `FOR UPDATE SKIP LOCKED`, which needs
MySQL 8.0 / MariaDB 10.6 and would fail on the 5.7 installs Perfex still runs on. Rows
abandoned by a worker that died mid-batch are reclaimed after 15 minutes.

**A missed reminder is skipped, not sent late.** "Your meeting starts in 30 minutes",
arriving two hours afterwards, is worse than silence. Missed rows are logged as `failed`
with reason `window_missed` so the outage is visible.

**Internal and client email bodies are separated at the data layer.**
`Payplex_meeting_mailer::build_payload()` has two modes, and the `client` mode never reads
internal notes, override reasons or confidential fields — they are not in the array at all.
A careless template edit therefore cannot leak them.

**Cancellation is a status, never a DELETE.** Participants are soft-removed. The audit log
has no update or delete route anywhere in the module.

**The lead-view injection has two paths.** See `docs/ATTACHMENT-POINTS.md` — this is the
part that a sibling module on this install currently gets wrong, and the reason the client
path exists.

## Not in v0.1.0

Deferred to the next phase, per the approval package:

- Meeting summaries UI, internal/client sharing, action-item → task conversion (schema is
  already in place: `_summaries`, `_action_items`)
- AI-drafted summaries via the existing OpenAI configuration
- Meeting dashboard and the report cuts, CSV/Excel/PDF export
- Google / Outlook two-way sync (`_integrations` table is ready; decision D4 pending)
- SMS / WhatsApp reminder channels (the channel column and dispatcher already support them)

## Open decisions still blocking later phases

`D1` reporting-manager source · `D2` client-facing AI text · `D3` scheduler choice ·
`D4` calendar integration depth · `D5` recording consent · `D6` polymorphic rel columns

See the Phase 0 package for the full statement of each.

## Uninstall

Deactivation does **not** drop data. To drop the schema, set `pm_allow_destructive_uninstall`
to `1` in the options table first, deliberately, after taking a backup.
