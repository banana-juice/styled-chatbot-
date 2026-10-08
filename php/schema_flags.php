<?php
// ============================================================
// RUN-ONCE SCHEMA CHECKS — php/schema_flags.php
//
// Several features create or upgrade their own tables on first use
// (stock_ensure_schema, reviews_ensure_schema, ...). Doing that work on EVERY
// request cost a dozen extra queries per page view, including DDL statements,
// which are slow and lock tables. schema_once() runs the migration once and
// remembers it in a tiny table, so a normal request pays for a single indexed
// SELECT instead.
//
// To ship a new schema change, give the migration a NEW name (e.g. "reviews_v3").
// Concurrent first requests may all run the migration at once; every statement
// in the migrations is written to tolerate that (IF NOT EXISTS / duplicate-column
// errors are ignored).
// ============================================================

function schema_once(PDO $pdo, string $name, callable $migrate): void {
    static $seen = [];
    if (isset($seen[$name])) {
        return;
    }
    try {
        $st = $pdo->prepare('SELECT 1 FROM schema_flags WHERE name = ?');
        $st->execute([$name]);
        if ($st->fetchColumn()) {
            $seen[$name] = true;
            return;
        }
    } catch (PDOException $e) {
        // First ever call: the flags table doesn't exist yet.
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_flags (
            name    VARCHAR(80) NOT NULL PRIMARY KEY,
            done_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    $migrate();
    $pdo->prepare('INSERT IGNORE INTO schema_flags (name) VALUES (?)')->execute([$name]);
    $seen[$name] = true;
}
