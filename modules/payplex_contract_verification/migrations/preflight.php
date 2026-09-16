<?php
/**
 * Read-only migration preflight runner.
 *
 * WHAT THIS IS FOR
 * ----------------
 * Migrations 201, 202 and 203 each create tables, and CREATE TABLE is not
 * idempotent in any useful sense here: `IF NOT EXISTS` does not fail on a
 * table that already exists, it SKIPS it — leaving a table of unknown shape in
 * place while the migration reports success. Each migration therefore carries a
 * preflight asserting that the names it is about to take are free, and this
 * runner executes those checks and nothing else.
 *
 * Adapted from the Lead Finder runner of the same name, including the
 * schema-name substitution described further down, which exists because of a
 * defect that would otherwise be repeated here verbatim.
 *
 * EVERY MIGRATION CARRIES A CHECK WHOSE REQUIRED ANSWER IS NON-ZERO
 * -----------------------------------------------------------------
 * Most preflight checks want the answer 0 — "this table name is free". A
 * predicate that matches nothing also returns 0, so a broken check and a clean
 * database look identical. Each migration therefore also carries at least one
 * check whose required answer is 1: 201 asserts `tblcontracts` is present, 202
 * asserts 201's table is, 203 asserts 202's. If a predicate stops matching,
 * that check fails and the gate closes, which is the right direction to fail
 * in.
 *
 * WHY IT IS A SEPARATE SCRIPT AND NOT PART OF THE MIGRATION
 * ---------------------------------------------------------
 * A preflight that runs as the first step of the thing it is gating is not a
 * gate. This is meant to be run, read by a person, and acted on — possibly by
 * deciding not to apply the migration at all.
 *
 * SAFETY
 * ------
 *   - Refuses to run anything that is not a SELECT. Every statement is checked
 *     before anything connects, so a bad second statement cannot be found
 *     after the first has already run.
 *   - Opens the connection READ ONLY where the driver supports it.
 *   - Writes its output to stdout and to a file. It writes nothing to the
 *     database, and there is no code path in this file that could.
 *
 * USAGE
 *   php migrations/preflight.php --migration=201 \
 *       --dsn="mysql:host=localhost;dbname=DB" --user=USER --prefix=tbl \
 *       --out=preflight-201.txt
 *
 * Run 201, then 202, then 203, applying each migration only after its own
 * preflight has said PROCEED. `--dry-run` prints the statements without
 * connecting to anything.
 *
 * The password is read from the PREFLIGHT_DB_PASSWORD environment variable,
 * not from an argument: arguments appear in `ps` output and in shell history.
 */
/* Two guards. BASEPATH keeps it out of the application's own include path, and
   the SAPI check keeps it off the web entirely — this file takes a database DSN
   and must never be reachable by a request. */
defined('BASEPATH') and exit('Not an application file');
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$opt = getopt('', array('dsn:', 'user:', 'prefix::', 'db::', 'out::', 'migration::', 'dry-run'));
if (empty($opt['dsn']) || empty($opt['user'])) {
    fwrite(STDERR, "usage: php preflight.php [--migration=NNN] --dsn=... --user=... [--db=NAME] [--prefix=tbl] [--out=FILE] [--dry-run]\n");
    fwrite(STDERR, "password: set PREFLIGHT_DB_PASSWORD in the environment\n");
    exit(2);
}
$prefix = isset($opt['prefix']) ? $opt['prefix'] : 'tbl';

/*
 * THE SCHEMA NAME IS SUBSTITUTED, NOT ASKED FOR AT RUNTIME.
 *
 * The preflight queries used to say `TABLE_SCHEMA = DATABASE()`, which reads
 * as obviously correct and is the form every example uses. On this server it
 * matches nothing: DATABASE() returns the right name and the comparison against
 * information_schema's own column still yields no rows, so every "is this
 * column already there" check returned 0 — the answer that means "clear to
 * apply" — whether or not the column existed.
 *
 * A gate that can only ever return the passing answer is worse than no gate,
 * because it is quoted in the change record as evidence. The controls in each
 * migration (the checks whose required answer is 1, not 0) would have caught it
 * by failing, which is the right direction to fail in — but the fix is to stop
 * depending on the function at all. The name comes from the DSN, is validated
 * as an identifier, and is substituted like the table prefix.
 */
$db = '';
if (isset($opt['db']) && $opt['db'] !== '') {
    $db = (string) $opt['db'];
} elseif (preg_match('/(?:dbname|database)=([A-Za-z0-9_$-]+)/i', (string) $opt['dsn'], $dm)) {
    $db = $dm[1];
}
if (!preg_match('/^[A-Za-z0-9_$-]+$/', $db)) {
    fwrite(STDERR, "REFUSED: could not determine a valid database name. Pass --db=NAME.\n");
    exit(2);
}
$outFile = isset($opt['out']) ? $opt['out'] : null;
$dryRun  = array_key_exists('dry-run', $opt);

/*
 * WHICH MIGRATION'S PREFLIGHT TO RUN
 * ----------------------------------
 * This started as a runner for 103 alone. Migrations 112-114 each carry their
 * own preflight — mostly information_schema checks that a column or table name
 * is still free, because ADD COLUMN and CREATE TABLE are not idempotent and a
 * chain that aborts halfway through an ALTER is a bad morning.
 *
 * The migration number is selected by argument, and the argument is matched
 * against the files that actually exist rather than concatenated into a path.
 * A runner that takes a filename from the command line and requires it is an
 * arbitrary-file-include with a helpful usage message.
 *
 * 103 stays the default so every existing instruction, runbook and note that
 * says `php migrations/preflight.php --dsn=...` keeps doing exactly what it
 * did.
 */
$want = isset($opt["migration"]) && $opt["migration"] !== "" ? $opt["migration"] : "201";
if (!preg_match('/^\d{3}$/', $want)) {
    fwrite(STDERR, "REFUSED: --migration must be a three-digit migration number\n");
    exit(2);
}
$candidates = glob(__DIR__ . '/' . $want . '_*.php');
if (count($candidates) !== 1) {
    fwrite(STDERR, "REFUSED: no single migration file matches $want\n");
    exit(2);
}

/* The migration files guard against direct web access. Declaring this constant
   is how a CLI reader says "I am reading the array, not applying it" — the
   runner still never touches the statements a migration would apply. */
define('PAYPLEX_CV_PREFLIGHT', 1);
$m = require $candidates[0];

if (empty($m['preflight'])) {
    fwrite(STDERR, "Migration $want declares no preflight. Nothing to check.\n");
    exit(2);
}
if (!isset($m['reports'])) { $m['reports'] = array(); }

/* Every statement is checked before anything connects. A runner that validates
   as it goes has already run the first one by the time it finds the second. */
$statements = array();
foreach ($m['preflight'] as $k => $q) { $statements['PREFLIGHT ' . $k] = $q['sql']; }
foreach ($m['reports'] as $k => $sql) { $statements['REPORT ' . $k]    = $sql; }

foreach ($statements as $label => $sql) {
    if (!preg_match('/^\s*SELECT\b/i', $sql)) {
        fwrite(STDERR, "REFUSED: $label is not a SELECT\n");
        exit(3);
    }
    if (preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|GRANT)\b/i', $sql)) {
        fwrite(STDERR, "REFUSED: $label contains a write keyword\n");
        exit(3);
    }
    if (substr_count($sql, ';') > 0) {
        fwrite(STDERR, "REFUSED: $label contains a statement separator\n");
        exit(3);
    }
}

$out = array();
$out[] = "Contract Verification — migration preflight";
$out[] = "Generated: " . date('c');
$out[] = "Migration: $want (" . $m['title'] . ")";
$out[] = str_repeat('=', 72);
$out[] = '';

if ($dryRun) {
    $out[] = "DRY RUN — no connection was opened. Statements that WOULD run:";
    foreach ($statements as $label => $sql) {
        $out[] = '';
        $out[] = "-- $label";
        $out[] = str_replace(array('{P}', '{DB}'), array($prefix, $db), $sql);
    }
    $text = implode("\n", $out) . "\n";
    echo $text;
    if ($outFile) { file_put_contents($outFile, $text); }
    exit(0);
}

$pw = getenv('PREFLIGHT_DB_PASSWORD');
if ($pw === false) {
    fwrite(STDERR, "PREFLIGHT_DB_PASSWORD is not set.\n");
    exit(2);
}

try {
    $pdo = new PDO($opt['dsn'], $opt['user'], $pw, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
    /* Belt and braces: ask the session itself to refuse writes. Not every
       server honours it, which is why the statement check above is the real
       control and this is the second line. */
    try { $pdo->exec('SET SESSION TRANSACTION READ ONLY'); } catch (Exception $e) { /* older servers */ }
} catch (Exception $e) {
    fwrite(STDERR, "connection failed: " . preg_replace('/password=\S+/i', 'password=***', $e->getMessage()) . "\n");
    exit(4);
}
unset($pw);

$verdict = 'PROCEED';
$notes   = array();

foreach ($m['preflight'] as $k => $q) {
    $sql = str_replace(array('{P}', '{DB}'), array($prefix, $db), $q['sql']);
    $out[] = "PREFLIGHT: $k";
    try {
        $row = $pdo->query($sql)->fetch();
        $val = $row ? (int) reset($row) : 0;
        $ok  = ($val === (int) $q['must_be']);
        $out[] = sprintf('  result: %d   required: %d   %s', $val, $q['must_be'], $ok ? 'OK' : 'BLOCKED');
        if (!$ok) { $verdict = 'DO NOT APPLY'; $notes[] = $k . ': ' . $q['if_not']; }
    } catch (Exception $e) {
        $out[] = '  ERROR: ' . $e->getMessage();
        $verdict = 'DO NOT APPLY';
        $notes[] = $k . ': preflight could not be evaluated, so it has not passed.';
    }
    $out[] = '';
}

foreach ($m['reports'] as $k => $sql) {
    $out[] = "REPORT: $k";
    try {
        $rows = $pdo->query(str_replace(array('{P}', '{DB}'), array($prefix, $db), $sql))->fetchAll();
        if (!$rows) { $out[] = '  (no rows)'; }
        else {
            $out[] = '  ' . implode(' | ', array_keys($rows[0]));
            foreach ($rows as $r) { $out[] = '  ' . implode(' | ', array_map('strval', $r)); }
            $out[] = '  ' . count($rows) . ' row(s)';
        }
    } catch (Exception $e) {
        $out[] = '  ERROR: ' . $e->getMessage();
    }
    $out[] = '';
}

$out[] = str_repeat('=', 72);
$out[] = "VERDICT: $verdict";
foreach ($notes as $n) { $out[] = '  - ' . $n; }
$out[] = '';
$out[] = 'This run wrote nothing. No contract, signing request or evidence record was read, altered or deleted.';

$text = implode("\n", $out) . "\n";
echo $text;
if ($outFile) { file_put_contents($outFile, $text); }
exit($verdict === 'PROCEED' ? 0 : 1);
