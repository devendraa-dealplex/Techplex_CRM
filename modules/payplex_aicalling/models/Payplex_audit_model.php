<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Tamper-evident, append-only audit log with a SHA-256 hash chain.
 * Each row's row_hash = sha256(prev_hash + canonical(row-core)).
 * Any silent edit/delete breaks the chain and is detectable via verifyChain().
 */
class Payplex_audit_model extends App_Model
{
    /** Where the newest row's hash is kept, outside the audit table. */
    const HEAD_OPTION = 'payplex_aicalling_audit_head';

    private $table;

    public function __construct()
    {
        parent::__construct();
        $this->table = db_prefix() . 'payplex_audit';
    }

    /**
     * Append an audit entry. Never updates or deletes.
     */
    public function log($action, $objectType = null, $objectId = null, $before = null, $after = null, $correlationId = null)
    {
        /*
         * Read the head and write the row as one atomic step. Two concurrent
         * appends that both read the same head produce two rows claiming the
         * same predecessor — a fork indistinguishable from tampering. FOR
         * UPDATE inside a transaction makes the second writer wait;
         * trans_start() nests safely if a caller already opened one.
         *
         * The row_hash IS NOT NULL filter matters on an install that has rows
         * from before chaining: the newest row may legitimately have no hash,
         * and taking it as the predecessor would chain from null.
         */
        $this->db->trans_start();

        $prev = $this->db->query(
            'SELECT `row_hash` FROM `' . $this->table . '` WHERE `row_hash` IS NOT NULL'
            . ' ORDER BY `id` DESC LIMIT 1 FOR UPDATE'
        )->row();

        $prevHash = ($prev && $prev->row_hash !== null && $prev->row_hash !== '')
            ? (string) $prev->row_hash
            : str_repeat('0', 64);

        $core = $this->core([
            'actor_staff_id' => function_exists('get_staff_user_id') ? get_staff_user_id() : null,
            'actor_role'     => $this->currentRole(),
            'action'         => $action,
            'object_type'    => $objectType,
            'object_id'      => $objectId !== null ? (string) $objectId : null,
            'before_json'    => $before !== null ? json_encode($before) : null,
            'after_json'     => $after !== null ? json_encode($after) : null,
            'correlation_id' => $correlationId,
            'ip'             => $this->input->ip_address(),
            'user_agent'     => substr((string) $this->input->user_agent(), 0, 255),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $canonical = json_encode($core, JSON_UNESCAPED_SLASHES);
        $rowHash = hash('sha256', $prevHash . $canonical);

        $core['prev_hash'] = $prevHash;
        $core['row_hash']  = $rowHash;
        $this->db->insert($this->table, $core);
        $id = $this->db->insert_id();
        $this->rememberHead($rowHash);

        $this->db->trans_complete();
        return $id;
    }

    /**
     * Keep the newest row's hash outside the audit table.
     *
     * Without it, deleting the most recent entries leaves a chain that still
     * verifies perfectly: the evidence of the deletion goes with the rows. A
     * head held elsewhere makes a truncated tail visible.
     */
    private function rememberHead($hash)
    {
        if (!function_exists('get_option')) { return; }

        // get_option() answers '' for an option that does not exist, so the
        // create/update choice is made on emptiness, not on null.
        $existing = get_option(self::HEAD_OPTION);
        if ($existing === null || $existing === false || trim((string) $existing) === '') {
            if (function_exists('add_option')) { add_option(self::HEAD_OPTION, $hash, 0); }
            return;
        }
        if (function_exists('update_option')) { update_option(self::HEAD_OPTION, $hash); }
    }

    /**
     * Recompute the chain and report what is wrong with it.
     *
     * Returns: ok, checked, unchained, broken_id, reason, tail_proof, head.
     *
     * Honest limit, stated rather than hidden: a chain proves no row was edited
     * and none removed from the middle, because the next row's prev_hash would
     * no longer match. Deleting from the END breaks nothing — the remaining
     * chain is still internally consistent — which is why the head is also kept
     * outside the table. tail_proof says whether that comparison was possible.
     */
    public function verifyChain()
    {
        $out = array('ok' => true, 'checked' => 0, 'unchained' => 0, 'legacy_format' => 0,
                     'broken_id' => 0, 'reason' => '', 'tail_proof' => false,
                     'head' => str_repeat('0', 64));

        $rows = $this->db->order_by('id', 'ASC')->get($this->table)->result();
        $prevHash = str_repeat('0', 64);
        $started = false;

        foreach ($rows as $r) {
            $rowHash = (string) $r->row_hash;

            if ($rowHash === '') {
                /*
                 * Written before chaining existed. It cannot be verified, and
                 * hashing it now would make an un-attested row look attested —
                 * worse than useless. Counted, reported and skipped.
                 */
                if ($started) {
                    $out['ok'] = false;
                    $out['broken_id'] = (int) $r->id;
                    $out['reason'] = 'Row #' . (int) $r->id . ' has no hash but follows chained rows — the chain was cut.';
                    return $out;
                }
                $out['unchained']++;
                continue;
            }

            $matched = $this->hashMatches($prevHash, $r, $rowHash);

            if (!hash_equals($prevHash, (string) $r->prev_hash)) {
                $out['ok'] = false;
                $out['broken_id'] = (int) $r->id;
                $out['reason'] = 'Row #' . (int) $r->id . ' does not follow the row before it — a row was removed or reordered.';
                return $out;
            }
            if ($matched === false) {
                $out['ok'] = false;
                $out['broken_id'] = (int) $r->id;
                $out['reason'] = 'Row #' . (int) $r->id . ' was altered after it was written.';
                return $out;
            }
            if ($matched === 'v1') { $out['legacy_format']++; }

            $started = true;
            $prevHash = $rowHash;
            $out['checked']++;
        }

        $out['head'] = $prevHash;

        $expectedHead = function_exists('get_option') ? get_option(self::HEAD_OPTION) : null;
        if ($expectedHead !== null && trim((string) $expectedHead) !== '') {
            $out['tail_proof'] = true;
            if (!hash_equals((string) $expectedHead, $prevHash)) {
                $out['ok'] = false;
                $out['reason'] = 'The newest audit entries are missing — the chain ends earlier than recorded.';
            }
        }

        return $out;
    }

    /**
     * The canonical hashed content of a row.
     *
     * One definition, used by the writer and the verifier alike. The writer
     * holds PHP values and the verifier reads them back from MySQL as strings,
     * so both sides normalise here; if the two ever disagreed about a single
     * field, every honest row would read as tampered.
     */
    /**
     * The canonical hashed content of a row, in one of two formats.
     *
     * 'v2' is the current rule: every value normalised, so the writer (holding
     * PHP values) and the verifier (reading strings back from MySQL) cannot
     * disagree.
     *
     * 'v1' is what the original writer did — values passed through untouched.
     * It exists because rows already in the log were hashed that way, and
     * changing the rule under them does not make them tampered. Adding the
     * normalisation without this variant condemned all 56 existing rows and
     * put "audit log integrity check FAILED" on screen over an intact log,
     * which is the most damaging possible false alarm: the next real one gets
     * dismissed.
     *
     * Both are accepted on read; only v2 is ever written, so the log converges
     * on one format without rewriting history. A row that was genuinely
     * altered matches neither.
     */
    private function core($r, $variant = 'v2')
    {
        $a = (array) $r;
        $get = function ($k) use ($a) { return array_key_exists($k, $a) ? $a[$k] : null; };

        $fields = array('actor_staff_id', 'actor_role', 'action', 'object_type', 'object_id',
                        'before_json', 'after_json', 'correlation_id', 'ip', 'user_agent',
                        'created_at');

        if ($variant === 'v1') {
            $core = array();
            foreach ($fields as $f) { $core[$f] = $get($f); }
            return $core;
        }

        $str = function ($v) { return $v === null ? null : (string) $v; };
        return array(
            'actor_staff_id' => $get('actor_staff_id') === null ? null : (int) $get('actor_staff_id'),
            'actor_role'     => $str($get('actor_role')),
            'action'         => (string) $get('action'),
            'object_type'    => $str($get('object_type')),
            'object_id'      => $str($get('object_id')),
            'before_json'    => $str($get('before_json')),
            'after_json'     => $str($get('after_json')),
            'correlation_id' => $str($get('correlation_id')),
            'ip'             => $str($get('ip')),
            'user_agent'     => $str($get('user_agent')),
            'created_at'     => (string) $get('created_at'),
        );
    }

    /** Does $row hash to $stored under either accepted format? */
    private function hashMatches($prevHash, $row, $stored)
    {
        foreach (array('v2', 'v1') as $variant) {
            $h = hash('sha256', $prevHash . json_encode($this->core($row, $variant), JSON_UNESCAPED_SLASHES));
            if (hash_equals($h, (string) $stored)) { return $variant; }
        }
        return false;
    }

    private function currentRole()
    {
        /*
         * Perfex has no single "role" on a staff member: permissions come from
         * roles AND per-staff overrides, so there is no one field to read. What
         * an audit entry needs is not a job title but whether the actor was
         * acting with administrator rights, because that is what changes what
         * they were permitted to do. Recording only that is accurate; inventing
         * a richer role string from a field that does not exist would not be.
         */
        if (function_exists('is_admin') && is_admin()) {
            return 'admin';
        }
        return 'staff';
    }
}
