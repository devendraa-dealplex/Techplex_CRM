<?php
defined('BASEPATH') or defined('LEADGEN_FU_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Followup_clock.php';

/**
 * Followup_schedule — which stage, if any, may be sent to one lead right now.
 *
 * THE DEFECT THIS REPLACES
 * ------------------------
 * The old loop was:
 *
 *     $daysElapsed = floor((time() - $anchorTime) / 86400);
 *     foreach ($stages as $stageDay) {
 *         if ($daysElapsed < $stageDay) break;
 *         INSERT IGNORE ... ;                 // claim
 *         if (affected_rows() > 0) { $stageToSend = $stageDay; break; }
 *     }
 *
 * Read it against a cron that fires every five minutes. Run one claims stage 1
 * and sends. Run two finds stage 1 already claimed, moves to stage 3, claims it
 * and sends. Run three does the same for stage 7. **The entire day-1/3/7
 * sequence completes in fifteen minutes** — and the measured staging data shows
 * exactly that: leads 1180–1183 received all three stages inside one calendar
 * day, `DATEDIFF(MAX(date_sent), MIN(date_sent)) = 0`.
 *
 * Nothing in that loop was a duplicate. Every row was a different stage, and the
 * UNIQUE (leadid, stage_day) index — correct in itself — could never have
 * prevented it. That is why the original F-EX-01 diagnosis of "duplicate tasks"
 * mattered: the fix it implied was already deployed and the sends continued.
 *
 * THE RULES NOW, AND WHERE EACH ONE COMES FROM
 * --------------------------------------------
 *  1. The anchor is the lead's **creation** time. The requirement is "N hours
 *     after lead creation"; the old code anchored on `lastcontact` and fell back
 *     to `dateassigned`, neither of which is creation, and both of which move.
 *  2. Stage N is due at `created + N*24 hours` — **hours, not floor'd days**, so
 *     "at least 24 hours" means at least 24 hours rather than "some time after
 *     midnight tomorrow".
 *  3. One stage per lead per run, always the lowest unsent due stage. A stage
 *     that has a log row is never re-selected — that is "never resend a
 *     completed stage", and it holds across crashes because the row is written
 *     before the work.
 *  4. Minimum gap since the previous *actual send* for that lead. Absolute
 *     anchoring already implies spacing, but only while the anchor is right;
 *     this holds even when it is not. Belt and braces, deliberately.
 *  5. Never two stages in one calendar day, measured in the CRM zone.
 *  6. A stage that came due before the module was fixed and re-armed is skipped
 *     permanently, and a stage that came due longer ago than the catch-up
 *     horizon is skipped too. Together these are "no bulk catch-up after
 *     deployment": a lead carrying a three-stage backlog is retired one
 *     *decision* at a time and sends nothing.
 *
 * Rules 3–6 each produce a distinct recorded reason, because a rule whose
 * outcome you cannot tell apart from another rule's cannot be tested.
 *
 * This class is pure: it takes values and returns a decision. No database, no
 * clock of its own, no options lookup. That is what makes a 24-hour rule
 * testable in a millisecond.
 */
class Followup_schedule
{
    /* Decisions. */
    const D_SEND            = 'send';
    const D_NO_ANCHOR       = 'no_anchor';
    const D_ALL_SENT        = 'all_stages_sent';
    const D_NOT_DUE         = 'not_due';
    const D_SPACING_BLOCK   = 'spacing_block';
    const D_SAME_DAY_BLOCK  = 'same_day_block';
    const D_BACKLOG_SKIPPED = 'backlog_skipped';
    const D_CATCHUP_EXPIRED = 'catchup_expired';

    /** Log rows that count as "a send actually happened" for rules 4 and 5. */
    const SEND_RESULTS = array('sent', 'partial', 'failed', 'simulated');

    /** Defaults. Overridable by option; none of them is a business rate. */
    const DEFAULT_MIN_GAP_HOURS     = 24;
    const DEFAULT_MAX_CATCHUP_HOURS = 48;

    /**
     * Stage day N means N*24 hours. Written as a method so the test can assert
     * the multiplication rather than trusting a literal in three places.
     */
    public static function stageDueHours($stageDay)
    {
        return ((int) $stageDay) * 24;
    }

    /**
     * When stage N becomes due for a lead created at $anchor.
     *
     * `add(new DateInterval('PT24H'))`, NOT `modify('+24 hours')`.
     *
     * They are not the same function and the difference is invisible until a
     * DST boundary. `modify()` moves the *wall clock* and re-resolves the zone,
     * so across Europe/London's spring-forward it produced **82,800 seconds —
     * 23 hours** — while the requirement says "at least 24 hours after lead
     * creation". `add()` with a time interval moves the instant and produced a
     * true 86,400.
     *
     * The first version of this method used `modify()`, with a comment claiming
     * it was the DST-correct choice. It was backwards, and only the clock test
     * that measured the actual delta across 2026-03-29 found it. Asia/Kolkata
     * has no DST, so nothing on this deployment would ever have shown it.
     */
    public static function dueAt(DateTimeImmutable $anchor, $stageDay)
    {
        return $anchor->add(new DateInterval('PT' . self::stageDueHours($stageDay) . 'H'));
    }

    /**
     * Normalise the configured stage list.
     *
     * Rejects non-positive and non-numeric entries, de-duplicates, sorts
     * ascending. A stage list of [7,3,1,3,0,'x'] becomes [1,3,7]; an unsorted
     * list would otherwise make "the lowest unsent due stage" mean whatever the
     * option happened to contain.
     */
    public static function normaliseStages($stages)
    {
        if (!is_array($stages)) {
            return array();
        }

        $out = array();

        foreach ($stages as $s) {
            if (!is_int($s) && !(is_string($s) && preg_match('/\A\d+\z/', trim($s)))) {
                continue;
            }

            $n = (int) $s;

            if ($n > 0) {
                $out[$n] = $n;
            }
        }

        $out = array_values($out);
        sort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * Decide what happens to one lead on this run.
     *
     * @param array              $stages    configured stage days
     * @param string|null        $anchorAt  lead creation, database format
     * @param array              $log       rows already recorded for this lead:
     *                                      each array('stage_day'=>int,
     *                                      'send_result'=>string,
     *                                      'date_sent'=>string)
     * @param Followup_clock     $clock     the run's single clock
     * @param DateTimeImmutable  $armedAt   when the corrected module was armed
     * @param array              $cfg       min_gap_hours, max_catchup_hours
     *
     * @return array decision, stage, reason, eligible_at, anchor_at
     */
    public static function decide(array $stages, $anchorAt, array $log,
                                  Followup_clock $clock, DateTimeImmutable $armedAt, array $cfg = array())
    {
        $minGap     = isset($cfg['min_gap_hours']) ? (int) $cfg['min_gap_hours'] : self::DEFAULT_MIN_GAP_HOURS;
        $maxCatchup = isset($cfg['max_catchup_hours']) ? (int) $cfg['max_catchup_hours'] : self::DEFAULT_MAX_CATCHUP_HOURS;

        if ($minGap < 0) {
            $minGap = self::DEFAULT_MIN_GAP_HOURS;
        }

        $now    = $clock->now();
        $anchor = $clock->parse($anchorAt);

        if ($anchor === null) {
            return self::result(self::D_NO_ANCHOR, null, null, null);
        }

        $stages = self::normaliseStages($stages);

        if (empty($stages)) {
            return self::result(self::D_ALL_SENT, null, null, $anchor);
        }

        /* Stages already recorded — sent, skipped or simulated — are finished. */
        $recorded = array();
        $lastSend = null;

        foreach ($log as $row) {
            $day = isset($row['stage_day']) ? (int) $row['stage_day'] : 0;

            if ($day > 0) {
                $recorded[$day] = true;
            }

            $result = isset($row['send_result']) ? (string) $row['send_result'] : '';

            if (!in_array($result, self::SEND_RESULTS, true)) {
                continue;
            }

            $sentAt = $clock->parse(isset($row['date_sent']) ? $row['date_sent'] : null);

            if ($sentAt !== null && ($lastSend === null || $sentAt > $lastSend)) {
                $lastSend = $sentAt;
            }
        }

        foreach ($stages as $stageDay) {
            if (isset($recorded[$stageDay])) {
                continue;
            }

            $dueAt = self::dueAt($anchor, $stageDay);

            /*
             * Stages ascend, so the first one that is not yet due means every
             * later one is not due either. Stopping here rather than continuing
             * is what stops a lead skipping straight to stage 7.
             */
            if ($now < $dueAt) {
                return self::result(self::D_NOT_DUE, $stageDay, $dueAt, $anchor);
            }

            /*
             * Backlog and horizon are checked BEFORE spacing, because both end
             * in a permanent skip that is written to the log. Checking spacing
             * first would make a backlog lead wait a day only to be skipped.
             */
            if ($dueAt < $armedAt) {
                return self::result(self::D_BACKLOG_SKIPPED, $stageDay, $dueAt, $anchor);
            }

            if ($maxCatchup >= 0 && Followup_clock::hoursBetween($dueAt, $now) > $maxCatchup) {
                return self::result(self::D_CATCHUP_EXPIRED, $stageDay, $dueAt, $anchor);
            }

            if ($lastSend !== null) {
                if (Followup_clock::hoursBetween($lastSend, $now) < $minGap) {
                    return self::result(self::D_SPACING_BLOCK, $stageDay, $dueAt, $anchor);
                }

                if ($lastSend->format('Y-m-d') === $now->format('Y-m-d')) {
                    return self::result(self::D_SAME_DAY_BLOCK, $stageDay, $dueAt, $anchor);
                }
            }

            return self::result(self::D_SEND, $stageDay, $dueAt, $anchor);
        }

        return self::result(self::D_ALL_SENT, null, null, $anchor);
    }

    /** Decisions that are written to the log so the stage is never retried. */
    public static function isPermanentSkip($decision)
    {
        return $decision === self::D_BACKLOG_SKIPPED || $decision === self::D_CATCHUP_EXPIRED;
    }

    private static function result($decision, $stage, $dueAt, $anchor)
    {
        return array(
            'decision'    => $decision,
            'stage'       => $stage === null ? null : (int) $stage,
            'eligible_at' => $dueAt instanceof DateTimeImmutable ? $dueAt->format('Y-m-d H:i:s') : null,
            'anchor_at'   => $anchor instanceof DateTimeImmutable ? $anchor->format('Y-m-d H:i:s') : null,
        );
    }
}
