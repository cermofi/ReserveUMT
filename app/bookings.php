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

function get_setting(PDO $db, string $key, string $default): string {
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) {
        return $default;
    }
    return (string) $row['value'];
}

function max_advance_days(PDO $db): int {
    $days = (int) get_setting($db, 'max_advance_booking_days', '30');
    if ($days < 0) {
        return 0;
    }
    return $days;
}

function max_reservations_per_email(PDO $db): int {
    $val = (int) get_setting($db, 'max_reservations_per_email', '0');
    return $val < 0 ? 0 : $val;
}

function max_reservation_duration_hours(PDO $db): float {
    $val = (float) get_setting($db, 'max_reservation_duration_hours', '2');
    if ($val < 0) return 0.0;
    return $val;
}

function enforce_advance_limit(PDO $db, int $start_ts, int $end_ts): ?array {
    $limitDays = max_advance_days($db);
    if ($limitDays === 0) {
        return null; // 0 => no limit
    }
    $now = time();
    $maxTs = $now + ($limitDays * 86400);
    if ($start_ts > $maxTs || $end_ts > $maxTs) {
        return ['ok' => false, 'error' => "Rezervace lze vytvořit maximálně {$limitDays} dní dopředu."];
    }
    return null;
}

function enforce_duration_limit(PDO $db, int $start_ts, int $end_ts): ?array {
    $hours = max_reservation_duration_hours($db);
    if ($hours <= 0) return null;
    $durationHours = ($end_ts - $start_ts) / 3600;
    if ($durationHours > $hours + 1e-6) {
        return ['ok' => false, 'error' => "Maximální délka rezervace je {$hours} hodin."];
    }
    return null;
}

function enforce_email_limit(PDO $db, string $email): ?array {
    $limit = max_reservations_per_email($db);
    $requireEmail = get_setting($db, 'require_email_verification', '1') === '1';
    if (!$requireEmail) return null;
    if ($limit === 0) return null;
    if ($email === '') return null; // no email -> cannot count
    $now = time();
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE email = ? AND end_ts > ? AND (status IS NULL OR status != 'CANCELLED')");
    $stmt->execute([$email, $now]);
    $cnt = (int) ($stmt->fetch()['cnt'] ?? 0);
    if ($cnt >= $limit) {
        return ['ok' => false, 'error' => "Na tento e-mail je možné mít maximálně {$limit} rezervací."];
    }
    return null;
}

function set_setting(PDO $db, string $key, string $value): void {
    $stmt = $db->prepare('INSERT INTO settings(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
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
    $stmt = $db->prepare('SELECT id, start_ts, end_ts, name, space, note FROM bookings WHERE start_ts < ? AND end_ts > ? ORDER BY start_ts');
    $stmt->execute([$to_ts, $from_ts]);
    return $stmt->fetchAll();
}

function list_bookings_admin(PDO $db, int $from_ts, int $to_ts): array {
    $stmt = $db->prepare('SELECT id, start_ts, end_ts, name, email, space, note FROM bookings WHERE start_ts < ? AND end_ts > ? ORDER BY start_ts');
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
                'space' => $rule['space'],
                'is_recurring' => true,
                'date_ts' => $day,
            ];
        }
    }
    return $out;
}

function random_token(): string {
    return bin2hex(random_bytes(16));
}

function ensure_email_token(PDO $db, string $email): string {
    $stmt = $db->prepare('SELECT token FROM email_tokens WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row && !empty($row['token'])) {
        return (string) $row['token'];
    }
    $token = random_token();
    $ins = $db->prepare('INSERT INTO email_tokens(email, token, created_ts) VALUES (?, ?, ?)');
    $ins->execute([$email, $token, time()]);
    return $token;
}

function ensure_booking_token(PDO $db, int $bookingId): string {
    $stmt = $db->prepare('SELECT edit_token FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();
    if ($row && !empty($row['edit_token'])) {
        return (string) $row['edit_token'];
    }
    $token = random_token();
    $upd = $db->prepare('UPDATE bookings SET edit_token = ? WHERE id = ?');
    $upd->execute([$token, $bookingId]);
    return $token;
}

function format_booking_range(int $start_ts, int $end_ts): string {
    $tz = new DateTimeZone('Europe/Prague');
    $start = (new DateTimeImmutable('@' . $start_ts))->setTimezone($tz);
    $end = (new DateTimeImmutable('@' . $end_ts))->setTimezone($tz);
    if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
        return $start->format('d.m.Y H:i') . '–' . $end->format('H:i');
    }
    return $start->format('d.m.Y H:i') . ' – ' . $end->format('d.m.Y H:i');
}

function send_manage_links(PDO $db, int $bookingId, string $email, int $start_ts, int $end_ts, string $space, string $name): void {
    if ($email === '') {
        return;
    }
    $bookingToken = ensure_booking_token($db, $bookingId);
    $emailToken = ensure_email_token($db, $email);
    $bookingLink = app_url() . '/manage.php?booking=' . urlencode($bookingToken);
    $emailLink = app_url() . '/manage.php?email=' . urlencode($emailToken);
    $range = format_booking_range($start_ts, $end_ts);
    $spaceLabel = space_label($space);
    if (!send_manage_links_email($email, $range, $spaceLabel, $name, $bookingLink, $emailLink)) {
        debug_log('manage_link_email_failed', ['email' => $email, 'booking_id' => $bookingId]);
    }
}

function get_booking_by_token(PDO $db, string $token): ?array {
    $stmt = $db->prepare('SELECT * FROM bookings WHERE edit_token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_email_by_token(PDO $db, string $token): ?string {
    $stmt = $db->prepare('SELECT email FROM email_tokens WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row || empty($row['email'])) {
        return null;
    }
    return (string) $row['email'];
}

function list_bookings_for_email_token(PDO $db, string $token): array {
    $email = get_email_by_token($db, $token);
    if ($email === null) {
        return [];
    }
    $stmt = $db->prepare('SELECT id, start_ts, end_ts, name, space, edit_token FROM bookings WHERE email = ? ORDER BY start_ts DESC');
    $stmt->execute([$email]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if (empty($row['edit_token'])) {
            $row['edit_token'] = ensure_booking_token($db, (int) $row['id']);
        }
    }
    return $rows;
}

function create_pending_booking(PDO $db, array $data, string $ip): array {
    $date = trim((string) ($data['date'] ?? ''));
    $start = trim((string) ($data['start'] ?? ''));
    $end = trim((string) ($data['end'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $space = trim((string) ($data['space'] ?? ''));
    $note = trim((string) ($data['note'] ?? ''));

    if (!validate_date($date) || !validate_time($start) || !validate_time($end)) {
        return ['ok' => false, 'error' => 'Neplatné datum nebo čas.'];
    }
    if ($name !== '' && mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'Neplatné jméno.'];
    }
    $requireVerify = get_setting($db, 'require_email_verification', '1') === '1';
    if ($requireVerify) {
        if (!validate_email_addr($email)) {
            return ['ok' => false, 'error' => 'Neplatný e-mail.'];
        }
    } else {
        if ($email !== '' && !validate_email_addr($email)) {
            return ['ok' => false, 'error' => 'Neplatný e-mail.'];
        }
    }
    if (!in_array($space, SPACES, true)) {
        return ['ok' => false, 'error' => 'Neplatná volba prostoru.'];
    }
    if (mb_strlen($note) > 500) {
        return ['ok' => false, 'error' => 'Poznámka je příliš dlouhá (max 500 znaků).'];
    }

    $dtStart = dt_from_date_time($date, $start);
    $dtEnd = dt_from_date_time($date, $end);
    if (!$dtStart || !$dtEnd) {
        return ['ok' => false, 'error' => 'Neplatné datum nebo čas.'];
    }
    $start_ts = $dtStart->getTimestamp();
    $end_ts = $dtEnd->getTimestamp();
    if ($limitErr = enforce_advance_limit($db, $start_ts, $end_ts)) {
        return $limitErr;
    }
    if ($limitErr = enforce_advance_limit($db, $start_ts, $end_ts)) {
        return $limitErr;
    }
    if ($end_ts <= $start_ts) {
        return ['ok' => false, 'error' => 'Konec musí být po začátku.'];
    }
    if ($limitErr = enforce_duration_limit($db, $start_ts, $end_ts)) {
        return $limitErr;
    }
    if ($limitErr = enforce_duration_limit($db, $start_ts, $end_ts)) {
        return $limitErr;
    }
    if ($limitErr = enforce_email_limit($db, $email)) {
        return $limitErr;
    }

    if (!rate_limit($db, 'req_ip:' . $ip, 5, 3600)) {
        return ['ok' => false, 'error' => 'Příliš mnoho žádostí z této IP.'];
    }
    if ($email !== '' && !rate_limit($db, 'req_email:' . $email, 5, 3600)) {
        return ['ok' => false, 'error' => 'Příliš mnoho žádostí pro tento e-mail.'];
    }

    if (has_conflict($db, $start_ts, $end_ts, $space)) {
        return ['ok' => false, 'error' => 'Termín je obsazený.'];
    }

    if (!$requireVerify) {
        try {
            $db->exec('BEGIN IMMEDIATE');
            if (has_conflict($db, $start_ts, $end_ts, $space)) {
                $db->exec('COMMIT');
                return ['ok' => false, 'error' => 'Termín je obsazený.'];
            }
            $ins = $db->prepare('INSERT INTO bookings(start_ts, end_ts, name, email, space, note, created_ts, created_ip, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$start_ts, $end_ts, $name, $email, $space, $note, time(), $ip, 'CONFIRMED']);
            $bookingId = (int) $db->lastInsertId();
            $db->exec('COMMIT');
            log_audit($db, 'booking_created_no_verify', 'public', $ip, [
                'booking_id' => $bookingId,
                'start_ts' => $start_ts,
                'end_ts' => $end_ts,
                'space' => $space,
            ]);
            send_manage_links($db, $bookingId, $email, $start_ts, $end_ts, $space, $name);
            return ['ok' => true, 'booking_id' => $bookingId];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            return ['ok' => false, 'error' => 'Chyba uložení.'];
        }
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $expires = time() + 600;

    $stmt = $db->prepare('INSERT INTO pending_bookings(start_ts, end_ts, name, email, space, note, code_hash, code_expires_ts, created_ts, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$start_ts, $end_ts, $name, $email, $space, $note, $codeHash, $expires, time(), $ip]);
    $pendingId = (int) $db->lastInsertId();

    if (!send_verification_email($email, $code)) {
        $db->prepare('DELETE FROM pending_bookings WHERE id = ?')->execute([$pendingId]);
        return ['ok' => false, 'error' => 'Nepodařilo se odeslat e-mail.'];
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
            return ['ok' => false, 'error' => 'Žádost nenalezena.'];
        }
        if ((int) $pending['code_expires_ts'] < time()) {
            $db->prepare('DELETE FROM pending_bookings WHERE id = ?')->execute([$pendingId]);
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'Kód vypršel.'];
        }
        if ((int) $pending['attempts'] >= 5) {
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'Příliš mnoho pokusů.'];
        }
        if (!password_verify($code, $pending['code_hash'])) {
            $db->prepare('UPDATE pending_bookings SET attempts = attempts + 1 WHERE id = ?')->execute([$pendingId]);
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'Neplatný kód.'];
        }

        $start_ts = (int) $pending['start_ts'];
        $end_ts = (int) $pending['end_ts'];
        if ($limitErr = enforce_advance_limit($db, $start_ts, $end_ts)) {
            $db->exec('COMMIT');
            return $limitErr;
        }
        if ($limitErr = enforce_duration_limit($db, $start_ts, $end_ts)) {
            $db->exec('COMMIT');
            return $limitErr;
        }
        if ($limitErr = enforce_email_limit($db, (string) $pending['email'])) {
            $db->exec('COMMIT');
            return $limitErr;
        }
        $space = (string) $pending['space'];
        if (has_conflict($db, $start_ts, $end_ts, $space)) {
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'Termín je obsazený.'];
        }

        $ins = $db->prepare('INSERT INTO bookings(start_ts, end_ts, name, email, space, note, created_ts, created_ip, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$start_ts, $end_ts, $pending['name'], $pending['email'], $space, $pending['note'], time(), $ip, 'CONFIRMED']);
        $bookingId = (int) $db->lastInsertId();
        $db->prepare('DELETE FROM pending_bookings WHERE id = ?')->execute([$pendingId]);
        $db->exec('COMMIT');

        log_audit($db, 'booking_confirmed', 'public', $ip, [
            'booking_id' => $bookingId,
            'start_ts' => $start_ts,
            'end_ts' => $end_ts,
            'space' => $space,
        ]);
        send_manage_links($db, $bookingId, (string) $pending['email'], $start_ts, $end_ts, $space, (string) $pending['name']);
        return ['ok' => true, 'booking_id' => $bookingId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        return ['ok' => false, 'error' => 'Chyba potvrzení.'];
    }
}

function admin_create_booking(PDO $db, array $data, string $ip): array {
    $date = trim((string) ($data['date'] ?? ''));
    $start = trim((string) ($data['start'] ?? ''));
    $end = trim((string) ($data['end'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $space = trim((string) ($data['space'] ?? ''));
    $note = trim((string) ($data['note'] ?? ''));

    if (!validate_date($date) || !validate_time($start) || !validate_time($end)) {
        return ['ok' => false, 'error' => 'Neplatné datum nebo čas.'];
    }
    if ($name !== '' && mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'Neplatné jméno.'];
    }
    if ($email !== '' && !validate_email_addr($email)) {
        return ['ok' => false, 'error' => 'Neplatný e-mail.'];
    }
    if (!in_array($space, SPACES, true)) {
        return ['ok' => false, 'error' => 'Neplatná volba prostoru.'];
    }
    if (mb_strlen($note) > 500) {
        return ['ok' => false, 'error' => 'Poznámka je příliš dlouhá (max 500 znaků).'];
    }

    $dtStart = dt_from_date_time($date, $start);
    $dtEnd = dt_from_date_time($date, $end);
    if (!$dtStart || !$dtEnd) {
        return ['ok' => false, 'error' => 'Neplatné datum nebo čas.'];
    }
    $start_ts = $dtStart->getTimestamp();
    $end_ts = $dtEnd->getTimestamp();
    if ($end_ts <= $start_ts) {
        return ['ok' => false, 'error' => 'Konec musí být po začátku.'];
    }

    try {
        $db->exec('BEGIN IMMEDIATE');
        if (has_conflict($db, $start_ts, $end_ts, $space)) {
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'Termín je obsazený.'];
        }
        $ins = $db->prepare('INSERT INTO bookings(start_ts, end_ts, name, email, space, note, created_ts, created_ip, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$start_ts, $end_ts, $name, $email, $space, $note, time(), $ip, 'CONFIRMED']);
        $bookingId = (int) $db->lastInsertId();
        $db->exec('COMMIT');
        log_audit($db, 'admin_booking_created', 'admin', $ip, ['booking_id' => $bookingId]);
        send_manage_links($db, $bookingId, $email, $start_ts, $end_ts, $space, $name);
        return ['ok' => true, 'booking_id' => $bookingId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        return ['ok' => false, 'error' => 'Chyba uložení.'];
    }
}

function admin_delete_booking(PDO $db, int $id, string $ip): array {
    $stmt = $db->prepare('DELETE FROM bookings WHERE id = ?');
    $stmt->execute([$id]);
    log_audit($db, 'admin_booking_deleted', 'admin', $ip, ['booking_id' => $id]);
    return ['ok' => true];
}

function admin_update_booking(PDO $db, int $id, array $data, string $ip): array {
    $date = trim((string) ($data['date'] ?? ''));
    $start = trim((string) ($data['start'] ?? ''));
    $end = trim((string) ($data['end'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $space = trim((string) ($data['space'] ?? ''));
    $note = trim((string) ($data['note'] ?? ''));

    if (!validate_date($date) || !validate_time($start) || !validate_time($end)) {
        return ['ok' => false, 'error' => 'Neplatné datum nebo čas.'];
    }
    if ($name !== '' && mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'Neplatné jméno.'];
    }
    if ($email !== '' && !validate_email_addr($email)) {
        return ['ok' => false, 'error' => 'Neplatný e-mail.'];
    }
    if (!in_array($space, SPACES, true)) {
        return ['ok' => false, 'error' => 'Neplatná volba prostoru.'];
    }
    if (mb_strlen($note) > 500) {
        return ['ok' => false, 'error' => 'Poznámka je příliš dlouhá (max 500 znaků).'];
    }

    $dtStart = dt_from_date_time($date, $start);
    $dtEnd = dt_from_date_time($date, $end);
    if (!$dtStart || !$dtEnd) {
        return ['ok' => false, 'error' => 'Neplatné datum nebo čas.'];
    }
    $start_ts = $dtStart->getTimestamp();
    $end_ts = $dtEnd->getTimestamp();
    if ($end_ts <= $start_ts) {
        return ['ok' => false, 'error' => 'Konec musí být po začátku.'];
    }

    try {
        $db->exec('BEGIN IMMEDIATE');
        if (has_conflict($db, $start_ts, $end_ts, $space, $id)) {
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'Termín je obsazený.'];
        }
        $stmt = $db->prepare('UPDATE bookings SET start_ts = ?, end_ts = ?, name = ?, email = ?, space = ?, note = ? WHERE id = ?');
        $stmt->execute([$start_ts, $end_ts, $name, $email, $space, $note, $id]);
        $db->exec('COMMIT');
        log_audit($db, 'admin_booking_updated', 'admin', $ip, ['booking_id' => $id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        return ['ok' => false, 'error' => 'Chyba uložení.'];
    }
}

function admin_create_recurring(PDO $db, array $data, string $ip): array {
    $title = trim((string) ($data['title'] ?? ''));
    $space = trim((string) ($data['space'] ?? ''));
    $dow = (int) ($data['dow'] ?? 0);
    $start = trim((string) ($data['start'] ?? ''));
    $end = trim((string) ($data['end'] ?? ''));
    $startDate = trim((string) ($data['start_date'] ?? ''));
    $endDate = trim((string) ($data['end_date'] ?? ''));

    if ($title === '' || mb_strlen($title) > 80) {
        return ['ok' => false, 'error' => 'Neplatný název.'];
    }
    if (!in_array($space, SPACES, true)) {
        return ['ok' => false, 'error' => 'Neplatná volba prostoru.'];
    }
    if ($dow < 1 || $dow > 7) {
        return ['ok' => false, 'error' => 'Neplatný den v týdnu.'];
    }
    if (!validate_time($start) || !validate_time($end)) {
        return ['ok' => false, 'error' => 'Neplatný čas.'];
    }
    if (!validate_date($startDate) || !validate_date($endDate)) {
        return ['ok' => false, 'error' => 'Neplatné datum.'];
    }
    $startParts = explode(':', $start);
    $endParts = explode(':', $end);
    $startMin = ((int) $startParts[0]) * 60 + (int) $startParts[1];
    $endMin = ((int) $endParts[0]) * 60 + (int) $endParts[1];
    if ($endMin <= $startMin) {
        return ['ok' => false, 'error' => 'Konec musí být po začátku.'];
    }
    $tz = new DateTimeZone('Europe/Prague');
    $sd = DateTimeImmutable::createFromFormat('Y-m-d', $startDate, $tz);
    $ed = DateTimeImmutable::createFromFormat('Y-m-d', $endDate, $tz);
    if (!$sd || !$ed) {
        return ['ok' => false, 'error' => 'Neplatné datum.'];
    }
    $startDateTs = $sd->setTime(0, 0)->getTimestamp();
    $endDateTs = $ed->setTime(0, 0)->getTimestamp();
    if ($endDateTs < $startDateTs) {
        return ['ok' => false, 'error' => 'Konec období musí být po začátku.'];
    }

    for ($day = $startDateTs; $day <= $endDateTs; $day += 86400) {
        $dayDow = (int) (new DateTimeImmutable('@' . $day))->setTimezone($tz)->format('N');
        if ($dayDow !== $dow) {
            continue;
        }
        $occStart = $day + $startMin * 60;
        $occEnd = $day + $endMin * 60;
        if (has_conflict($db, $occStart, $occEnd, $space)) {
            return ['ok' => false, 'error' => 'Opakování koliduje s existující rezervací.'];
        }
    }

    $stmt = $db->prepare('INSERT INTO recurring_rules(title, space, dow, start_min, end_min, start_date, end_date, created_ts) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $space, $dow, $startMin, $endMin, $startDateTs, $endDateTs, time()]);
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

function public_update_booking(PDO $db, string $token, array $data, string $ip): array {
    $booking = get_booking_by_token($db, $token);
    if (!$booking) {
        return ['ok' => false, 'error' => 'Rezervace nenalezena.'];
    }
    $email = (string) ($booking['email'] ?? '');

    $date = trim((string) ($data['date'] ?? ''));
    $start = trim((string) ($data['start'] ?? ''));
    $end = trim((string) ($data['end'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $space = trim((string) ($data['space'] ?? ''));

    if (!validate_date($date) || !validate_time($start) || !validate_time($end)) {
        return ['ok' => false, 'error' => 'Neplatné datum nebo čas.'];
    }
    if ($name === '' || mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'Neplatné jméno.'];
    }
    if (!in_array($space, SPACES, true)) {
        return ['ok' => false, 'error' => 'Neplatná volba prostoru.'];
    }

    $dtStart = dt_from_date_time($date, $start);
    $dtEnd = dt_from_date_time($date, $end);
    if (!$dtStart || !$dtEnd) {
        return ['ok' => false, 'error' => 'Neplatné datum nebo čas.'];
    }
    $start_ts = $dtStart->getTimestamp();
    $end_ts = $dtEnd->getTimestamp();
    if ($end_ts <= $start_ts) {
        return ['ok' => false, 'error' => 'Konec musí být po začátku.'];
    }
    if ($limitErr = enforce_duration_limit($db, $start_ts, $end_ts)) {
        return $limitErr;
    }
    if ($limitErr = enforce_email_limit($db, $email)) {
        return $limitErr;
    }
    if ($limitErr = enforce_duration_limit($db, $start_ts, $end_ts)) {
        return $limitErr;
    }
    if ($limitErr = enforce_email_limit($db, $email)) {
        return $limitErr;
    }
    if ($limitErr = enforce_advance_limit($db, $start_ts, $end_ts)) {
        return $limitErr;
    }
    if ($limitErr = enforce_advance_limit($db, $start_ts, $end_ts)) {
        return $limitErr;
    }

    $id = (int) $booking['id'];
    try {
        $db->exec('BEGIN IMMEDIATE');
        if (has_conflict($db, $start_ts, $end_ts, $space, $id)) {
            $db->exec('COMMIT');
            return ['ok' => false, 'error' => 'Termín je obsazený.'];
        }
        $stmt = $db->prepare('UPDATE bookings SET start_ts = ?, end_ts = ?, name = ?, space = ? WHERE id = ?');
        $stmt->execute([$start_ts, $end_ts, $name, $space, $id]);
        $db->exec('COMMIT');
        log_audit($db, 'public_booking_updated', 'public', $ip, ['booking_id' => $id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        return ['ok' => false, 'error' => 'Chyba uložení.'];
    }
}

function public_delete_booking(PDO $db, string $token, string $ip): array {
    $booking = get_booking_by_token($db, $token);
    if (!$booking) {
        return ['ok' => false, 'error' => 'Rezervace nenalezena.'];
    }
    $id = (int) $booking['id'];
    $stmt = $db->prepare('DELETE FROM bookings WHERE id = ?');
    $stmt->execute([$id]);
    log_audit($db, 'public_booking_deleted', 'public', $ip, ['booking_id' => $id]);
    return ['ok' => true];
}
