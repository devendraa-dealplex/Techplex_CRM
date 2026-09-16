<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Turning the delivery log into an answer.
 *
 * WHY THIS EXISTS, GIVEN THE LOG ALREADY DOES
 * -------------------------------------------
 * The delivery log fixed the original problem: a refused delivery used to leave
 * no trace, so "Meta never called" and "Meta called eleven times and we refused
 * every one" produced identical evidence. Now every request is recorded.
 *
 * That is necessary and it is not sufficient. A table of two hundred rows with
 * `rejected_bad_signature` in one column still requires somebody to read it,
 * count it, and know what it means. Nobody reads a log until something is
 * already wrong, and by then the leads are gone.
 *
 * So this class does the counting and the interpreting. The important output is
 * not the numbers — it is the sentences. "Twelve consecutive signature failures"
 * is data; **"the App Secret in this CRM does not match the app Meta is signing
 * with"** is the answer, and it is the one a human can act on.
 *
 * Each alert is deliberately a claim about a *specific misconfiguration*, not a
 * generic "errors detected". A monitor that can only say "something is wrong"
 * is a monitor nobody trusts twice.
 *
 * Pure functions: counts in, verdicts out. No database, no options, no clock of
 * its own — the caller supplies `now`, so every threshold is testable at an
 * exact boundary rather than approximately.
 */
class Facebook_health
{
    /* ---- report buckets ---- */

    /** A lead was created. The only outcome that means the integration worked. */
    const B_CREATED = 'created';
    /** Already seen; correctly not created twice. */
    const B_DUPLICATE = 'duplicate';
    /** Matched an existing lead by email or phone. */
    const B_MATCHED = 'matched';
    /** Accepted, nothing to do. */
    const B_NO_ACTION = 'no_action';
    /** Created but nobody owns it. */
    const B_REVIEW = 'review';
    /** Refused. The caller's request was not acceptable. */
    const B_REJECTED = 'rejected';
    /** We broke, or we are not configured. */
    const B_FAILED = 'failed';
    /** A subscription handshake. */
    const B_VERIFY = 'verify';
    /** Handled and kept, but deliberately not a lead. Its own bucket, because
        counting it as "created" would overstate what the pipeline produced and
        counting it as "rejected" would hide a delivery we did accept. */
    const B_QUARANTINED = 'quarantined';

    /** The four buckets the requirement names, in report order. */
    const REPORT_BUCKETS = array(self::B_CREATED, self::B_REJECTED, self::B_FAILED, self::B_DUPLICATE);

    /**
     * Outcome => bucket.
     *
     * A literal map rather than string matching on the outcome name. Matching
     * on `strpos($outcome, 'rejected')` would work today and silently
     * misclassify the first outcome whose name does not follow the convention.
     */
    const BUCKET = array(
        Facebook_delivery::ACCEPTED_CREATED        => self::B_CREATED,
        Facebook_delivery::ACCEPTED_DUPLICATE      => self::B_DUPLICATE,
        Facebook_delivery::ACCEPTED_MATCHED        => self::B_MATCHED,
        Facebook_delivery::ACCEPTED_QUARANTINED    => self::B_QUARANTINED,
        Facebook_delivery::ACCEPTED_NO_ACTION      => self::B_NO_ACTION,
        Facebook_delivery::ACCEPTED_UNASSIGNED     => self::B_REVIEW,
        Facebook_delivery::VERIFY_OK               => self::B_VERIFY,

        Facebook_delivery::VERIFY_BAD_TOKEN        => self::B_REJECTED,
        Facebook_delivery::VERIFY_BAD_MODE         => self::B_REJECTED,
        Facebook_delivery::REJECTED_NO_SIGNATURE   => self::B_REJECTED,
        Facebook_delivery::REJECTED_BAD_SIGNATURE  => self::B_REJECTED,
        Facebook_delivery::REJECTED_BAD_PAYLOAD    => self::B_REJECTED,
        Facebook_delivery::REJECTED_TOO_LARGE      => self::B_REJECTED,
        Facebook_delivery::REJECTED_BAD_CONTENT_TYPE => self::B_REJECTED,
        Facebook_delivery::REJECTED_RATE_LIMITED   => self::B_REJECTED,

        Facebook_delivery::VERIFY_NOT_CONFIGURED   => self::B_FAILED,
        Facebook_delivery::REJECTED_NOT_CONFIGURED => self::B_FAILED,
        Facebook_delivery::FAILED_EXCEPTION        => self::B_FAILED,
    );

    /* ---- alert identifiers ---- */

    const A_SIGNATURE_MISMATCH = 'signature_mismatch';
    const A_NOT_CONFIGURED = 'not_configured';
    const A_CONSECUTIVE_FAILURES = 'consecutive_failures';
    const A_THROTTLING = 'throttling';
    const A_REVIEW_BACKLOG = 'review_backlog';
    const A_SILENT_SINCE_SUCCESS = 'silent_since_success';
    const A_ALL_REFUSED = 'all_refused';
    const A_ENRICHMENT_PENDING = 'enrichment_pending';
    const A_ENRICHMENT_EXHAUSTED = 'enrichment_exhausted';
    const A_QUARANTINE_BACKLOG = 'quarantine_backlog';
    const A_DUPLICATE_BACKLOG = 'duplicate_backlog';

    /** Consecutive same-cause refusals before a conclusion is drawn. */
    const SIGNATURE_STREAK = 3;
    /** Consecutive exceptions before the integration is called broken. */
    const FAILURE_STREAK = 5;
    /** Unowned leads before the queue is called a backlog. */
    const REVIEW_BACKLOG = 5;
    /** Hours of silence after a working delivery before that is suspicious. */
    const SILENCE_HOURS = 48;

    public static function bucketFor($outcome)
    {
        return isset(self::BUCKET[$outcome]) ? self::BUCKET[$outcome] : self::B_FAILED;
    }

    /**
     * Count a set of `outcome => n` rows into buckets.
     *
     * Accepts the shape `deliveryTotals()` returns, and tolerates rows for
     * outcomes this build does not know about — those land in `failed` rather
     * than being dropped, because an unrecognised outcome is a problem and
     * silently discarding it would hide one.
     */
    public static function summarise(array $rows)
    {
        $out = array(
            self::B_CREATED => 0, self::B_DUPLICATE => 0, self::B_MATCHED => 0,
            self::B_NO_ACTION => 0, self::B_REVIEW => 0, self::B_REJECTED => 0,
            self::B_FAILED => 0, self::B_VERIFY => 0,
        );
        $byOutcome = array();
        $total = 0;
        $unknown = array();

        foreach ($rows as $row) {
            $row = (array) $row;
            $outcome = isset($row['outcome']) ? (string) $row['outcome'] : '';
            $n = isset($row['n']) ? (int) $row['n'] : 0;

            if ($outcome === '' || $n < 0) {
                continue;
            }

            if (!isset(self::BUCKET[$outcome])) {
                $unknown[] = $outcome;
            }

            $out[self::bucketFor($outcome)] += $n;
            $byOutcome[$outcome] = (isset($byOutcome[$outcome]) ? $byOutcome[$outcome] : 0) + $n;
            $total += $n;
        }

        $accepted = $out[self::B_CREATED] + $out[self::B_DUPLICATE] + $out[self::B_MATCHED]
                  + $out[self::B_NO_ACTION] + $out[self::B_REVIEW] + $out[self::B_VERIFY];

        return array(
            'buckets'      => $out,
            'by_outcome'   => $byOutcome,
            'total'        => $total,
            'accepted'     => $accepted,
            'not_accepted' => $out[self::B_REJECTED] + $out[self::B_FAILED],
            'unknown'      => array_values(array_unique($unknown)),
        );
    }

    /**
     * Interpret the recent history.
     *
     * @param array $ctx recent_outcomes  most-recent-first list of outcome strings
     *                   review_count      unowned Facebook leads
     *                   last_accepted     epoch of the last accepted delivery, or null
     *                   now               epoch
     *                   total             rows in the window
     *
     * @return array of array(id, severity, message) — severity: critical|warning|info
     */
    public static function alerts(array $ctx)
    {
        $recent = isset($ctx['recent_outcomes']) && is_array($ctx['recent_outcomes'])
            ? array_values($ctx['recent_outcomes']) : array();
        $alerts = array();

        /*
         * The diagnosis worth having.
         *
         * A run of signature failures has exactly one likely cause, and it is
         * not a transient one: the App Secret stored here is not the secret the
         * app Meta signs with. Saying so is the difference between a monitor
         * and a counter.
         */
        $sigStreak = self::streak($recent, array(Facebook_delivery::REJECTED_BAD_SIGNATURE));

        if ($sigStreak >= self::SIGNATURE_STREAK) {
            $alerts[] = self::alert(self::A_SIGNATURE_MISMATCH, 'critical',
                $sigStreak . ' consecutive deliveries failed signature verification. '
                . 'The App Secret stored here almost certainly does not belong to the '
                . 'Meta app that is signing these requests — check which app the Page '
                . 'is subscribed through. Leads are being refused and lost.');
        }

        $noSigStreak = self::streak($recent, array(Facebook_delivery::REJECTED_NO_SIGNATURE));

        if ($noSigStreak >= self::SIGNATURE_STREAK) {
            $alerts[] = self::alert(self::A_SIGNATURE_MISMATCH, 'critical',
                $noSigStreak . ' consecutive deliveries arrived with no signature header. '
                . 'Genuine Meta deliveries are always signed, so these are coming from '
                . 'somewhere else — or a proxy is stripping the header.');
        }

        $unconfStreak = self::streak($recent, array(
            Facebook_delivery::REJECTED_NOT_CONFIGURED,
            Facebook_delivery::VERIFY_NOT_CONFIGURED,
        ));

        if ($unconfStreak >= 1) {
            $alerts[] = self::alert(self::A_NOT_CONFIGURED, 'critical',
                'Deliveries are arriving but this install has no credentials configured, '
                . 'so none can be verified. Every one is being refused with 503. '
                . 'Enter the App Secret and Verify Token on the settings page.');
        }

        $failStreak = self::streak($recent, array(Facebook_delivery::FAILED_EXCEPTION));

        if ($failStreak >= self::FAILURE_STREAK) {
            $alerts[] = self::alert(self::A_CONSECUTIVE_FAILURES, 'critical',
                $failStreak . ' consecutive deliveries failed while being processed. '
                . 'Nothing was written for any of them — Meta will keep retrying. '
                . 'Check the activity log for the rolled-back transactions.');
        }

        $throttleStreak = self::streak($recent, array(Facebook_delivery::REJECTED_RATE_LIMITED));

        if ($throttleStreak >= self::SIGNATURE_STREAK) {
            $alerts[] = self::alert(self::A_THROTTLING, 'warning',
                $throttleStreak . ' consecutive deliveries were throttled. If this is Meta '
                . 'rather than an abusive source, the per-minute limit is too low for your '
                . 'lead volume and real leads are being deferred.');
        }

        /*
         * Every request refused, with no successes at all. Distinct from the
         * streak alerts: it catches a mixture of refusal causes, which a
         * single-cause streak check would miss entirely.
         */
        $total = isset($ctx['total']) ? (int) $ctx['total'] : count($recent);

        if ($total >= 5 && !empty($recent)) {
            $anyAccepted = false;

            foreach ($recent as $o) {
                if (Facebook_delivery::isAccepted($o)) {
                    $anyAccepted = true;
                    break;
                }
            }

            if (!$anyAccepted) {
                $alerts[] = self::alert(self::A_ALL_REFUSED, 'critical',
                    'Not one of the last ' . count($recent) . ' deliveries was accepted. '
                    . 'Meta is reaching this CRM and every request is being refused, so '
                    . 'the problem is configuration here rather than connectivity.');
            }
        }

        /*
         * Silence after success. This is the failure that looks like nothing at
         * all: the integration worked, then the Page Access Token expired or the
         * subscription lapsed, and the log simply stops. An empty log after a
         * working delivery is evidence, and without this check nobody reads it.
         */
        $lastAccepted = isset($ctx['last_accepted']) ? $ctx['last_accepted'] : null;
        $now = isset($ctx['now']) ? (int) $ctx['now'] : time();

        if ($lastAccepted !== null && (int) $lastAccepted > 0) {
            $hours = (int) floor(($now - (int) $lastAccepted) / 3600);

            if ($hours >= self::SILENCE_HOURS) {
                $alerts[] = self::alert(self::A_SILENT_SINCE_SUCCESS, 'warning',
                    'No delivery has been accepted for ' . $hours . ' hours, though this '
                    . 'integration has worked before. Check the Page subscription and '
                    . 'whether the Page Access Token has expired.');
            }
        }

        $review = isset($ctx['review_count']) ? (int) $ctx['review_count'] : 0;

        if ($review >= self::REVIEW_BACKLOG) {
            $alerts[] = self::alert(self::A_REVIEW_BACKLOG, 'warning',
                $review . ' Facebook leads have no owner. They are being created '
                . 'correctly but nobody is being told to call them.');
        }

        /*
         * A metadata failure is not a lost lead, and the threshold is ONE.
         *
         * Everything else on this screen tolerates a single event — one refused
         * delivery is a blip, one rate-limited request is traffic. This does
         * not, because tagging is deliberately best-effort and best-effort is
         * indistinguishable from broken unless somebody is told. A lead sitting
         * in a shared CRM without its business unit is a lead the wrong team
         * will work, and it looks completely normal until someone asks why the
         * DealPlex numbers are short.
         */
        /*
         * Quarantine and duplicate backlogs. Both are work queues for a person,
         * and both are invisible unless something says so: a quarantined
         * delivery is not on the Leads list by design, and a possible-duplicate
         * marker sits on a lead that otherwise looks completely normal.
         */
        $quarantined = isset($ctx['quarantine_open']) ? (int) $ctx['quarantine_open'] : 0;
        $dupes = isset($ctx['duplicates_open']) ? (int) $ctx['duplicates_open'] : 0;

        if ($quarantined > 0) {
            $alerts[] = self::alert(self::A_QUARANTINE_BACKLOG, 'warning',
                $quarantined . ' delivery(ies) arrived with no email and no phone number. They are '
                . 'stored in full and are NOT on the Leads list, because there is nothing to call '
                . 'or write to. Review them and add a contact identifier to release one as a lead.');
        }

        if ($dupes > 0) {
            $alerts[] = self::alert(self::A_DUPLICATE_BACKLOG, 'warning',
                $dupes . ' lead(s) look like someone already in the CRM. Nothing has been merged '
                . 'or discarded — each is a real lead awaiting a human judgement. Shared '
                . 'switchboard numbers are excluded automatically and are not counted here.');
        }

        $pending = isset($ctx['enrichment_pending']) ? (int) $ctx['enrichment_pending'] : 0;
        $exhausted = isset($ctx['enrichment_exhausted']) ? (int) $ctx['enrichment_exhausted'] : 0;

        if ($pending > 0) {
            $alerts[] = self::alert(self::A_ENRICHMENT_PENDING, 'warning',
                $pending . ' lead(s) were saved without all of their tags or Facebook '
                . 'reference fields. The leads themselves are intact and callable; what is '
                . 'missing is the labelling. Retry them from the Enrichment screen.');
        }

        if ($exhausted > 0) {
            $alerts[] = self::alert(self::A_ENRICHMENT_EXHAUSTED, 'critical',
                $exhausted . ' lead(s) have used every automatic retry and are still '
                . 'missing their labels. Automatic repair has stopped; this needs a person. '
                . 'The usual cause is a custom-field definition that was deleted after the '
                . 'module was installed.');
        }

        return $alerts;
    }

    /**
     * How many of the most recent outcomes, in order, are in the given set.
     *
     * Stops at the first outcome outside the set. Order matters: a streak is a
     * claim about *now*, and counting matches anywhere in the window would
     * report a problem that was fixed yesterday.
     */
    public static function streak(array $recentFirst, array $wanted)
    {
        $n = 0;

        foreach ($recentFirst as $outcome) {
            if (!in_array($outcome, $wanted, true)) {
                break;
            }

            $n++;
        }

        return $n;
    }

    /** Is anything here worth an administrator's attention right now? */
    public static function worstSeverity(array $alerts)
    {
        $rank = array('critical' => 3, 'warning' => 2, 'info' => 1);
        $worst = null;

        foreach ($alerts as $a) {
            $s = isset($a['severity']) ? $a['severity'] : 'info';

            if ($worst === null || $rank[$s] > $rank[$worst]) {
                $worst = $s;
            }
        }

        return $worst;
    }

    /**
     * Deliveries Meta is expected to send again.
     *
     * Counted from the retryable flag rather than re-derived here, so the
     * report and the response cannot disagree about what was retryable.
     */
    public static function retryPending(array $rows)
    {
        $n = 0;

        foreach ($rows as $row) {
            $row = (array) $row;

            if (!empty($row['retryable'])) {
                $n += isset($row['n']) ? (int) $row['n'] : 1;
            }
        }

        return $n;
    }

    private static function alert($id, $severity, $message)
    {
        return array('id' => $id, 'severity' => $severity, 'message' => $message);
    }
}
