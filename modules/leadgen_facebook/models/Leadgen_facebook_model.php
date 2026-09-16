<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
 * This file loads its own dependencies.
 *
 * WHY, AND HOW IT WAS FOUND
 * -------------------------
 * The libraries were required by the module bootstrap
 * (`leadgen_facebook.php`), which Perfex executes only for modules marked
 * active. The HMVC router, however, resolves `/<module>/<controller>/<method>`
 * by finding the controller file on disk, and does not consult
 * `tblmodules.active` at all.
 *
 * So on a freshly deployed, not-yet-activated install, the public webhook URL
 * is routable and its classes are not loaded — and the endpoint answered a
 * live GET with **HTTP 500 and an empty body**. Measured on production
 * immediately after deployment, before activation.
 *
 * That is the wrong failure in two ways. Meta retries a 5xx, so an
 * unactivated install would collect retries for a delivery it never recorded;
 * and a fatal produces no log row, which is the exact silence this whole
 * change set exists to remove.
 *
 * `require_once` is idempotent, so this costs nothing when the bootstrap has
 * already run and makes the endpoint correct when it has not.
 */
require_once __DIR__ . '/../libraries/Facebook_settings.php';
require_once __DIR__ . '/../libraries/Facebook_redactor.php';
require_once __DIR__ . '/../libraries/Facebook_delivery.php';
require_once __DIR__ . '/../libraries/Facebook_status.php';
require_once __DIR__ . '/../libraries/Facebook_assignment.php';
require_once __DIR__ . '/../libraries/Facebook_guard.php';
require_once __DIR__ . '/../libraries/Facebook_health.php';
require_once __DIR__ . '/../libraries/Facebook_tagging.php';
require_once __DIR__ . '/../libraries/Facebook_identity.php';


/**
 * Every database write this module makes, in one place.
 *
 * The webhook controller used to talk to `$this->db` directly, across seven
 * methods, with the lead insert and the idempotency record as two independent
 * statements. That is the shape that produces duplicates: if anything fails
 * between them — a DB error, a timeout, a fatal in the middle of a Graph API
 * call — the lead exists and the record that says "we have handled this
 * delivery" does not, so Meta's retry creates a second lead, and a third.
 *
 * Here the two are one transaction, and the claim is taken *first*.
 */
class Leadgen_facebook_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /* ================================================================== */
    /* Reference data                                                     */
    /* ================================================================== */

    public function leadStatuses()
    {
        return $this->db->order_by('statusorder', 'asc')
                        ->get(db_prefix() . 'leads_status')->result_array();
    }

    public function leadSources()
    {
        return $this->db->order_by('id', 'asc')
                        ->get(db_prefix() . 'leads_sources')->result_array();
    }

    /**
     * Staff who may currently receive a lead.
     *
     * `active = 1` only. A deactivated employee is not an eligible assignee, and
     * the assignment library is given this list rather than deciding for itself,
     * so "who can own a lead" is one query that can be read.
     */
    public function eligibleStaffIds()
    {
        $rows = $this->db->select('staffid')
                         ->where('active', 1)
                         ->get(db_prefix() . 'staff')->result_array();

        $ids = array();

        foreach ($rows as $r) {
            $id = (int) $r['staffid'];

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function adminStaffEmails()
    {
        $rows = $this->db->select('email')
                         ->where('admin', 1)->where('active', 1)
                         ->get(db_prefix() . 'staff')->result_array();

        $out = array();

        foreach ($rows as $r) {
            if (!empty($r['email'])) {
                $out[] = $r['email'];
            }
        }

        return $out;
    }

    /* ================================================================== */
    /* The lead source, created at most once                              */
    /* ================================================================== */

    /**
     * Find the lead source by name, creating it if it is genuinely absent.
     *
     * Idempotent in the sense that matters: called a hundred times it produces
     * one row. The match is on a normalised name (case-insensitive, whitespace
     * collapsed) so an install that already has "facebook lead ads" is not given
     * a second near-identical source with the leads split between them.
     *
     * After an insert it re-reads by name and returns the LOWEST matching id.
     * If two requests ever raced and both inserted, every subsequent caller then
     * converges on the same source instead of the two of them disagreeing
     * forever, and the duplicate is reported so a human can merge it.
     *
     * There is no hard-coded id anywhere in this method. Staging's
     * "Facebook Lead Ads" is id 20; production's sources stop at 19 and its
     * next id is not knowable in advance.
     */
    public function ensureSource($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            return array('id' => null, 'created' => false, 'duplicates' => 0);
        }

        $rows = $this->leadSources();
        $id = Facebook_status::findSourceId($rows, $name);

        if ($id !== null) {
            return array('id' => $id, 'created' => false, 'duplicates' => 0);
        }

        $this->db->insert(db_prefix() . 'leads_sources', array('name' => $name));

        $rows = $this->leadSources();
        $matches = array();

        foreach ($rows as $row) {
            if (Facebook_status::key($row['name']) === Facebook_status::key($name)) {
                $matches[] = (int) $row['id'];
            }
        }

        sort($matches);

        return array(
            'id'         => empty($matches) ? null : $matches[0],
            'created'    => true,
            'duplicates' => max(0, count($matches) - 1),
        );
    }

    /* ================================================================== */
    /* The inbound delivery log                                           */
    /* ================================================================== */

    /**
     * Record one inbound request.
     *
     * Runs OUTSIDE any transaction, and swallows its own failures.
     *
     * Both of those are deliberate. Inside a transaction, a rolled-back
     * delivery would roll back its own log row — so the one case most worth
     * investigating would be the one case that left no evidence. And a log
     * write that throws must not turn a refused delivery into a 500, because
     * then Meta retries a request we have already correctly refused.
     *
     * The cost is that a log write can be lost without anybody knowing. That is
     * the right trade for an audit trail that must never change the outcome it
     * is describing, and the activity log gets a line when it happens.
     */
    public function logDelivery(array $row)
    {
        try {
            if (!$this->db->table_exists(db_prefix() . 'leadgen_facebook_deliveries')) {
                return false;
            }

            $this->db->insert(db_prefix() . 'leadgen_facebook_deliveries', $row);

            return $this->db->insert_id() > 0;
        } catch (Throwable $e) {
            /*
             * Throwable, not Exception: a DB error here is a PHP Error in some
             * drivers, and letting it escape would change the HTTP status of a
             * delivery that has already been decided.
             */
            log_activity('leadgen_facebook: delivery log write failed (request '
                . (isset($row['request_id']) ? $row['request_id'] : '?') . ')');

            return false;
        }
    }

    /**
     * How many requests this source has made inside the window.
     *
     * The delivery log is the rate limiter's counter. There is no Redis on this
     * host and introducing a second store for one counter would be a larger
     * change than the limiter is worth — and the log has a property a cache
     * does not: it survives, so "we throttled this source 400 times last
     * Tuesday" is answerable afterwards.
     *
     * Counts on `received_epoch`, a UTC integer, NOT on the formatted
     * `received_at`. This install has a measured 12h30m gap between the CRM
     * timezone (Asia/Kolkata) and the database session timezone (SYSTEM,
     * resolving to UTC-07:00), so a window computed with MySQL's NOW() against
     * a datetime written by PHP's date() would be wrong by half a day — either
     * counting nothing and never throttling, or counting everything and
     * throttling always. Neither failure announces itself.
     *
     * Returns 0 when the table does not exist yet, which is the pre-activation
     * state: no log means no counting, and an unactivated module's endpoint
     * refuses everything anyway.
     */
    public function recentFromSource($sourceKey, $sinceEpoch)
    {
        if ((string) $sourceKey === '') {
            return 0;
        }

        try {
            if (!$this->db->table_exists(db_prefix() . 'leadgen_facebook_deliveries')) {
                return 0;
            }

            if (!$this->db->field_exists('source_key', db_prefix() . 'leadgen_facebook_deliveries')) {
                return 0;
            }

            $row = $this->db->select('COUNT(*) AS n', false)
                            ->where('source_key', (string) $sourceKey)
                            ->where('received_epoch >=', (int) $sinceEpoch)
                            ->get(db_prefix() . 'leadgen_facebook_deliveries')->row_array();

            return $row ? (int) $row['n'] : 0;
        } catch (Throwable $e) {
            /*
             * A counter that throws must not refuse the delivery. Failing open
             * here is deliberate: the signature check is what protects this
             * endpoint, and a broken limiter dropping real leads would be a
             * worse outcome than a broken limiter not throttling an abuser.
             */
            return 0;
        }
    }

    /**
     * The per-install salt the source key is hashed with, generated once.
     *
     * Required, not optional. An unsalted hash of an IPv4 address is reversible
     * by anybody willing to compute four billion hashes, so it would store the
     * address while looking as though it did not — the worst of both.
     *
     * It cannot be seeded by a migration, because `add_option()` takes a
     * literal and a literal shipped in a file is identical on every install,
     * which is the same as having no salt. So it is created on first use.
     */
    public function ipSalt()
    {
        $salt = (string) get_option('facebook_ip_salt');

        if (trim($salt) !== '') {
            return $salt;
        }

        try {
            $salt = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $salt = hash('sha256', uniqid('fbsalt', true) . mt_rand());
        }

        update_option('facebook_ip_salt', $salt);

        return $salt;
    }

    public function updateDelivery($requestId, array $fields)
    {
        try {
            $this->db->where('request_id', (string) $requestId)
                     ->update(db_prefix() . 'leadgen_facebook_deliveries', $fields);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ================================================================== */
    /* The claim, and the lead                                            */
    /* ================================================================== */

    /**
     * Has this exact delivery already been handled?
     *
     * Used only to answer quickly before doing paid work (a Graph API call).
     * It is NOT the duplicate defence — the UNIQUE key is. A check here that
     * says "no" can still be overtaken by a concurrent request, which is
     * precisely why claim() does not trust it.
     */
    public function existingClaim($channel, $fbRef)
    {
        $row = $this->db->where('channel', (string) $channel)
                        ->where('fb_ref', (string) $fbRef)
                        ->get(db_prefix() . 'leadgen_facebook_messages')->row_array();

        return $row ? $row : null;
    }

    /**
     * Take the delivery reference, create the lead, and link them — or do
     * nothing at all.
     *
     * ORDER OF OPERATIONS, AND WHY
     * ----------------------------
     *   1. INSERT IGNORE the claim row.  The UNIQUE (channel, fb_ref) key makes
     *      this the atomic step: exactly one concurrent request can succeed.
     *      affected_rows() === 0 means somebody else already owns this
     *      delivery, so this request stops — it does not create a lead, and it
     *      reports the duplicate rather than an error.
     *   2. Look for an existing lead by email, then phone.  A match means no
     *      new lead; the claim row still records that the delivery arrived and
     *      which lead it belongs to.
     *   3. INSERT the lead.
     *   4. UPDATE the claim row with the lead id and the assignment decision.
     *
     * The claim is taken before the lead is created, not after, so the failure
     * mode is a claim with no lead — which a retry cannot turn into two leads,
     * and which is visible in the log — rather than a lead with no claim, which
     * a retry duplicates.
     *
     * Everything is in one transaction in manual mode. On any failure the whole
     * thing is rolled back, leaving the reference unclaimed so that Meta's next
     * retry is free to try again cleanly.
     *
     * @return array outcome, lead_id, claim_id, created (bool), matched (bool)
     */
    public function claimAndCreateLead(array $ctx)
    {
        $messages = db_prefix() . 'leadgen_facebook_messages';
        $leads = db_prefix() . 'leads';

        $channel = (string) $ctx['channel'];
        $fbRef = (string) $ctx['fb_ref'];

        $this->db->trans_begin();

        try {
            /* ---- 1. the atomic claim ---- */
            $this->db->query(
                "INSERT IGNORE INTO `{$messages}`
                    (`lead_id`, `channel`, `fb_ref`, `page_id`, `form_id`, `campaign_id`,
                     `request_id`, `assignment_mode`, `assigned_to`, `needs_review`,
                     `message_body`, `raw_payload`, `date_created`)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                array(
                    $channel,
                    $fbRef,
                    $ctx['page_id'],
                    $ctx['form_id'],
                    $ctx['campaign_id'],
                    $ctx['request_id'],
                    (string) $ctx['assignment_mode'],
                    (int) $ctx['assigned_to'],
                    !empty($ctx['needs_review']) ? 1 : 0,
                    (string) $ctx['message_body'],
                    (string) $ctx['raw_payload'],
                    (string) $ctx['now'],
                )
            );

            if ($this->db->affected_rows() < 1) {
                /*
                 * Somebody already claimed this reference. Nothing has been
                 * written by this request, so the rollback is a formality — but
                 * it closes the transaction cleanly rather than leaving it open
                 * for the rest of the request.
                 */
                $this->db->trans_rollback();

                $existing = $this->existingClaim($channel, $fbRef);

                return array(
                    'outcome' => 'duplicate',
                    'lead_id' => $existing && $existing['lead_id'] ? (int) $existing['lead_id'] : null,
                    'claim_id' => $existing ? (int) $existing['id'] : null,
                    'created' => false,
                    'matched' => false,
                );
            }

            $claimId = (int) $this->db->insert_id();

            /*
             * ---- 2. can anybody contact this person? ----
             *
             * The delivery is real and the claim is taken either way. What is
             * decided here is whether it becomes a LEAD.
             *
             * Neither an email nor a phone number means there is nothing for a
             * salesperson to do with it. Put it on the Leads list and they open
             * it, find nothing, and after the third one they stop trusting the
             * list. Drop it and the delivery vanishes, which is the silence this
             * whole engagement exists to remove. So it is kept in quarantine:
             * complete, reviewable, and out of the way.
             */
            if (!Facebook_identity::isContactable($ctx['email'], $ctx['phone'])) {
                $qid = $this->quarantineDelivery($channel, $fbRef, $ctx);

                if ($this->db->trans_status() === false) {
                    $this->db->trans_rollback();

                    return array('outcome' => 'failed', 'lead_id' => null, 'claim_id' => null,
                                 'created' => false, 'matched' => false);
                }

                $this->db->trans_commit();

                log_activity('leadgen_facebook: delivery ' . $ctx['request_id']
                    . ' quarantined — no contact identifier (Facebook lead ' . $fbRef . ').');

                return array(
                    'outcome'      => 'quarantined',
                    'lead_id'      => null,
                    'claim_id'     => $claimId,
                    'created'      => false,
                    'matched'      => false,
                    'quarantine_id' => $qid,
                    'reason'       => Facebook_identity::contactState($ctx['email'], $ctx['phone']),
                );
            }

            /* ---- 3. create the lead ---- */
            $matched = false;

            {
                $insert = array(
                    'name'         => (string) $ctx['name'],
                    'email'        => (string) $ctx['email'],
                    'phonenumber'  => (string) $ctx['phone'],
                    'source'       => (int) $ctx['source_id'],
                    'status'       => (int) $ctx['status_id'],
                    'assigned'     => (int) $ctx['assigned_to'],
                    'dateadded'    => (string) $ctx['now'],
                    'addedfrom'    => 0,
                    'is_public'    => 0,
                    'from_form_id' => 0,
                );

                /*
                 * Perfex gives every lead a `hash` and uses it to build the
                 * lead's public link. A lead created by a module that skips it
                 * looks normal on the Leads screen and has a broken public URL
                 * — the sort of defect found months later by a salesperson who
                 * assumes they did something wrong. Generated with Perfex's own
                 * helper where it exists, and only when the column does.
                 */
                if (function_exists('app_generate_hash')) {
                    $insert['hash'] = app_generate_hash();
                }

                $this->db->insert($leads, $insert);
                $leadId = (int) $this->db->insert_id();

                if ($leadId < 1) {
                    throw new RuntimeException('lead insert returned no id');
                }
            }

            /*
             * ---- 3b. does this look like somebody we already have? ----
             *
             * MARKED, NEVER MERGED.
             *
             * The old code attached a matching delivery to the existing lead and
             * created nothing. Two colleagues filling one form from one
             * switchboard number are two leads and two commissions, and the
             * second one disappeared with no record it ever arrived.
             *
             * Now the lead is always created and the resemblance is recorded for
             * a person to judge. A wrong marker costs somebody thirty seconds; a
             * wrong merge costs a lead nobody knows to look for.
             */
            $duplicates = $this->markPossibleDuplicates($leadId, $fbRef, $ctx);

            /* ---- 4. link the claim to the lead ---- */
            $this->db->where('id', $claimId)->update($messages, array('lead_id' => $leadId));

            /*
             * ---- 5. tag the lead and record its references ----
             *
             * Inside the transaction, so a rolled-back delivery cannot leave a
             * half-tagged lead. Its individual writes are best-effort and
             * report rather than throw: a lead missing a campaign id is still a
             * lead somebody can call, whereas a lead rolled back because the
             * tag table hiccuped is a lead that is gone.
             */
            $refs = $this->applyLeadReferences($leadId, array(
                'source_name' => isset($ctx['source_name']) ? $ctx['source_name'] : '',
                'page_name'   => isset($ctx['page_name']) ? $ctx['page_name'] : '',
                'page_id'     => $ctx['page_id'],
                'form_id'     => $ctx['form_id'],
                'campaign_id' => $ctx['campaign_id'],
                'leadgen_id'  => $fbRef,
                'extra_tags'  => empty($duplicates) ? array() : array(Facebook_identity::duplicateTag()),
            ));

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();

                return array('outcome' => 'failed', 'lead_id' => null, 'claim_id' => null,
                             'created' => false, 'matched' => false);
            }

            $this->db->trans_commit();

            /*
             * The lead is safely committed. Only now is a metadata failure
             * recorded, and deliberately OUTSIDE the transaction: inside it, a
             * delivery that rolled back would roll back its own failure record
             * too, and a rolled-back delivery has no lead to enrich anyway.
             *
             * Best-effort is only defensible if the failure is visible. This is
             * what makes it visible.
             */
            if (!empty($refs['failures'])) {
                $this->queueEnrichment($leadId, $ctx, $refs['failures']);
            }

            return array(
                'outcome'    => 'created',
                'lead_id'    => $leadId,
                'claim_id'   => $claimId,
                'created'    => true,
                'matched'    => $matched,
                'refs'       => $refs,
                'duplicates' => $duplicates,
            );
        } catch (Throwable $e) {
            $this->db->trans_rollback();

            log_activity('leadgen_facebook: delivery ' . $ctx['request_id']
                . ' rolled back — ' . $e->getMessage());

            return array('outcome' => 'failed', 'lead_id' => null, 'claim_id' => null,
                         'created' => false, 'matched' => false,
                         'error' => $e->getMessage());
        }
    }

    /* ================================================================== */
    /* Reading it back                                                    */
    /* ================================================================== */

    public function deliveries($limit = 100, $outcome = '')
    {
        if (!$this->db->table_exists(db_prefix() . 'leadgen_facebook_deliveries')) {
            return array();
        }

        if ($outcome !== '') {
            $this->db->where('outcome', $outcome);
        }

        return $this->db->order_by('id', 'desc')->limit((int) $limit)
                        ->get(db_prefix() . 'leadgen_facebook_deliveries')->result_array();
    }

    public function deliveryTotals()
    {
        if (!$this->db->table_exists(db_prefix() . 'leadgen_facebook_deliveries')) {
            return array();
        }

        return $this->db->query(
            'SELECT outcome, accepted, COUNT(*) AS n, MAX(received_at) AS last_seen
             FROM `' . db_prefix() . 'leadgen_facebook_deliveries`
             GROUP BY outcome, accepted ORDER BY n DESC'
        )->result_array();
    }

    /**
     * The unassigned review queue.
     *
     * Derived from the leads themselves rather than kept as a separate list, so
     * that a lead somebody assigns by hand leaves the queue immediately without
     * anything having to remember to remove it. A second list would go stale the
     * first time a manager used the normal Leads screen.
     */
    public function reviewQueue($sourceId, $limit = 200)
    {
        if ((int) $sourceId < 1) {
            return array();
        }

        return $this->db->select('id, name, email, phonenumber, status, dateadded')
                        ->where('source', (int) $sourceId)
                        ->group_start()->where('assigned', 0)->or_where('assigned IS NULL')->group_end()
                        ->order_by('dateadded', 'desc')->limit((int) $limit)
                        ->get(db_prefix() . 'leads')->result_array();
    }

    /* ================================================================== */
    /* Tags and reference fields on the lead itself                        */
    /* ================================================================== */

    /**
     * Find a tag by name, creating it only if genuinely absent.
     *
     * Matched on a normalised name so "DealPlex", "dealplex" and "DealPlex "
     * are one tag rather than three. Perfex's tag list is global and shared
     * with every other module, so a near-duplicate here is visible to the whole
     * CRM and effectively permanent — nobody goes back and merges tags.
     */
    public function ensureTag($name)
    {
        $name = Facebook_tagging::cleanTag($name);

        if ($name === '') {
            return null;
        }

        $table = db_prefix() . 'tags';

        try {
            $rows = $this->db->get($table)->result_array();

            foreach ($rows as $row) {
                if (Facebook_tagging::key($row['name']) === Facebook_tagging::key($name)) {
                    return (int) $row['id'];
                }
            }

            $this->db->insert($table, array('name' => $name));
            $id = (int) $this->db->insert_id();

            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Which table joins a tag to a record on THIS install.
     *
     * FOUND IN PREFLIGHT, BEFORE DEPLOYING
     * ------------------------------------
     * This code was written against `tbltags_in`. Production has
     * `tbltaggables` — same four columns, different name; Perfex renamed it
     * between releases. Every tag write would have thrown, been caught, and
     * landed silently in the enrichment queue as a permanent failure: leads
     * created correctly, never tagged, on a CRM whose whole point right now is
     * telling one business unit's leads from another's.
     *
     * So the name is resolved from the live schema rather than assumed, with an
     * uncached `SHOW TABLES` for the same reason every other guard in this
     * module uses one. Neither present returns null, and the caller records a
     * failure a human will see instead of writing into nothing.
     */
    public function tagLinkTable()
    {
        foreach (array('tags_in', 'taggables') as $candidate) {
            $table = db_prefix() . $candidate;

            try {
                $rows = $this->db->query('SHOW TABLES LIKE ' . $this->db->escape($table))->result_array();

                if (!empty($rows)) {
                    return $table;
                }
            } catch (Throwable $e) {
                /* Try the next candidate rather than giving up on the first
                   driver hiccup. */
            }
        }

        return null;
    }

    /**
     * Attach a tag to a lead, once.
     *
     * The tag-link table has no unique constraint on
     * (tag_id, rel_id, rel_type) on a stock install, so a repeated call would
     * attach the same tag twice and the lead would display it twice. Checked
     * before inserting; the check is safe here because this runs inside the
     * lead-creation transaction, which is the only writer for this lead at this
     * moment.
     */
    public function linkTag($tagId, $leadId)
    {
        $tagId = (int) $tagId;
        $leadId = (int) $leadId;

        if ($tagId < 1 || $leadId < 1) {
            return false;
        }

        $table = $this->tagLinkTable();

        if ($table === null) {
            return false;
        }

        try {
            $existing = $this->db->where('tag_id', $tagId)
                                 ->where('rel_id', $leadId)
                                 ->where('rel_type', 'lead')
                                 ->get($table)->row();

            if ($existing) {
                return true;
            }

            $this->db->insert($table, array(
                'tag_id'   => $tagId,
                'rel_id'   => $leadId,
                'rel_type' => 'lead',
                'tag_order' => 0,
            ));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The id of one of this module's own custom-field definitions.
     *
     * Restricted to slugs the module owns, so this cannot be turned into a
     * lookup for an arbitrary custom field elsewhere in the CRM — the values
     * written here come from an unauthenticated request body, and the set of
     * fields they may reach is fixed in code rather than derived from input.
     */
    public function customFieldId($slug)
    {
        if (!Facebook_tagging::isOwnedField($slug)) {
            return null;
        }

        try {
            $row = $this->db->where('fieldto', 'leads')->where('slug', (string) $slug)
                            ->get(db_prefix() . 'customfields')->row();

            return $row ? (int) $row->id : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Write one reference value against a lead.
     *
     * Upsert rather than insert: Meta can re-deliver, and a lead matched by
     * email rather than created fresh may already carry values from an earlier
     * delivery. Two rows for one field on one lead makes the field render
     * unpredictably.
     */
    public function writeCustomFieldValue($fieldId, $leadId, $value)
    {
        $fieldId = (int) $fieldId;
        $leadId = (int) $leadId;

        if ($fieldId < 1 || $leadId < 1) {
            return false;
        }

        $table = db_prefix() . 'customfieldsvalues';

        try {
            $existing = $this->db->where('relid', $leadId)
                                 ->where('fieldid', $fieldId)
                                 ->where('fieldto', 'leads')
                                 ->get($table)->row();

            if ($existing) {
                $this->db->where('id', (int) $existing->id)
                         ->update($table, array('value' => (string) $value));

                return true;
            }

            $this->db->insert($table, array(
                'relid'   => $leadId,
                'fieldid' => $fieldId,
                'fieldto' => 'leads',
                'value'   => (string) $value,
            ));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Tag the lead and record its Facebook references.
     *
     * Called from inside the lead-creation transaction, so a rolled-back
     * delivery cannot leave a half-tagged lead behind.
     *
     * Every individual write is best-effort and reports rather than throws. The
     * reasoning: a lead that exists but is missing a campaign id is a lead
     * somebody can still call, while a lead that was rolled back because the
     * tag table hiccuped is a lead that is simply gone. Losing the label is
     * recoverable; losing the lead is not. What is NOT best-effort is the lead
     * and its claim row — those are the transaction.
     *
     * @return array tags_applied, fields_written, failures
     */
    public function applyLeadReferences($leadId, array $ctx)
    {
        $result = array('tags_applied' => array(), 'fields_written' => array(), 'failures' => array());

        if ((int) $leadId < 1) {
            return $result;
        }

        if ((string) get_option('facebook_tagging_enabled') !== '1') {
            return $result;
        }

        $tags = Facebook_tagging::tagsFor(
            get_option('facebook_business_unit'),
            isset($ctx['source_name']) ? $ctx['source_name'] : '',
            get_option('facebook_page_name')
        );

        /*
         * Extra tags the caller has decided on — currently "Possible
         * Duplicate". Cleaned by the same rules as every other tag so a caller
         * cannot inject a comma and split one tag into two.
         */
        if (!empty($ctx['extra_tags']) && is_array($ctx['extra_tags'])) {
            foreach ($ctx['extra_tags'] as $extra) {
                $clean = Facebook_tagging::cleanTag($extra);

                if ($clean !== '' && !in_array($clean, $tags, true)) {
                    $tags[] = $clean;
                }
            }
        }

        foreach ($tags as $tagName) {
            $tagId = $this->ensureTag($tagName);

            if ($tagId === null) {
                $result['failures'][] = 'tag:' . $tagName;
                continue;
            }

            if ($this->linkTag($tagId, $leadId)) {
                $result['tags_applied'][] = $tagName;
            } else {
                $result['failures'][] = 'link:' . $tagName;
            }
        }

        foreach (Facebook_tagging::referenceFields($ctx) as $slug => $value) {
            $fieldId = $this->customFieldId($slug);

            if ($fieldId === null) {
                $result['failures'][] = 'field_missing:' . $slug;
                continue;
            }

            if ($this->writeCustomFieldValue($fieldId, $leadId, $value)) {
                $result['fields_written'][] = $slug;
            } else {
                $result['failures'][] = 'field_write:' . $slug;
            }
        }

        return $result;
    }

    /* ================================================================== */
    /* Quarantine: deliveries nobody can act on                            */
    /* ================================================================== */

    /**
     * Keep a delivery that carried no way to contact anybody.
     *
     * Runs INSIDE the claim transaction, unlike the delivery log: the claim and
     * the quarantine record are one fact. A claim with no quarantine row would
     * be a delivery that is permanently ignored — the idempotency guard would
     * refuse every retry, and nothing would exist to show for it.
     *
     * Stores the redacted field summary, never the raw payload. If a form sent
     * a name and a postcode and nothing else, that is what a reviewer sees.
     */
    public function quarantineDelivery($channel, $fbRef, array $ctx)
    {
        $table = db_prefix() . 'leadgen_facebook_quarantine';

        if (!$this->db->table_exists($table)) {
            /* Loud, because the alternative is discarding the delivery. The
               transaction is still committed by the caller: the claim row
               preserves the fact that it arrived. */
            log_activity('leadgen_facebook: quarantine table missing — delivery '
                . $ctx['request_id'] . ' recorded as a claim only.');

            return 0;
        }

        $summary = isset($ctx['raw_payload']) ? (string) $ctx['raw_payload'] : '';

        $this->db->insert($table, array(
            'channel'       => (string) $channel,
            'fb_ref'        => (string) $fbRef,
            'request_id'    => isset($ctx['request_id']) ? (string) $ctx['request_id'] : '',
            'page_id'       => (string) $ctx['page_id'],
            'form_id'       => (string) $ctx['form_id'],
            'campaign_id'   => (string) $ctx['campaign_id'],
            'display_name'  => mb_substr(Facebook_tagging::cleanValue($ctx['name']), 0, 191),
            'reason'        => Facebook_identity::contactState($ctx['email'], $ctx['phone']),
            'field_summary' => mb_substr($summary, 0, 8000),
            'created_epoch' => time(),
        ));

        return (int) $this->db->insert_id();
    }

    public function quarantineQueue($limit = 200)
    {
        try {
            $table = db_prefix() . 'leadgen_facebook_quarantine';

            if (!$this->db->table_exists($table)) {
                return array();
            }

            return $this->db->order_by('released_lead_id', 'asc')->order_by('id', 'desc')
                            ->limit((int) $limit)->get($table)->result_array();
        } catch (Throwable $e) {
            return array();
        }
    }

    public function quarantineOpenCount()
    {
        try {
            $table = db_prefix() . 'leadgen_facebook_quarantine';

            if (!$this->db->table_exists($table)) {
                return 0;
            }

            return (int) $this->db->where('released_lead_id', 0)->count_all_results($table);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Turn a quarantined record into a real lead — only with a contact identifier.
     *
     * THE GATE, AND WHY IT IS HERE RATHER THAN IN THE VIEW
     * ---------------------------------------------------
     * A quarantined record exists precisely because nobody can contact the
     * person. Releasing it without adding a way to do so would recreate the
     * problem it was built to prevent, one click further along. The check lives
     * in the model so it holds for every caller, not only the screen that
     * happens to ask nicely.
     *
     * The identifier is supplied by the reviewer, validated the same way an
     * incoming one is, and written onto the new lead. The quarantine row stays,
     * pointing at the lead it produced: the audit trail of "this arrived
     * uncontactable and a person fixed it" is worth keeping.
     */
    public function releaseQuarantine($quarantineId, $email, $phone, $staffId, array $routing)
    {
        $table = db_prefix() . 'leadgen_facebook_quarantine';
        $leads = db_prefix() . 'leads';

        $email = trim((string) $email);
        $phone = trim((string) $phone);

        if (!Facebook_identity::isContactable($email, $phone)) {
            return array('ok' => false, 'error' => 'no_contact_identifier');
        }

        try {
            $row = $this->db->where('id', (int) $quarantineId)->get($table)->row_array();

            if (empty($row)) {
                return array('ok' => false, 'error' => 'not_found');
            }

            if ((int) $row['released_lead_id'] > 0) {
                return array('ok' => false, 'error' => 'already_released',
                             'lead_id' => (int) $row['released_lead_id']);
            }

            $insert = array(
                'name'         => $row['display_name'] !== '' ? $row['display_name'] : ('Facebook lead ' . $row['fb_ref']),
                'email'        => $email,
                'phonenumber'  => $phone,
                'source'       => (int) $routing['source_id'],
                'status'       => (int) $routing['status_id'],
                'assigned'     => 0,
                'dateadded'    => date('Y-m-d H:i:s'),
                'addedfrom'    => (int) $staffId,
                'is_public'    => 0,
                'from_form_id' => 0,
            );

            if (function_exists('app_generate_hash')) {
                $insert['hash'] = app_generate_hash();
            }

            $this->db->insert($leads, $insert);
            $leadId = (int) $this->db->insert_id();

            if ($leadId < 1) {
                return array('ok' => false, 'error' => 'insert_failed');
            }

            $this->db->where('id', (int) $quarantineId)->update($table, array(
                'released_lead_id' => $leadId,
                'reviewed_by'      => (int) $staffId,
                'reviewed_epoch'   => time(),
            ));

            $this->db->where('channel', $row['channel'])->where('fb_ref', $row['fb_ref'])
                     ->update(db_prefix() . 'leadgen_facebook_messages', array('lead_id' => $leadId));

            $this->applyLeadReferences($leadId, array(
                'source_name' => isset($routing['source_name']) ? $routing['source_name'] : '',
                'page_name'   => (string) get_option('facebook_page_name'),
                'page_id'     => $row['page_id'],
                'form_id'     => $row['form_id'],
                'campaign_id' => $row['campaign_id'],
                'leadgen_id'  => $row['fb_ref'],
                'extra_tags'  => array(Facebook_identity::quarantineTag()),
            ));

            log_activity('leadgen_facebook: quarantined delivery ' . $row['fb_ref']
                . ' released to lead ' . $leadId . ' by staff ' . (int) $staffId
                . ' after a contact identifier was added.');

            return array('ok' => true, 'lead_id' => $leadId);
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => 'exception');
        }
    }

    /* ================================================================== */
    /* Possible duplicates: marked, reviewable, never auto-merged          */
    /* ================================================================== */

    /**
     * Record that this new lead resembles one we already have.
     *
     * Returns the markers created. An empty array means no resemblance, or a
     * resemblance the shared-number rule discounted.
     */
    public function markPossibleDuplicates($leadId, $fbRef, array $ctx)
    {
        $out = array();

        if ((string) get_option('facebook_duplicate_detection') !== '1') {
            return $out;
        }

        $leads = db_prefix() . 'leads';
        $table = db_prefix() . 'leadgen_facebook_duplicates';

        try {
            if (!$this->db->table_exists($table)) {
                return $out;
            }

            $salt = $this->ipSalt();
            $email = Facebook_identity::normaliseEmail($ctx['email']);
            $phone = Facebook_identity::normalisePhone($ctx['phone']);

            if ($email !== '') {
                $rows = $this->db->select('id')->where('email', trim((string) $ctx['email']))
                                 ->where('id !=', (int) $leadId)
                                 ->order_by('id', 'asc')->limit(5)->get($leads)->result_array();

                foreach ($rows as $r) {
                    $marker = $this->recordDuplicate($leadId, (int) $r['id'],
                        Facebook_identity::MATCH_EMAIL,
                        Facebook_identity::identityKey($salt, Facebook_identity::MATCH_EMAIL, $email),
                        $fbRef);

                    if ($marker !== null) {
                        $out[] = $marker;
                    }
                }
            }

            if ($phone !== '') {
                /*
                 * Matched on the normalised tail, so +91 98765 43210 and
                 * 09876543210 are one number. LIKE on the last ten digits is an
                 * index-unfriendly scan, which is acceptable at this table's
                 * size and is the only way to compare numbers stored in whatever
                 * shape the form sent them.
                 */
                $rows = $this->db->select('id, phonenumber')
                                 ->like('phonenumber', $phone, 'before')
                                 ->where('id !=', (int) $leadId)
                                 ->order_by('id', 'asc')->limit(25)->get($leads)->result_array();

                $confirmed = array();

                foreach ($rows as $r) {
                    if (Facebook_identity::normalisePhone($r['phonenumber']) === $phone) {
                        $confirmed[] = (int) $r['id'];
                    }
                }

                $shared = Facebook_identity::isSharedNumber(
                    $phone,
                    Facebook_identity::parseSharedNumbers(get_option('facebook_shared_numbers')),
                    count($confirmed),
                    get_option('facebook_shared_number_threshold')
                );

                if ($shared) {
                    /*
                     * A switchboard number is evidence of a company, not of a
                     * duplicate. Recorded in the activity log so the decision is
                     * visible, and NOT marked: a queue full of false markers is
                     * a queue nobody reads.
                     */
                    log_activity('leadgen_facebook: lead ' . (int) $leadId
                        . ' shares a phone number with ' . count($confirmed)
                        . ' existing lead(s); treated as a shared/switchboard number, not a duplicate.');
                } else {
                    foreach ($confirmed as $otherId) {
                        $marker = $this->recordDuplicate($leadId, $otherId,
                            Facebook_identity::MATCH_PHONE,
                            Facebook_identity::identityKey($salt, Facebook_identity::MATCH_PHONE, $phone),
                            $fbRef);

                        if ($marker !== null) {
                            $out[] = $marker;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            /* A failed marker must never cost the lead. */
            log_activity('leadgen_facebook: duplicate check failed for lead ' . (int) $leadId . '.');
        }

        return $out;
    }

    /**
     * One marker row. Returns null when the pair is already recorded.
     *
     * `INSERT IGNORE` against the unique pair index rather than a read-then-write:
     * the read-then-write races with itself on a retry and produces the duplicate
     * rows this table exists to avoid.
     */
    private function recordDuplicate($leadId, $otherLeadId, $matchType, $matchKey, $fbRef)
    {
        $table = db_prefix() . 'leadgen_facebook_duplicates';

        if ((int) $otherLeadId < 1 || (int) $otherLeadId === (int) $leadId) {
            return null;
        }

        $otherRef = '';

        try {
            $row = $this->db->select('fb_ref')
                            ->where('lead_id', (int) $otherLeadId)
                            ->limit(1)->get(db_prefix() . 'leadgen_facebook_messages')->row_array();
            $otherRef = empty($row) ? '' : (string) $row['fb_ref'];
        } catch (Throwable $e) {
            $otherRef = '';
        }

        $this->db->query(
            'INSERT IGNORE INTO `' . $table . '`
                (`lead_id`, `other_lead_id`, `match_type`, `match_key`, `fb_ref`, `other_fb_ref`,
                 `status`, `created_epoch`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            array((int) $leadId, (int) $otherLeadId, (string) $matchType, (string) $matchKey,
                  (string) $fbRef, $otherRef, 'open', time())
        );

        if ($this->db->affected_rows() < 1) {
            return null;
        }

        return array('other_lead_id' => (int) $otherLeadId, 'match_type' => (string) $matchType);
    }

    public function duplicateQueue($limit = 200, $status = 'open')
    {
        try {
            $table = db_prefix() . 'leadgen_facebook_duplicates';

            if (!$this->db->table_exists($table)) {
                return array();
            }

            if ($status !== '') {
                $this->db->where('status', $status);
            }

            return $this->db->order_by('id', 'desc')->limit((int) $limit)->get($table)->result_array();
        } catch (Throwable $e) {
            return array();
        }
    }

    public function duplicateOpenCount()
    {
        try {
            $table = db_prefix() . 'leadgen_facebook_duplicates';

            if (!$this->db->table_exists($table)) {
                return 0;
            }

            return (int) $this->db->where('status', 'open')->count_all_results($table);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Record a human decision on a possible duplicate.
     *
     * WHAT THIS DOES AND DOES NOT DO
     * ------------------------------
     * It records a judgement. It does NOT merge, move, delete or alter either
     * lead — deciding that two leads are the same person is a judgement, and
     * combining their history, notes, activities and owner is surgery. Doing the
     * surgery automatically on the strength of a matching phone number is the
     * behaviour that was removed for losing leads.
     *
     * So: an authorised person, a reason in their own words, a timestamp, and an
     * audit line. The operator then merges the records in the CRM's own UI, with
     * this row standing as the authorisation for having done it.
     */
    public function decideDuplicate($id, $status, $reason, $staffId)
    {
        $table = db_prefix() . 'leadgen_facebook_duplicates';
        $allowed = array('confirmed', 'not_duplicate');

        if (!in_array((string) $status, $allowed, true)) {
            return array('ok' => false, 'error' => 'bad_status');
        }

        $reason = trim((string) $reason);

        if ($reason === '') {
            return array('ok' => false, 'error' => 'reason_required');
        }

        if ((int) $staffId < 1) {
            return array('ok' => false, 'error' => 'no_staff');
        }

        try {
            $row = $this->db->where('id', (int) $id)->get($table)->row_array();

            if (empty($row)) {
                return array('ok' => false, 'error' => 'not_found');
            }

            $this->db->where('id', (int) $id)->update($table, array(
                'status'         => (string) $status,
                'decided_by'     => (int) $staffId,
                'decided_reason' => mb_substr($reason, 0, 255),
                'decided_epoch'  => time(),
            ));

            log_activity('leadgen_facebook: possible duplicate ' . (int) $id
                . ' (leads ' . (int) $row['lead_id'] . ' and ' . (int) $row['other_lead_id']
                . ', matched on ' . $row['match_type'] . ') marked ' . $status
                . ' by staff ' . (int) $staffId . ' — ' . mb_substr($reason, 0, 160));

            return array('ok' => true);
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => 'exception');
        }
    }

    /* ================================================================== */
    /* Metadata failures: recorded, retryable, never silent                */
    /* ================================================================== */

    /**
     * Record that a lead was saved but some of its labels were not.
     *
     * Swallows its own failure, like the delivery log and for the same reason:
     * this runs after the lead is committed, and an exception here must not
     * turn a successful delivery into a 500 that Meta will retry. If it does
     * fail, the activity-log line below is still written, so the failure is
     * never completely invisible.
     *
     * What is stored is labels — field slugs and tag names — never the lead's
     * contact details, which are on the lead record where they belong.
     */
    public function queueEnrichment($leadId, array $ctx, array $failures)
    {
        $leadId = (int) $leadId;

        if ($leadId < 1 || empty($failures)) {
            return false;
        }

        $summary = implode(', ', array_map(array('Facebook_tagging', 'cleanValue'), $failures));

        log_activity('leadgen_facebook: lead ' . $leadId
            . ' saved but its metadata is incomplete — ' . $summary
            . ' (queued for retry).');

        try {
            $table = db_prefix() . 'leadgen_facebook_enrichment';

            if (!$this->db->table_exists($table)) {
                return false;
            }

            $this->db->insert($table, array(
                'lead_id'       => $leadId,
                'request_id'    => isset($ctx['request_id']) ? (string) $ctx['request_id'] : '',
                'channel'       => isset($ctx['channel']) ? (string) $ctx['channel'] : '',
                'fb_ref'        => isset($ctx['fb_ref']) ? (string) $ctx['fb_ref'] : '',
                'failures'      => mb_substr($summary, 0, 2000),
                'last_error'    => '',
                'attempts'      => 0,
                'resolved'      => 0,
                'created_epoch' => time(),
            ));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** How many leads are currently missing a label. Drives the admin alert. */
    public function enrichmentPending()
    {
        try {
            $table = db_prefix() . 'leadgen_facebook_enrichment';

            if (!$this->db->table_exists($table)) {
                return 0;
            }

            return (int) $this->db->where('resolved', 0)->count_all_results($table);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * The queue, newest first, for the admin screen.
     *
     * `exhausted` marks an item that has used its retries — it stays in the
     * list rather than disappearing, because an item that quietly stopped being
     * retried and also stopped being shown is the silent failure this whole
     * table exists to prevent.
     */
    public function enrichmentQueue($limit = 100)
    {
        try {
            $table = db_prefix() . 'leadgen_facebook_enrichment';

            if (!$this->db->table_exists($table)) {
                return array();
            }

            $max = (int) get_option('facebook_enrichment_max_attempts');
            $max = $max > 0 ? $max : 5;

            $rows = $this->db->order_by('resolved', 'asc')
                             ->order_by('id', 'desc')
                             ->limit((int) $limit)
                             ->get($table)->result_array();

            foreach ($rows as $i => $row) {
                $rows[$i]['exhausted'] = ((int) $row['resolved'] === 0
                    && (int) $row['attempts'] >= $max);
            }

            return $rows;
        } catch (Throwable $e) {
            return array();
        }
    }

    /**
     * Retry pending items.
     *
     * Re-runs the same write path against the same lead. It is safe to run
     * repeatedly: tag linking checks for the existing link, and a custom-field
     * value is updated in place rather than appended, so a partially successful
     * item does not accumulate duplicates on its second attempt.
     *
     * The context is rebuilt from the claim row rather than trusted from the
     * queue, so a retry cannot write a reference the original delivery never
     * carried.
     */
    public function retryEnrichment($limit = 25)
    {
        $result = array('attempted' => 0, 'resolved' => 0, 'still_failing' => 0, 'exhausted' => 0);
        $table = db_prefix() . 'leadgen_facebook_enrichment';
        $messages = db_prefix() . 'leadgen_facebook_messages';

        try {
            if (!$this->db->table_exists($table)) {
                return $result;
            }

            $max = (int) get_option('facebook_enrichment_max_attempts');
            $max = $max > 0 ? $max : 5;

            $rows = $this->db->where('resolved', 0)
                             ->where('attempts <', $max)
                             ->order_by('id', 'asc')
                             ->limit((int) $limit)
                             ->get($table)->result_array();
        } catch (Throwable $e) {
            return $result;
        }

        foreach ($rows as $row) {
            $result['attempted']++;

            $ctx = array('source_name' => '', 'page_name' => '', 'page_id' => '',
                         'form_id' => '', 'campaign_id' => '',
                         'leadgen_id' => (string) $row['fb_ref']);

            try {
                $claim = $this->db->where('channel', $row['channel'])
                                  ->where('fb_ref', $row['fb_ref'])
                                  ->get($messages)->row_array();

                if (!empty($claim)) {
                    $ctx['page_id'] = (string) $claim['page_id'];
                    $ctx['form_id'] = (string) $claim['form_id'];
                    $ctx['campaign_id'] = (string) $claim['campaign_id'];
                }
            } catch (Throwable $e) {
                /* The claim row is a convenience here, not a requirement: the
                   tags and the Facebook lead id can still be written without
                   it. Losing it costs three references, not the retry. */
            }

            $sourceName = trim((string) get_option('facebook_lead_source_name'));
            $ctx['source_name'] = $sourceName === '' ? 'Facebook Lead Ads' : $sourceName;
            $ctx['page_name'] = (string) get_option('facebook_page_name');

            $applied = $this->applyLeadReferences((int) $row['lead_id'], $ctx);

            $attempts = (int) $row['attempts'] + 1;
            $update = array('attempts' => $attempts, 'last_attempt_epoch' => time());

            if (empty($applied['failures'])) {
                $update['resolved'] = 1;
                $update['resolved_epoch'] = time();
                $update['last_error'] = '';
                $result['resolved']++;
            } else {
                $update['last_error'] = mb_substr(implode(', ', $applied['failures']), 0, 255);
                $result['still_failing']++;

                if ($attempts >= $max) {
                    $result['exhausted']++;
                }
            }

            try {
                $this->db->where('id', (int) $row['id'])->update($table, $update);
            } catch (Throwable $e) {
                /* Nothing to do but leave the item pending; a retry that cannot
                   record its own outcome is retried again rather than lost. */
            }
        }

        if ($result['attempted'] > 0) {
            log_activity('leadgen_facebook: enrichment retry — ' . $result['attempted']
                . ' attempted, ' . $result['resolved'] . ' resolved, '
                . $result['still_failing'] . ' still failing.');
        }

        return $result;
    }

    /* ================================================================== */
    /* Reports and monitoring                                             */
    /* ================================================================== */

    /**
     * Everything the reports screen needs, in one pass over a bounded window.
     *
     * Windowed on `received_epoch`, a UTC integer, for the same reason the rate
     * limiter is: this install's CRM timezone and database session timezone are
     * 12h30m apart, so a window expressed in local datetimes would be wrong by
     * half a day and would say so quietly.
     */
    public function deliveryReport($sinceEpoch, $recentLimit = 40)
    {
        $table = db_prefix() . 'leadgen_facebook_deliveries';
        $empty = array('totals' => array(), 'recent_outcomes' => array(),
                       'last_accepted' => null, 'window_rows' => 0, 'retry_pending' => 0);

        try {
            if (!$this->db->table_exists($table)) {
                return $empty;
            }

            $totals = $this->db->query(
                "SELECT outcome, accepted, retryable, COUNT(*) AS n,
                        MIN(received_at) AS first_seen, MAX(received_at) AS last_seen
                 FROM `{$table}` WHERE received_epoch >= ?
                 GROUP BY outcome, accepted, retryable ORDER BY n DESC",
                array((int) $sinceEpoch)
            )->result_array();

            /*
             * Most recent first, and only the outcome. The streak checks in
             * Facebook_health are a claim about *now*, so the order is the
             * information — counting matches anywhere in the window would
             * report a fault that was fixed yesterday.
             */
            $recent = $this->db->query(
                "SELECT outcome FROM `{$table}` ORDER BY id DESC LIMIT " . (int) $recentLimit
            )->result_array();

            $recentOutcomes = array();

            foreach ($recent as $r) {
                $recentOutcomes[] = (string) $r['outcome'];
            }

            $lastAccepted = $this->db->query(
                "SELECT MAX(received_epoch) AS e FROM `{$table}` WHERE accepted = 1"
            )->row();

            $windowRows = 0;

            foreach ($totals as $t) {
                $windowRows += (int) $t['n'];
            }

            return array(
                'totals'          => $totals,
                'recent_outcomes' => $recentOutcomes,
                'last_accepted'   => $lastAccepted && $lastAccepted->e ? (int) $lastAccepted->e : null,
                'window_rows'     => $windowRows,
                'retry_pending'   => Facebook_health::retryPending($totals),
            );
        } catch (Throwable $e) {
            /* A report that throws must not take down the admin page. */
            return $empty;
        }
    }

    /**
     * Deliveries that failed and were told to retry, most recent first.
     *
     * This is the retry/error monitor's detail view: which specific requests
     * Meta is expected to send again, so a human can tell "one blip" from "the
     * same delivery has been failing for an hour".
     */
    public function retryableDeliveries($limit = 25)
    {
        $table = db_prefix() . 'leadgen_facebook_deliveries';

        try {
            if (!$this->db->table_exists($table)) {
                return array();
            }

            return $this->db->query(
                "SELECT request_id, received_at, outcome, http_status, failure_reason,
                        leadgen_id, page_id, form_id
                 FROM `{$table}` WHERE retryable = 1 ORDER BY id DESC LIMIT " . (int) $limit
            )->result_array();
        } catch (Throwable $e) {
            return array();
        }
    }

    /* ================================================================== */
    /* Credential change history — fingerprints only, never values         */
    /* ================================================================== */

    /**
     * Record that a credential was set, changed or cleared.
     *
     * Takes the old and new VALUES because it has to compute their fingerprints
     * and lengths — and stores neither. The two fingerprint columns are
     * VARCHAR(8), so the schema itself cannot hold a credential even if some
     * future caller passed one in the wrong argument.
     *
     * Called only from the settings save path, and only for names the
     * allowlist marks secret.
     */
    public function recordCredentialEvent($optionName, $oldValue, $newValue, $staffId)
    {
        $table = db_prefix() . 'leadgen_facebook_credential_events';

        try {
            if (!$this->db->table_exists($table)) {
                return false;
            }

            $before = Facebook_settings::describe($oldValue);
            $after = Facebook_settings::describe($newValue);

            if ($before['set'] === false && $after['set'] === false) {
                return false;   /* nothing happened */
            }

            if ($before['fp'] === $after['fp'] && $before['set'] === $after['set']) {
                return false;   /* saved again, unchanged */
            }

            if ($after['set'] === false) {
                $action = 'cleared';
            } elseif ($before['set'] === false) {
                $action = 'set';
            } else {
                $action = 'changed';
            }

            $this->db->insert($table, array(
                'option_name'   => (string) $optionName,
                'action'        => $action,
                'fp_before'     => $before['fp'],
                'fp_after'      => $after['fp'],
                'len_before'    => (int) $before['length'],
                'len_after'     => (int) $after['length'],
                'changed_by'    => (int) $staffId,
                'changed_at'    => date('Y-m-d H:i:s'),
                'changed_epoch' => time(),
            ));

            log_activity('leadgen_facebook: credential ' . $optionName . ' ' . $action
                . ' (fp ' . ($before['fp'] === '' ? 'none' : $before['fp'])
                . ' -> ' . ($after['fp'] === '' ? 'none' : $after['fp']) . ').');

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function credentialEvents($limit = 30)
    {
        $table = db_prefix() . 'leadgen_facebook_credential_events';

        try {
            if (!$this->db->table_exists($table)) {
                return array();
            }

            return $this->db->order_by('id', 'desc')->limit((int) $limit)
                            ->get($table)->result_array();
        } catch (Throwable $e) {
            return array();
        }
    }

    public function markAlerted($requestId)
    {
        try {
            $this->db->where('request_id', (string) $requestId)
                     ->update(db_prefix() . 'leadgen_facebook_messages',
                              array('alerted_at' => date('Y-m-d H:i:s')));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
