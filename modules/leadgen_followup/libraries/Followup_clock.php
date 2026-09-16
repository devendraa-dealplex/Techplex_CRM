<?php
defined('BASEPATH') or defined('LEADGEN_FU_TEST') or exit('No direct script access allowed');

/**
 * Followup_clock — one clock, one timezone, for a whole cron run.
 *
 * WHY THIS EXISTS
 * ---------------
 * The defect this module is being fixed for is a timing defect, and the old
 * code read time from three different places without noticing:
 *
 *   - `time()` and `date()`            — PHP's ambient default timezone
 *   - `strtotime($row['lastcontact'])` — a database DATETIME reinterpreted in
 *                                        PHP's zone, whatever that is
 *   - MySQL `NOW()` in any query       — the *database server's* zone
 *
 * On this staging box those are not the same. The CRM is configured
 * `Asia/Kolkata`; `SELECT NOW()` returned `2026-09-12 02:32` while the wall
 * clock in Kolkata was `08:02`, and `@@session.time_zone` is `SYSTEM`. A
 * 5.5-hour disagreement in a module whose smallest rule is "at least 24 hours"
 * is not a rounding detail: it moves a calendar-day boundary, and "never send
 * two stages on the same day" is a calendar-day rule.
 *
 * So the rule here is absolute: **one `DateTimeImmutable` is created at the top
 * of a run, in the CRM's configured zone, and every comparison in SQL and in
 * PHP uses that same value passed as a bound parameter.** `NOW()` never appears
 * in a query in this module, and nothing calls `time()` after the run starts.
 *
 * A run that took its own time twice could disagree with itself. This one
 * cannot, because there is only one value to disagree with.
 */
class Followup_clock
{
    /** Perfex stores the CRM zone in this option. */
    const OPTION = 'default_timezone';

    /** Used only when the option is absent or names a zone PHP does not know. */
    const FALLBACK = 'UTC';

    /** @var DateTimeImmutable */
    private $now;

    /** @var DateTimeZone */
    private $tz;

    /** @var string one of: option | fallback_missing | fallback_invalid */
    private $source;

    private function __construct(DateTimeImmutable $now, DateTimeZone $tz, $source)
    {
        $this->now    = $now;
        $this->tz     = $tz;
        $this->source = $source;
    }

    /**
     * Build the clock from the CRM option value.
     *
     * `$optionValue` is passed in rather than read here so this class stays
     * pure and testable — the tests drive it with a fixed zone and a fixed
     * instant, which is the only way to assert a 24-hour rule deterministically.
     *
     * `$nowString` is optional and exists for tests. In production it is null
     * and the clock takes the real instant exactly once.
     */
    public static function fromCrmTimezone($optionValue, $nowString = null)
    {
        $name   = is_string($optionValue) ? trim($optionValue) : '';
        $source = 'option';

        if ($name === '') {
            $name   = self::FALLBACK;
            $source = 'fallback_missing';
        }

        try {
            $tz = new DateTimeZone($name);
        } catch (Exception $e) {
            $tz     = new DateTimeZone(self::FALLBACK);
            $source = 'fallback_invalid';
        }

        if ($nowString === null) {
            $now = new DateTimeImmutable('now', $tz);
        } else {
            $now = new DateTimeImmutable($nowString, $tz);
        }

        return new self($now, $tz, $source);
    }

    /** @return DateTimeImmutable the single instant this run is reasoning about */
    public function now()
    {
        return $this->now;
    }

    /** @return DateTimeZone */
    public function timezone()
    {
        return $this->tz;
    }

    /** Which of the three paths produced the zone — recorded in the run audit. */
    public function source()
    {
        return $this->source;
    }

    /** The zone actually in use, by name. */
    public function timezoneName()
    {
        return $this->tz->getName();
    }

    /** `Y-m-d H:i:s` for binding into SQL. Never `NOW()`. */
    public function sql()
    {
        return $this->now->format('Y-m-d H:i:s');
    }

    /** `Y-m-d` — the calendar day the "one stage per day" rule is measured in. */
    public function day()
    {
        return $this->now->format('Y-m-d');
    }

    /**
     * Read a database DATETIME as a moment in the CRM zone.
     *
     * Perfex writes lead timestamps with PHP `date()` under the CRM zone, so
     * that is how they must be read back. Returns null for NULL, '', the MySQL
     * zero date, and anything unparseable — a caller that cannot establish an
     * anchor must skip the lead, not guess one.
     */
    public function parse($dbDateTime)
    {
        if (!is_string($dbDateTime)) {
            return null;
        }

        $v = trim($dbDateTime);

        if ($v === '' || $v === '0000-00-00 00:00:00' || $v === '0000-00-00') {
            return null;
        }

        try {
            $d = new DateTimeImmutable($v, $this->tz);
        } catch (Exception $e) {
            return null;
        }

        /*
         * `DateTimeImmutable` accepts a surprising amount of nonsense — 'now',
         * 'tomorrow', '4abc' — so the parsed value is required to round-trip to
         * a real date. Without this, a corrupt column would silently become
         * "right now" and every stage would look due.
         */
        $errors = DateTimeImmutable::getLastErrors();

        if (is_array($errors) && (!empty($errors['error_count']) || !empty($errors['warning_count']))) {
            return null;
        }

        if (!preg_match('/\A\d{4}-\d{2}-\d{2}(\s+\d{2}:\d{2}(:\d{2})?)?\z/', $v)) {
            return null;
        }

        return $d;
    }

    /** Whole hours between two instants, floored. Negative when $b precedes $a. */
    public static function hoursBetween(DateTimeImmutable $a, DateTimeImmutable $b)
    {
        $seconds = $b->getTimestamp() - $a->getTimestamp();

        return (int) floor($seconds / 3600);
    }
}
