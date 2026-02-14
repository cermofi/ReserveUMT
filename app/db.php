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

function column_exists(PDO $db, string $table, string $column): bool {
    $stmt = $db->query("PRAGMA table_info($table)");
    $cols = $stmt->fetchAll();
    foreach ($cols as $col) {
        if (isset($col['name']) && $col['name'] === $column) {
            return true;
        }
    }
    return false;
}

function add_column_if_missing(PDO $db, string $table, string $column, string $definition): void {
    if (!column_exists($db, $table, $column)) {
        $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

function drop_category_from_bookings(PDO $db): void {
    if (!column_exists($db, 'bookings', 'category')) return;
    $db->exec("CREATE TABLE bookings_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        start_ts INTEGER NOT NULL,
        end_ts INTEGER NOT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        space TEXT NOT NULL CHECK(space IN ('WHOLE','HALF_A','HALF_B')),
        note TEXT NOT NULL DEFAULT '',
        created_ts INTEGER NOT NULL,
        created_ip TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'CONFIRMED',
        edit_token TEXT
    )");
    $db->exec("INSERT INTO bookings_new (id,start_ts,end_ts,name,email,space,note,created_ts,created_ip,status,edit_token)
               SELECT id,start_ts,end_ts,name,email,space,note,created_ts,created_ip,status,edit_token FROM bookings");
    $db->exec("DROP TABLE bookings");
    $db->exec("ALTER TABLE bookings_new RENAME TO bookings");
}

function drop_category_from_pending(PDO $db): void {
    if (!column_exists($db, 'pending_bookings', 'category')) return;
    $db->exec("CREATE TABLE pending_bookings_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        start_ts INTEGER,
        end_ts INTEGER,
        name TEXT,
        email TEXT,
        space TEXT NOT NULL CHECK(space IN ('WHOLE','HALF_A','HALF_B')),
        note TEXT NOT NULL DEFAULT '',
        code_hash TEXT NOT NULL,
        code_expires_ts INTEGER NOT NULL,
        created_ts INTEGER NOT NULL,
        created_ip TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("INSERT INTO pending_bookings_new (id,start_ts,end_ts,name,email,space,note,code_hash,code_expires_ts,created_ts,created_ip,attempts)
               SELECT id,start_ts,end_ts,name,email,space,note,code_hash,code_expires_ts,created_ts,created_ip,attempts FROM pending_bookings");
    $db->exec("DROP TABLE pending_bookings");
    $db->exec("ALTER TABLE pending_bookings_new RENAME TO pending_bookings");
}

function drop_category_from_recurring(PDO $db): void {
    if (!column_exists($db, 'recurring_rules', 'category')) return;
    $db->exec("CREATE TABLE recurring_rules_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        space TEXT NOT NULL CHECK(space IN ('WHOLE','HALF_A','HALF_B')),
        dow INTEGER NOT NULL,
        start_min INTEGER NOT NULL,
        end_min INTEGER NOT NULL,
        start_date INTEGER NOT NULL,
        end_date INTEGER NOT NULL,
        created_ts INTEGER NOT NULL
    )");
    $db->exec("INSERT INTO recurring_rules_new (id,title,space,dow,start_min,end_min,start_date,end_date,created_ts)
               SELECT id,title,space,dow,start_min,end_min,start_date,end_date,created_ts FROM recurring_rules");
    $db->exec("DROP TABLE recurring_rules");
    $db->exec("ALTER TABLE recurring_rules_new RENAME TO recurring_rules");
}

function migrate(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        start_ts INTEGER NOT NULL,
        end_ts INTEGER NOT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        space TEXT NOT NULL CHECK(space IN ('WHOLE','HALF_A','HALF_B')),
        note TEXT NOT NULL DEFAULT '',
        created_ts INTEGER NOT NULL,
        created_ip TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'CONFIRMED',
        edit_token TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS pending_bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        start_ts INTEGER,
        end_ts INTEGER,
        name TEXT,
        email TEXT,
        space TEXT NOT NULL CHECK(space IN ('WHOLE','HALF_A','HALF_B')),
        note TEXT NOT NULL DEFAULT '',
        code_hash TEXT NOT NULL,
        code_expires_ts INTEGER NOT NULL,
        created_ts INTEGER NOT NULL,
        created_ip TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS recurring_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
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

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        key TEXT PRIMARY KEY,
        window_start INTEGER NOT NULL,
        count INTEGER NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS email_tokens (
        email TEXT PRIMARY KEY,
        token TEXT NOT NULL,
        created_ts INTEGER NOT NULL
    )");

    add_column_if_missing($db, 'bookings', 'edit_token', 'TEXT');
    add_column_if_missing($db, 'bookings', 'note', "TEXT NOT NULL DEFAULT ''");
    add_column_if_missing($db, 'pending_bookings', 'note', "TEXT NOT NULL DEFAULT ''");
    drop_category_from_bookings($db);
    drop_category_from_pending($db);
    drop_category_from_recurring($db);

    $db->prepare("INSERT OR IGNORE INTO settings(key, value) VALUES ('require_email_verification', '1')")->execute();
    $db->prepare("INSERT OR IGNORE INTO settings(key, value) VALUES ('max_advance_booking_days', '30')")->execute();
    $db->prepare("INSERT OR IGNORE INTO settings(key, value) VALUES ('max_reservations_per_email', '0')")->execute();
    $db->prepare("INSERT OR IGNORE INTO settings(key, value) VALUES ('max_reservation_duration_hours', '2')")->execute();

    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_start_end ON bookings(start_ts, end_ts)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_space ON bookings(space)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_recurring_range ON recurring_rules(start_date, end_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pending_email ON pending_bookings(email)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_log(ts)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_edit_token ON bookings(edit_token)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_email_tokens_token ON email_tokens(token)");
}
