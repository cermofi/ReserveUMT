<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/bookings.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

send_security_headers();
send_no_cache_headers();
init_app_error_logging('json');
$db = db();
migrate($db);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

debug_log('api_request', [
    'action' => $action,
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
]);

if ($action === 'list') {
    $from = (int) ($_GET['from'] ?? 0);
    $to = (int) ($_GET['to'] ?? 0);
    if ($from <= 0 || $to <= 0 || $to <= $from) {
        fail_json('Invalid range', 400);
    }
    if ($to - $from > 60 * 60 * 24 * 31) {
        fail_json('Range too large', 400);
    }
    $bookings = list_bookings($db, $from, $to);
    $recurring = list_recurring_occurrences($db, $from, $to);
    respond_json(['ok' => true, 'bookings' => $bookings, 'recurring' => $recurring]);
}

if ($action === 'settings') {
    $requireVerify = get_setting($db, 'require_email_verification', '1');
    $maxAdvance = max_advance_days($db);
    $maxEmail = max_reservations_per_email($db);
    $maxDuration = max_reservation_duration_hours($db);
    respond_json([
        'ok' => true,
        'require_email_verification' => $requireVerify,
        'max_advance_booking_days' => $maxAdvance,
        'max_reservations_per_email' => $maxEmail,
        'max_reservation_duration_hours' => $maxDuration,
    ]);
}

require_post();
require_csrf();

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($action === 'request_booking') {
    $result = create_pending_booking($db, $_POST, $ip);
    if (!$result['ok']) {
        fail_json($result['error']);
    }
    if (isset($result['booking_id'])) {
        respond_json(['ok' => true, 'booking_id' => $result['booking_id']]);
    }
    respond_json(['ok' => true, 'pending_id' => $result['pending_id'], 'expires_ts' => $result['expires_ts']]);
}

if ($action === 'verify_code') {
    $pendingId = (int) ($_POST['pending_id'] ?? 0);
    $code = trim((string) ($_POST['code'] ?? ''));
    if ($pendingId <= 0 || $code === '') {
        fail_json('Neplatný kód.');
    }
    if (!rate_limit($db, 'verify_ip:' . $ip, 10, 3600)) {
        fail_json('Příliš mnoho pokusů.');
    }
    $result = verify_pending_booking($db, $pendingId, $code, $ip);
    if (!$result['ok']) {
        fail_json($result['error']);
    }
    respond_json(['ok' => true, 'booking_id' => $result['booking_id']]);
}

fail_json('Unknown action', 400);
