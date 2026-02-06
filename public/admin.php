<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/bookings.php';
require_once __DIR__ . '/../app/admin_actions.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

send_security_headers();
secure_session_start();
$db = db();
migrate($db);
$csrf = csrf_token();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    init_api_error_handler();
    $action = $_POST['action'] ?? '';
    debug_log('admin_request', [
        'action' => $action,
        'ip' => $ip,
    ]);

    if ($action === 'login') {
        if (!rate_limit($db, 'admin_login:' . $ip, 5, 900)) {
            fail_json('Příliš mnoho pokusů.', 429);
        }
        $pass = (string) ($_POST['password'] ?? '');
        $hash = (string) cfg('admin_password_hash');
        if ($hash === '' || !password_verify($pass, $hash)) {
            fail_json('Neplatné heslo.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        log_audit($db, 'admin_login', 'admin', $ip, []);
        respond_json(['ok' => true]);
    }

    require_csrf();

    if ($action === 'logout') {
        $_SESSION['is_admin'] = false;
        session_regenerate_id(true);
        respond_json(['ok' => true]);
    }

    require_admin();

    if ($action === 'create_booking') {
        $result = admin_create_booking($db, $_POST, $ip);
        if (!$result['ok']) {
            fail_json($result['error']);
        }
        respond_json(['ok' => true, 'booking_id' => $result['booking_id']]);
    }
    if ($action === 'delete_booking') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            fail_json('Neplatné ID.');
        }
        respond_json(admin_delete_booking($db, $id, $ip));
    }
    if ($action === 'create_recurring') {
        $result = admin_create_recurring($db, $_POST, $ip);
        if (!$result['ok']) {
            fail_json($result['error']);
        }
        respond_json(['ok' => true, 'rule_id' => $result['rule_id']]);
    }
    if ($action === 'delete_recurring') {
        $ruleId = (int) ($_POST['rule_id'] ?? 0);
        if ($ruleId <= 0) {
            fail_json('Neplatné ID.');
        }
        respond_json(admin_delete_recurring($db, $ruleId, $ip));
    }
    if ($action === 'delete_occurrence') {
        $ruleId = (int) ($_POST['rule_id'] ?? 0);
        $dateTs = (int) ($_POST['date_ts'] ?? 0);
        if ($ruleId <= 0 || $dateTs <= 0) {
            fail_json('Neplatné data.');
        }
        respond_json(admin_delete_occurrence($db, $ruleId, $dateTs, $ip));
    }
    if ($action === 'list_admin') {
        $from = (int) ($_POST['from'] ?? 0);
        $to = (int) ($_POST['to'] ?? 0);
        if ($from <= 0 || $to <= 0 || $to <= $from) {
            fail_json('Invalid range');
        }
        $bookings = list_bookings($db, $from, $to);
        $recurring = list_recurring_occurrences($db, $from, $to);
        $rules = $db->query('SELECT * FROM recurring_rules ORDER BY id DESC')->fetchAll();
        $audit = $db->query('SELECT * FROM audit_log ORDER BY ts DESC LIMIT 50')->fetchAll();
        respond_json(['ok' => true, 'bookings' => $bookings, 'recurring' => $recurring, 'rules' => $rules, 'audit' => $audit]);
    }

    fail_json('Unknown action', 400);
}

$tz = new DateTimeZone('Europe/Prague');
$baseDate = new DateTimeImmutable('now', $tz);
$weekStart = $baseDate->modify('monday this week')->setTime(0, 0);
$weekLabel = $weekStart->format('o-\WW');
$admin = is_admin();
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="<?= h($csrf) ?>" />
  <title>Administrace UMT</title>
  <link rel="stylesheet" href="/assets/app.css" />
</head>
<body data-page="admin" data-week-start="<?= h($weekStart->format('Y-m-d')) ?>" data-week-label="<?= h($weekLabel) ?>" data-grid-start="<?= h(cfg('grid_start')) ?>" data-grid-end="<?= h(cfg('grid_end')) ?>" data-step-min="<?= h((string) cfg('grid_step_min')) ?>" data-space-label-a="<?= h((string) cfg('space_label_a')) ?>" data-space-label-b="<?= h((string) cfg('space_label_b')) ?>">
  <div class="layout">
    <header class="topbar">
      <div class="brand">
        <div class="title">Administrace</div>
        <div class="subtitle">Správa rezervací UMT</div>
      </div>
      <div class="actions">
        <a class="btn ghost" href="/">Zpět na rozpis</a>
        <?php if ($admin): ?>
          <button class="btn" id="btn-logout">Odhlásit</button>
        <?php endif; ?>
      </div>
    </header>

    <main class="content">
      <?php if (!$admin): ?>
        <div class="panel narrow">
          <h2>Přihlášení</h2>
          <form id="form-login" class="form">
            <input type="hidden" name="action" value="login" />
            <label>
              Heslo administrátora
              <input type="password" name="password" required />
            </label>
            <button class="btn primary" type="submit">
              <span class="btn-text">Přihlásit</span>
              <span class="spinner" aria-hidden="true"></span>
            </button>
          </form>
        </div>
      <?php else: ?>
        <div class="grid-admin">
          <section class="panel">
            <h2>Vytvořit rezervaci</h2>
            <form id="form-admin-book" class="form">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="create_booking" />
              <div class="grid-2">
                <label>
                  Datum
                  <input type="date" name="date" required />
                </label>
                <label>
                  Kategorie
                  <select name="category" required>
                    <?php foreach (CATEGORIES as $cat): ?>
                      <option value="<?= h($cat) ?>"><?= h($cat) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>
                  Začátek
                  <input type="time" name="start" required />
                </label>
                <label>
                  Konec
                  <input type="time" name="end" required />
                </label>
                <label>
                  Prostor
                  <select name="space" required>
                    <option value="WHOLE">Celá UMT</option>
                    <option value="HALF_A"><?= h(space_label('HALF_A')) ?></option>
                    <option value="HALF_B"><?= h(space_label('HALF_B')) ?></option>
                  </select>
                </label>
                <label>
                  Jméno / tým
                  <input type="text" name="name" maxlength="80" required />
                </label>
              </div>
              <label>
                E-mail (nebude veřejný)
                <input type="email" name="email" required />
              </label>
              <button class="btn primary" type="submit">
                <span class="btn-text">Uložit</span>
                <span class="spinner" aria-hidden="true"></span>
              </button>
            </form>
          </section>

          <section class="panel">
            <h2>Opakované rezervace</h2>
            <form id="form-recurring" class="form">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="create_recurring" />
              <div class="grid-2">
                <label>
                  Název
                  <input type="text" name="title" maxlength="80" required />
                </label>
                <label>
                  Kategorie
                  <select name="category" required>
                    <?php foreach (CATEGORIES as $cat): ?>
                      <option value="<?= h($cat) ?>"><?= h($cat) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>
                  Den v týdnu
                  <select name="dow" required>
                    <option value="1">Pondělí</option>
                    <option value="2">Úterý</option>
                    <option value="3">Středa</option>
                    <option value="4">Čtvrtek</option>
                    <option value="5">Pátek</option>
                    <option value="6">Sobota</option>
                    <option value="7">Neděle</option>
                  </select>
                </label>
                <label>
                  Prostor
                  <select name="space" required>
                    <option value="WHOLE">Celá UMT</option>
                    <option value="HALF_A"><?= h(space_label('HALF_A')) ?></option>
                    <option value="HALF_B"><?= h(space_label('HALF_B')) ?></option>
                  </select>
                </label>
                <label>
                  Začátek
                  <input type="time" name="start" required />
                </label>
                <label>
                  Konec
                  <input type="time" name="end" required />
                </label>
                <label>
                  Od
                  <input type="date" name="start_date" required />
                </label>
                <label>
                  Do
                  <input type="date" name="end_date" required />
                </label>
              </div>
              <button class="btn primary" type="submit">
                <span class="btn-text">Vytvořit</span>
                <span class="spinner" aria-hidden="true"></span>
              </button>
            </form>
          </section>
        </div>

        <section class="panel">
          <div class="week-controls">
            <button class="btn ghost" id="week-prev">←</button>
            <div id="week-label" class="week-label"></div>
            <button class="btn ghost" id="week-next">→</button>
          </div>
          <h2>Rezervace v týdnu</h2>
          <div id="admin-bookings" class="table"></div>
        </section>

        <section class="panel">
          <h2>Opakování</h2>
          <div id="admin-rules" class="table"></div>
        </section>

        <section class="panel">
          <h2>Audit log</h2>
          <div id="admin-audit" class="table"></div>
        </section>
      <?php endif; ?>
    </main>
  </div>
  <div class="toast" id="toast"></div>
  <script src="/assets/app.js" defer></script>
</body>
</html>