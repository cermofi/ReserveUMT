<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/mailer.php';

function log_audit(PDO $db, string $action, string $actor, string $ip, array $details): void {
    $stmt = $db->prepare('INSERT INTO audit_log(ts, action, actor, ip, details) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([time(), $action, $actor, $ip, json_encode($details, JSON_UNESCAPED_UNICODE)]);
}

function dt_from_date_time(string $date, string $time): ?DateTimeImmutable {
    $tz = new DateTimeZone('Europe/Prague');
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $tz);
    if (!$dt) {
        return null;
    }
    return $dt;
}

function midnight_ts(int $ts): int {
    $tz = new DateTimeZone('Europe/Prague');
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    $dt = $dt->setTime(0, 0, 0);
    return $dt->getTimestamp();
}

function conflict_spaces(string $space): array {
    if ($space === 'WHOLE') {
        return ['WHOLE', 'HALF_A', 'HALF_B'];
    }
    if ($space === 'HALF_A') {
        return ['WHOLE', 'HALF_A'];
    }
    if ($space === 'HALF_B') {
        return ['WHOLE', 'HALF_B'];
    }
    return ['WHOLE', 'HALF_A', 'HALF_B'];
}

function has_conflict(PDO $db, int $start_ts, int $end_ts, string $space, ?int $exclude_booking_id = null): bool {
    $spaces = conflict_spaces($space);
    $placeholders = implode(',', array_fill(0, count($spaces), '?'));
    $params = array_merge([$end_ts, $start_ts], $spaces);
    $sql = "SELECT id FROM bookings WHERE start_ts < ? AND end_ts > ? AND space IN ({$placeholders})";
    if ($exclude_booking_id !== null) {
        $sql .= ' AND id != ?';
        $params[] = $exclude_booking_id;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        return true;
    }

    $from_midnight = midnight_ts($start_ts);
    $to_midnight = midnight_ts($end_ts);
    $ruleSql = "SELECT * FROM recurring_rules WHERE end_date >= ? AND start_date <= ? AND space IN ({$placeholders})";
    $ruleParams = array_merge([$from_midnight, $to_midnight], $spaces);
    $rstmt = $db->prepare($ruleSql);
    $rstmt->execute($ruleParams);
    $rules = $rstmt->fetchAll();
    if (!$rules) {
        return false;
    }

    $exStmt = $db->prepare('SELECT date_ts FROM recurring_exceptions WHERE rule_id = ?');
    foreach ($rules as $rule) {
        $ruleStart = (int) $rule['start_date'];
        $ruleEnd = (int) $rule['end_date'];
        $checkStart = max($ruleStart, $from_midnight);
        $checkEnd = min($ruleEnd, $to_midnight);
        $exStmt->execute([(int) $rule['id']]);
        $exceptions = array_column($exStmt->fetchAll(), 'date_ts');
        $exceptions = array_flip($exceptions);
        for ($day = $checkStart; $day <= $checkEnd; $day += 86400) {
            if (isset($exceptions[$day])) {
                continue;
            }
            $dow = (int) (new DateTimeImmutable('@' . $day))->setTimezone(new DateTimeZone('Europe/Prague'))->format('N');
            if ($dow !== (int) $rule['dow']) {
                continue;
            }
            $occStart = $day + ((int) $rule['start_min']) * 60;
            $occEnd = $day + ((int) $rule['end_min']) * 60;
            if ($start_ts < $occEnd && $end_ts > $occStart) {
                return true;
            }
        }
    }
    return false;
}

function list_bookings(PDO $db, int $from_ts, int $to_ts): array {
    $stmt = $db->prepare('SELECT id, start_ts, end_ts, name, category, space FROM bookings WHERE start_ts < ? AND end_ts > ? ORDER BY start_ts');
    $stmt->execute([$to_ts, $from_ts]);
    return $stmt->fetchAll();
}

function list_recurring_occurrences(PDO $db, int $from_ts, int $to_ts): array {
    $from_midnight = midnight_ts($from_ts);
    $to_midnight = midnight_ts($to_ts);
    $stmt = $db->prepare('SELECT * FROM recurring_rules WHERE end_date >= ? AND start_date <= ?');
    $stmt->execute([$from_midnight, $to_midnight]);
    $rules = $stmt->fetchAll();
    if (!$rules) {
        return [];
    }
    $exStmt = $db->prepare('SELECT date_ts FROM recurring_exceptions WHERE rule_id = ?');
    $out = [];
    foreach ($rules as $rule) {
        $ruleStart = (int) $rule['start_date'];
        $ruleEnd = (int) $rule['end_date'];
        $checkStart = max($ruleStart, $from_midnight);
        $checkEnd = min($ruleEnd, $to_midnight);
        $exStmt->execute([(int) $rule['id']]);
        $exceptions = array_column($exStmt->fetchAll(), 'date_ts');
        $exceptions = array_flip($exceptions);
        for ($day = $checkStart; $day <= $checkEnd; $day += 86400) {
            if (isset($exceptions[$day])) {
                continue;
            }
            $dow = (int) (new DateTimeImmutable('@' . $day))->setTimezone(new DateTimeZone('Europe/Prague'))->format('N');
            if ($dow !== (int) $rule['dow']) {
                continue;
            }
            $occStart = $day + ((int) $rule['start_min']) * 60;
            $occEnd = $day + ((int) $rule['end_min']) * 60;
            if ($occStart >= $to_ts || $occEnd <= $from_ts) {
                continue;
            }
            $out[] = [
                'id' => 'R' . $rule['id'] . '-' . $day,
                'rule_id' => (int) $rule['id'],
                'start_ts' => $occStart,
                'end_ts' => $occEnd,
                'name' => $rule['title'],
                'category' => $rule['category'],
                'space' => $rule['space'],
                'is_recurring' => true,
                'date_ts' => $day,
            ];
        }
    }
    return $out;
}

function create_pending_booking(PDO $db, array $data, string $ip): array {
    $date = trim((string) ($data['date'] ?? ''));
    $start = trim((string) ($data['start'] ?? ''));
    $end = trim((string) ($data['end'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $category = trim((string) ($data['category'] ?? ''));
    $space = trim((string) ($data['space'] ?? ''));

    if (!validate_date($date) || !validate_time($start) || !validate_time($end)) {
        return ['ok' => false, 'error' => 'Neplatn? datum nebo ?as.'];
    }
    if ($name === '' || mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'Neplatn? jm?no.'];
    }
    if (!validate_email_addr($email)) {
        return ['ok' => false, 'error' => 'Neplatn? e-mail.'];
    }
    if (!in_array($category, CATEGORIES, true)) {
        return ['ok' => false, 'error' => 'Neplatn? kategorie.'];
    }
    if (!in_array($space, SPACES, true)) {
        return ['ok' => false, 'error' => 'Neplatn? volba prostoru.'];
    }

    $dtStart = dt_from_date_time($date, $start);
    $dtEnd = dt_from_date_time($date, $end);
    if (!$dtStart || !$dtEnd) {
        return ['ok' => false, 'error' => 'Neplatn? datum nebo ?as.'];
    }
    $start_ts = $dtStart->getTimestamp();
    $end_ts = $dtEnd->getTimestamp();
    if ($end_ts <= $start_ts) {
        return ['ok' => false, 'error' => 'Konec mus? b?t po za??tku.'];
    }

    if (!rate_limit($db, 'req_ip:' . $ip, 5, 3600)) {
        return ['ok' => false, 'error' => 'P??li? mnoho ??dost? z t?to IP.'];
    }
    if (!rate_limit($db, 'req_email:' . $email, 5, 3600)) {
        return ['ok' => false, 'error' => 'P??li? mnoho ??dost? pro tento e-mail.'];
    }

    if (has_conflict($db, $start_ts, $end_ts, $space)) {
        return ['ok' => false, 'error' => 'Term?n je obsazen?.'];
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $expires = time() + 600;

    $stmt = $db->prepare('INSERT INTO pending_bookings(start_ts, end_ts, name, email, category, space, code_hash, code_expires_ts, created_ts, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$start_ts, $end_ts, $name, $email, $category, $space, $codeHash, $expires, time(), $ip]);
    $pendingId = (int) $db->lastInsertId();

    if (!send_verification_email($email, $code)) {
        $db->prepare('DELETE FROM pending_bookings WHERE id = ?')->execute([$pendingId]);
        return ['ok' => false, 'error' => 'Nepoda?ilo se odeslat e-mail.'];
    }

    log_audit($db, 'pending_created', 'public', $ip, [
        'pending_id' => $pendingId,
        'start_ts' => $start_ts,
        'end_ts' => $end_ts,
        'space' => $space,
    ]);

    return ['ok' => true, 'pending_id' => $pendingId, 'expires_ts' => $expires];
}

function verify_pending_booking(PDO $db, int $pendingId, string $code, string $ip): array {
    try {
        $db->exec('BEGIN IMMEDIATE');
        $stmt = $db->prepare('SELECT * FROM pending_bookings WHERE id = ?');
        $stmt->execute([$pendingId]);
        $pending = $stmt->fetch();
        if (!$pending) {
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => '??dost nenalezena.'];
        }
        if ((int) $pending['code_expires_ts'] < time()) {
            $db->prepare('DELETE FROM pending_bookings WHERE id = ?')->execute([$pendingId]);
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'K?d vypr?el.'];
        }
        if ((int) $pending['attempts'] >= 5) {
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'P??li? mnoho pokus?.'];
        }
        if (!password_verify($code, $pending['code_hash'])) {
            $db->prepare('UPDATE pending_bookings SET attempts = attempts + 1 WHERE id = ?')->execute([$pendingId]);
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'Neplatn? k?d.'];
        }

        $start_ts = (int) $pending['start_ts'];
        $end_ts = (int) $pending['end_ts'];
        $space = (string) $pending['space'];
        if (has_conflict($db, $start_ts, $end_ts, $space)) {
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'Term?n je obsazen?.'];
        }

        $ins = $db->prepare('INSERT INTO bookings(start_ts, end_ts, name, email, category, space, created_ts, created_ip, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$start_ts, $end_ts, $pending['name'], $pending['email'], $pending['category'], $space, time(), $ip, 'CONFIRMED']);
        $bookingId = (int) $db->lastInsertId();
        $db->prepare('DELETE FROM pending_bookings WHERE id = ?')->execute([$pendingId]);
        $db->exec('COMMIT');

        log_audit($db, 'booking_confirmed', 'public', $ip, [
            'booking_id' => $bookingId,
            'start_ts' => $start_ts,
            'end_ts' => $end_ts,
            'space' => $space,
        ]);
        return ['ok' => true, 'booking_id' => $bookingId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        return ['ok' => false, 'error' => 'Chyba potvrzen?.'];
    }
}

function admin_create_booking(PDO $db, array $data, string $ip): array {
    $date = trim((string) ($data['date'] ?? ''));
    $start = trim((string) ($data['start'] ?? ''));
    $end = trim((string) ($data['end'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $category = trim((string) ($data['category'] ?? ''));
    $space = trim((string) ($data['space'] ?? ''));

    if (!validate_date($date) || !validate_time($start) || !validate_time($end)) {
        return ['ok' => false, 'error' => 'Neplatn? datum nebo ?as.'];
    }
    if ($name === '' || mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'Neplatn? jm?no.'];
    }
    if (!validate_email_addr($email)) {
        return ['ok' => false, 'error' => 'Neplatn? e-mail.'];
    }
    if (!in_array($category, CATEGORIES, true)) {
        return ['ok' => false, 'error' => 'Neplatn? kategorie.'];
    }
    if (!in_array($space, SPACES, true)) {
        return ['ok' => false, 'error' => 'Neplatn? volba prostoru.'];
    }

    $dtStart = dt_from_date_time($date, $start);
    $dtEnd = dt_from_date_time($date, $end);
    if (!$dtStart || !$dtEnd) {
        return ['ok' => false, 'error' => 'Neplatn? datum nebo ?as.'];
    }
    $start_ts = $dtStart->getTimestamp();
    $end_ts = $dtEnd->getTimestamp();
    if ($end_ts <= $start_ts) {
        return ['ok' => false, 'error' => 'Konec mus? b?t po za??tku.'];
    }

    try {
        $db->exec('BEGIN IMMEDIATE');
        if (has_conflict($db, $start_ts, $end_ts, $space)) {
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'Term?n je obsazen?.'];
        }
        $ins = $db->prepare('INSERT INTO bookings(start_ts, end_ts, name, email, category, space, created_ts, created_ip, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$start_ts, $end_ts, $name, $email, $category, $space, time(), $ip, 'CONFIRMED']);
        $bookingId = (int) $db->lastInsertId();
        $db->exec('COMMIT');
        log_audit($db, 'admin_booking_created', 'admin', $ip, ['booking_id' => $bookingId]);
        return ['ok' => true, 'booking_id' => $bookingId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        return ['ok' => false, 'error' => 'Chyba ulo?en?.'];
    }
}

function admin_delete_booking(PDO $db, int $id, string $ip): array {
    $stmt = $db->prepare('DELETE FROM bookings WHERE id = ?');
    $stmt->execute([$id]);
    log_audit($db, 'admin_booking_deleted', 'admin', $ip, ['booking_id' => $id]);
    return ['ok' => true];
}

function admin_create_recurring(PDO $db, array $data, string $ip): array {
    $title = trim((string) ($data['title'] ?? ''));
    $category = trim((string) ($data['category'] ?? ''));
    $space = trim((string) ($data['space'] ?? ''));
    $dow = (int) ($data['dow'] ?? 0);
    $start = trim((string) ($data['start'] ?? ''));
    $end = trim((string) ($data['end'] ?? ''));
    $startDate = trim((string) ($data['start_date'] ?? ''));
    $endDate = trim((string) ($data['end_date'] ?? ''));

    if ($title === '' || mb_strlen($title) > 80) {
        return ['ok' => false, 'error' => 'Neplatn? n?zev.'];
    }
    if (!in_array($category, CATEGORIES, true)) {
        return ['ok' => false, 'error' => 'Neplatn? kategorie.'];
    }
    if (!in_array($space, SPACES, true)) {
        return ['ok' => false, 'error' => 'Neplatn? volba prostoru.'];
    }
    if ($dow < 1 || $dow > 7) {
        return ['ok' => false, 'error' => 'Neplatn? den v t?dnu.'];
    }
    if (!validate_time($start) || !validate_time($end)) {
        return ['ok' => false, 'error' => 'Neplatn? ?as.'];
    }
    if (!validate_date($startDate) || !validate_date($endDate)) {
        return ['ok' => false, 'error' => 'Neplatn? datum.'];
    }
    $startParts = explode(':', $start);
    $endParts = explode(':', $end);
    $startMin = ((int) $startParts[0]) * 60 + (int) $startParts[1];
    $endMin = ((int) $endParts[0]) * 60 + (int) $endParts[1];
    if ($endMin <= $startMin) {
        return ['ok' => false, 'error' => 'Konec mus? b?t po za??tku.'];
    }
    $tz = new DateTimeZone('Europe/Prague');
    $sd = DateTimeImmutable::createFromFormat('Y-m-d', $startDate, $tz);
    $ed = DateTimeImmutable::createFromFormat('Y-m-d', $endDate, $tz);
    if (!$sd || !$ed) {
        return ['ok' => false, 'error' => 'Neplatn? datum.'];
    }
    $startDateTs = $sd->setTime(0, 0)->getTimestamp();
    $endDateTs = $ed->setTime(0, 0)->getTimestamp();
    if ($endDateTs < $startDateTs) {
        return ['ok' => false, 'error' => 'Konec obdob? mus? b?t po za??tku.'];
    }

    for ($day = $startDateTs; $day <= $endDateTs; $day += 86400) {
        $dayDow = (int) (new DateTimeImmutable('@' . $day))->setTimezone($tz)->format('N');
        if ($dayDow !== $dow) {
            continue;
        }
        $occStart = $day + $startMin * 60;
        $occEnd = $day + $endMin * 60;
        if (has_conflict($db, $occStart, $occEnd, $space)) {
            return ['ok' => false, 'error' => 'Opakov?n? koliduje s existuj?c? rezervac?.'];
        }
    }

    $stmt = $db->prepare('INSERT INTO recurring_rules(title, category, space, dow, start_min, end_min, start_date, end_date, created_ts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $category, $space, $dow, $startMin, $endMin, $startDateTs, $endDateTs, time()]);
    $ruleId = (int) $db->lastInsertId();
    log_audit($db, 'admin_recurring_created', 'admin', $ip, ['rule_id' => $ruleId]);
    return ['ok' => true, 'rule_id' => $ruleId];
}

function admin_delete_recurring(PDO $db, int $ruleId, string $ip): array {
    $db->prepare('DELETE FROM recurring_rules WHERE id = ?')->execute([$ruleId]);
    $db->prepare('DELETE FROM recurring_exceptions WHERE rule_id = ?')->execute([$ruleId]);
    log_audit($db, 'admin_recurring_deleted', 'admin', $ip, ['rule_id' => $ruleId]);
    return ['ok' => true];
}

function admin_delete_occurrence(PDO $db, int $ruleId, int $dateTs, string $ip): array {
    $stmt = $db->prepare('INSERT OR IGNORE INTO recurring_exceptions(rule_id, date_ts, created_ts) VALUES (?, ?, ?)');
    $stmt->execute([$ruleId, $dateTs, time()]);
    log_audit($db, 'admin_occurrence_deleted', 'admin', $ip, ['rule_id' => $ruleId, 'date_ts' => $dateTs]);
    return ['ok' => true];
}
