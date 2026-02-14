<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/bookings.php';

send_security_headers();
send_no_cache_headers();
init_app_error_logging('html');
secure_session_start();
$db = db();
migrate($db);
$csrf = csrf_token();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$bookingToken = trim((string) ($_GET['booking'] ?? ($_POST['booking'] ?? '')));
$emailToken = trim((string) ($_GET['email'] ?? ($_POST['email'] ?? '')));

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update_booking' && $bookingToken !== '') {
        $result = public_update_booking($db, $bookingToken, $_POST, $ip);
        if ($result['ok']) {
            $message = 'Rezervace byla upravena.';
        } else {
            $error = $result['error'];
        }
    } elseif ($action === 'delete_booking' && $bookingToken !== '') {
        $result = public_delete_booking($db, $bookingToken, $ip);
        if ($result['ok']) {
            $message = 'Rezervace byla smazána.';
            $bookingToken = '';
        } else {
            $error = $result['error'];
        }
    }
}

$booking = null;
if ($bookingToken !== '') {
    $booking = get_booking_by_token($db, $bookingToken);
    if (!$booking && $error === '') {
        $error = 'Rezervace nenalezena.';
    }
}

$list = [];
$emailForToken = null;
if ($emailToken !== '') {
    $emailForToken = get_email_by_token($db, $emailToken);
    $list = list_bookings_for_email_token($db, $emailToken);
    if (!$emailForToken && $error === '') {
        $error = 'Neplatný odkaz.';
    }
}

$tz = new DateTimeZone('Europe/Prague');
$appCssVersion = (string) (@filemtime(__DIR__ . '/assets/app.css') ?: 0);
$appJsVersion = (string) (@filemtime(__DIR__ . '/assets/app.js') ?: 0);

function format_date(int $ts): string {
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Prague'));
    return $dt->format('Y-m-d');
}

function format_time(int $ts): string {
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Prague'));
    return $dt->format('H:i');
}

?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="csrf-token" content="<?= h($csrf) ?>" />
  <meta name="app-version" content="<?= h(app_version()) ?>" />
  <meta name="theme-color" content="#0b0d10" />
  <link rel="apple-touch-icon" href="/icons/icon-192.png" />
  <title>Správa rezervace</title>
  <link rel="stylesheet" href="/assets/app.css?v=<?= h($appCssVersion) ?>" />
</head>
<body data-app-version="<?= h(app_version()) ?>">
  <div class="layout">
    <header class="topbar">
      <div class="brand">
        <div class="title">Správa rezervace</div>
        <div class="subtitle">UMT Rozpis</div>
      </div>
      <div class="actions">
        <a class="btn ghost" href="/">Zpět na rozpis</a>
      </div>
    </header>

    <main class="content">
      <?php if ($message): ?>
        <div class="panel ok"><?= h($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="panel warning"><?= h($error) ?></div>
      <?php endif; ?>

      <?php if ($booking): ?>
        <section class="panel narrow">
          <h2>Upravit rezervaci</h2>
          <form class="form" method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
            <input type="hidden" name="action" value="update_booking" />
            <input type="hidden" name="booking" value="<?= h($bookingToken) ?>" />
            <div class="grid-2">
              <label>
                Datum
                <input type="date" name="date" value="<?= h(format_date((int) $booking['start_ts'])) ?>" required autocomplete="off" />
              </label>
              <label>
                Začátek
                <input type="time" name="start" value="<?= h(format_time((int) $booking['start_ts'])) ?>" required inputmode="numeric" />
              </label>
              <label>
                Konec
                <input type="time" name="end" value="<?= h(format_time((int) $booking['end_ts'])) ?>" required inputmode="numeric" />
              </label>
              <label>
                Prostor
                <select name="space" required>
                  <option value="WHOLE" <?= $booking['space'] === 'WHOLE' ? 'selected' : '' ?>>Celá UMT</option>
                  <option value="HALF_A" <?= $booking['space'] === 'HALF_A' ? 'selected' : '' ?>><?= h(space_label('HALF_A')) ?></option>
                  <option value="HALF_B" <?= $booking['space'] === 'HALF_B' ? 'selected' : '' ?>><?= h(space_label('HALF_B')) ?></option>
                </select>
              </label>
              <label>
                Jméno / tým
                <input type="text" name="name" maxlength="80" value="<?= h((string) $booking['name']) ?>" required />
              </label>
            </div>
            <div class="hint">Maximální délka veřejné rezervace je 2 hodiny.</div>
            <div class="grid-2">
              <button class="btn primary" type="submit" enterkeyhint="done">Uložit změny</button>
              <button class="btn ghost" type="submit" name="action" value="delete_booking" onclick="return confirm('Opravdu smazat rezervaci?');">Smazat rezervaci</button>
            </div>
          </form>
        </section>
      <?php endif; ?>

      <?php if ($emailToken !== '' && $emailForToken): ?>
        <section class="panel">
          <h2>Rezervace pro <?= h($emailForToken) ?></h2>
          <?php if (!$list): ?>
            <div class="hint">Žádné aktivní rezervace.</div>
          <?php else: ?>
            <div class="table">
              <?php foreach ($list as $row): ?>
                <?php
                  $start = (int) $row['start_ts'];
                  $end = (int) $row['end_ts'];
                  $range = format_booking_range($start, $end);
                  $token = (string) $row['edit_token'];
                ?>
                <div class="table-row">
                  <div>
                    <div><strong><?= h((string) $row['name']) ?></strong></div>
                    <div class="meta"><?= h($range) ?> · <?= h(space_label((string) $row['space'])) ?></div>
                  </div>
                  <div>
                    <?php if ($token): ?>
                      <a class="btn ghost" href="/manage.php?booking=<?= h($token) ?>">Upravit</a>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if (!$booking && !$emailToken): ?>
        <section class="panel narrow">
          <h2>Neplatný odkaz</h2>
          <div class="hint">Zkontrolujte, že jste zkopírovali celý odkaz z e-mailu.</div>
        </section>
      <?php endif; ?>
    </main>
    <footer class="content" style="padding-top:0;margin-top:-20px;">
      <div class="app-version" data-role="app-version" aria-label="Verze aplikace" data-app-version="<?= h(app_version()) ?>">
        <?= h(app_version()) ?>
      </div>
    </footer>
  </div>
  <script src="/assets/app.js?v=<?= h($appJsVersion) ?>" defer></script>
</body>
</html>
