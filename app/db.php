<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO {
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    $db = new PDO('sqlite:' . cfg('db_path'));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

function migrate(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        start_ts INTEGER NOT NULL,
        end_ts INTEGER NOT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        category TEXT NOT NULL,
        space TEXT NOT NULL CHECK(space IN ('WHOLE','HALF_A','HALF_B')),
        created_ts INTEGER NOT NULL,
        created_ip TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'CONFIRMED'
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS pending_bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        start_ts INTEGER,
        end_ts INTEGER,
        name TEXT,
        email TEXT,
        category TEXT,
        space TEXT NOT NULL CHECK(space IN ('WHOLE','HALF_A','HALF_B')),
        code_hash TEXT NOT NULL,
        code_expires_ts INTEGER NOT NULL,
        created_ts INTEGER NOT NULL,
        created_ip TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS recurring_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        category TEXT NOT NULL,
        space TEXT NOT NULL CHECK(space IN ('WHOLE','HALF_A','HALF_B')),
        dow INTEGER NOT NULL,
        start_min INTEGER NOT NULL,
        end_min INTEGER NOT NULL,
        start_date INTEGER NOT NULL,
        end_date INTEGER NOT NULL,
        created_ts INTEGER NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS recurring_exceptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rule_id INTEGER NOT NULL,
        date_ts INTEGER NOT NULL,
        created_ts INTEGER NOT NULL,
        UNIQUE(rule_id, date_ts)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER NOT NULL,
        action TEXT NOT NULL,
        actor TEXT NOT NULL,
        ip TEXT NOT NULL,
        details TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        key TEXT PRIMARY KEY,
        window_start INTEGER NOT NULL,
        count INTEGER NOT NULL
    )");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_start_end ON bookings(start_ts, end_ts)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_space ON bookings(space)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_recurring_range ON recurring_rules(start_date, end_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pending_email ON pending_bookings(email)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_log(ts)");
}

