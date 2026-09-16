<?php
/**
 * Rollback plan generator.
 *
 * IT PRINTS SQL. IT DOES NOT RUN IT, AND IT CANNOT.
 * -------------------------------------------------
 * There is no database connection in this file and no code path that could
 * open one. Dropping the tables that hold the record of who approved and sent
 * every contract is a decision a person takes, having read what it costs —
 * not something a script does because it was invoked.
 *
 * It reads each migration's own `down` steps and `down_warning`, so the plan
 * cannot drift from the migration it reverses: there is one definition of what
 * 202 creates and one definition of what undoing it destroys, in the same file.
 *
 * ORDER
 * -----
 * Reverse of application: 203, then 202, then 201. The dependencies run the
 * other way — 202 needs 201's table to exist, 203 needs 202's — so undoing them
 * in creation order would leave each preflight's stated dependency missing
 * while the later tables still stood.
 *
 * WHAT ROLLBACK DOES NOT TOUCH
 * ----------------------------
 *   - Any Perfex core table. This module never wrote to one.
 *   - Any Lead Finder table, file or retention job.
 *   - The evidence FILES. They live outside the document root and outside the
 *     database, so they survive — as unidentifiable bytes, because the index
 *     saying which file is which and what its hash was is in the tables being
 *     dropped. Export first; see the warnings the plan prints.
 *
 * USAGE
 *   php migrations/rollback.php                 # the plan, with warnings
 *   php migrations/rollback.php --prefix=tbl    # substituted for a real host
 *   php migrations/rollback.php --sql-only      # just the statements
 */
defined('BASEPATH') and exit('Not an application file');
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$opt     = getopt('', array('prefix::', 'sql-only'));
$prefix  = isset($opt['prefix']) ? $opt['prefix'] : '{P}';
$sqlOnly = array_key_exists('sql-only', $opt);

if (!preg_match('/^[A-Za-z0-9_{}$-]*$/', $prefix)) {
    fwrite(STDERR, "REFUSED: --prefix is not a valid table prefix\n");
    exit(2);
}

define('PAYPLEX_CV_PREFLIGHT', 1);

/*
 * Reverse order of application, stated as a literal rather than derived by
 * sorting — a sort is one typo away from running them forwards.
 *
 * EXTENDED, AND WHY IT HAD TO BE.
 * This list said ('203','202','201') while twelve migrations existed on disk.
 * The script did not fail on the other nine; it silently rolled back three and
 * reported success, which is the worst possible behaviour for a rollback tool —
 * it looks like it worked. The guard below now refuses when the list and the
 * directory disagree, so the next migration cannot be added without this line
 * being updated.
 */
$order = array('212', '211', '210', '209', '208', '207', '206',
               '205', '204', '203', '202', '201');

/*
 * The list must account for every migration on disk. A rollback that quietly
 * skips a migration leaves the schema in a state no migration describes.
 */
$onDisk = array();

foreach (glob(__DIR__ . '/[0-9]*_*.php') as $f) {
    if (preg_match('/^(\d+)_/', basename($f), $mm)) { $onDisk[] = $mm[1]; }
}

$unlisted = array_diff($onDisk, $order);
$phantom  = array_diff($order, $onDisk);

if (count($unlisted) > 0 || count($phantom) > 0) {
    fwrite(STDERR, "REFUSED: the rollback order does not match the migrations on disk.\n");

    if (count($unlisted) > 0) {
        fwrite(STDERR, "  on disk but not in the order: " . implode(', ', $unlisted) . "\n");
    }

    if (count($phantom) > 0) {
        fwrite(STDERR, "  in the order but not on disk: " . implode(', ', $phantom) . "\n");
    }

    exit(2);
}
$plans = array();

foreach ($order as $id) {
    $files = glob(__DIR__ . '/' . $id . '_*.php');

    if (count($files) !== 1) {
        fwrite(STDERR, "REFUSED: no single migration file matches $id\n");
        exit(2);
    }

    $m = require $files[0];

    if (empty($m['down'])) {
        fwrite(STDERR, "REFUSED: migration $id declares no down steps. A migration with no "
                     . "rollback is not something this script will paper over.\n");
        exit(3);
    }

    $plans[$id] = $m;
}

if ($sqlOnly) {
    foreach ($plans as $m) {
        foreach ($m['down'] as $sql) {
            echo str_replace('{P}', $prefix, $sql) . ";\n";
        }
    }

    exit(0);
}

$out   = array();
$out[] = 'Contract Verification — rollback plan';
$out[] = 'Generated: ' . date('c');
$out[] = str_repeat('=', 72);
$out[] = '';
$out[] = 'This script has printed SQL. It has not run any of it and cannot.';
$out[] = '';
$out[] = 'BEFORE RUNNING ANY OF IT:';
$out[] = '  1. Take a full database backup and record its size and timestamp.';
$out[] = '  2. Export all eight payplex_cv_* tables separately and keep the export';
$out[] = '     WITH the evidence files. For any executed contract those tables are';
$out[] = '     the execution evidence.';
$out[] = '  3. Confirm you are on staging. Check the database name, not the URL.';
$out[] = '';
$out[] = 'REMOVING THE MODULE FILES ALONE IS THE SAFER ROLLBACK.';
$out[] = '  Deleting modules/payplex_contract_verification/ stops every screen, hook';
$out[] = '  and route this module adds. The CRM returns to exactly its previous';
$out[] = '  behaviour, because nothing here modifies a core file or a core table.';
$out[] = '  The tables stay, holding their data, costing nothing. Drop them only if';
$out[] = '  the module is being removed permanently.';
$out[] = '';

foreach ($plans as $id => $m) {
    $out[] = str_repeat('-', 72);
    $out[] = 'MIGRATION ' . $id . ' — ' . $m['title'];
    $out[] = '';
    $out[] = 'What undoing it costs:';

    foreach (explode('. ', (string) $m['down_warning']) as $sentence) {
        $sentence = trim($sentence);

        if ($sentence === '') { continue; }

        $out[] = '  ' . rtrim($sentence, '.') . '.';
    }

    $out[] = '';
    $out[] = 'Statements:';

    foreach ($m['down'] as $sql) {
        $out[] = '  ' . str_replace('{P}', $prefix, $sql) . ';';
    }

    $out[] = '';
}

$out[] = str_repeat('=', 72);
$out[] = 'AFTER ROLLBACK, CHECK:';
$out[] = '  - /admin/contracts and an individual contract still load.';
$out[] = '  - The Lead Finder screens still load and its retention job still runs.';
$out[] = '  - No tblpayplex_cv_* table remains:';
$out[] = '      SELECT TABLE_NAME FROM information_schema.TABLES';
$out[] = "       WHERE TABLE_SCHEMA = '<database>' AND TABLE_NAME LIKE '"
       . str_replace('{P}', $prefix, '{P}') . "payplex\\_cv\\_%';";
$out[] = '  - The evidence directory is untouched, and you have the export from step 2.';
$out[] = '';

echo implode("\n", $out) . "\n";
