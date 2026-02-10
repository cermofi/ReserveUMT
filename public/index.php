<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/security.php';

send_security_headers();
init_app_error_logging('html');
secure_session_start();
$csrf = csrf_token();
$tz = new DateTimeZone('Europe/Prague');

$baseDate = new DateTimeImmutable('now', $tz);
$weekParam = $_GET['week'] ?? '';
$dateParam = $_GET['date'] ?? '';
if (is_string($weekParam) && preg_match('/^\d{4}-\d{2}$/', $weekParam)) {
    $dt = DateTimeImmutable::createFromFormat('o-\WW', $weekParam, $tz);
    if ($dt instanceof DateTimeImmutable) {
        $baseDate = $dt;
    }
} elseif (is_string($dateParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateParam, $tz);
    if ($dt instanceof DateTimeImmutable) {
        $baseDate = $dt;
    }
}
$weekStart = $baseDate->modify('monday this week')->setTime(0, 0);
$weekLabel = $weekStart->format('o-\WW');
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="csrf-token" content="<?= h($csrf) ?>" />
  <meta name="app-version" content="<?= h(app_version()) ?>" />
  <link rel="manifest" href="/manifest.webmanifest" />
  <meta name="theme-color" content="#0b0d10" />
  <link rel="apple-touch-icon" href="/icons/icon-192.png" />
  <title>UMT Rozpis</title>
  <link rel="stylesheet" href="/assets/app.css" />
</head>
<body data-page="public" data-week-start="<?= h($weekStart->format('Y-m-d')) ?>" data-week-label="<?= h($weekLabel) ?>" data-grid-start="<?= h(cfg('grid_start')) ?>" data-grid-end="<?= h(cfg('grid_end')) ?>" data-step-min="<?= h((string) cfg('grid_step_min')) ?>" data-space-label-a="<?= h((string) cfg('space_label_a')) ?>" data-space-label-b="<?= h((string) cfg('space_label_b')) ?>" data-app-version="<?= h(app_version()) ?>">
  <div class="layout">
    <header class="topbar">
      <div class="brand">
        <div class="title">UMT Rozpis</div>
        <div class="subtitle">Týdenní rozpis umělé trávy</div>
      </div>
      <div class="actions">
        <button class="btn" id="btn-new">Nová rezervace</button>
        <a class="btn ghost" href="/admin.php">Administrace</a>
      </div>
    </header>

    <main class="content">
      <div class="week-controls">
        <button class="btn ghost" id="week-prev">←</button>
        <button class="btn ghost" id="week-today">Tento týden</button>
        <button class="btn ghost" id="week-date-trigger" type="button" aria-label="Vybrat datum">
          <span aria-hidden="true">📅</span>
        </button>
        <div id="week-label" class="week-label"></div>
        <button class="btn ghost" id="week-next">→</button>
        <input type="date" id="week-date" aria-label="Přejít na datum" autocomplete="off" class="visually-hidden" />
      </div>
      <div class="legend-split">
        <span>Půlka A = půlka blíž ke vchodu</span><br>
        <span>Půlka B = půlka dál od vchodu</span>
      </div>

      <div class="calendar-wrap">
        <div id="calendar" class="calendar"></div>
      </div>
      <div id="agenda" class="agenda"></div>
    </main>
    <footer class="content" style="padding-top:0;margin-top:-20px;">
      <div class="app-version" data-role="app-version" aria-label="Verze aplikace" data-app-version="<?= h(app_version()) ?>">
        <?= h(app_version()) ?>
      </div>
    </footer>
  </div>

  <div class="modal" id="modal-reserve" aria-hidden="true">
    <div class="modal-card">
      <div class="modal-header">
        <div class="modal-title">Nová rezervace</div>
        <button class="icon-btn" data-close="modal-reserve" aria-label="Zavřít">×</button>
      </div>
      <form id="form-reserve" class="form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
        <div class="grid-2">
          <label class="full-span">
            Datum
            <input type="date" name="date" required autocomplete="off" />
            <div class="hint" id="max-advance-hint" style="display:none; margin-top:4px;"></div>
          </label>
          <div class="grid-2 full-span">
            <label>
              Začátek
              <div class="time-row">
                <button class="btn tiny ghost" type="button" data-time-adjust="start" data-delta="-30">−30</button>
                <input type="time" name="start" required inputmode="numeric" />
                <button class="btn tiny ghost" type="button" data-time-adjust="start" data-delta="30">+30</button>
              </div>
            </label>
            <label>
              Konec
              <div class="time-row">
                <button class="btn tiny ghost" type="button" data-time-adjust="end" data-delta="-30">−30</button>
                <input type="time" name="end" required inputmode="numeric" />
                <button class="btn tiny ghost" type="button" data-time-adjust="end" data-delta="30">+30</button>
              </div>
            </label>
          </div>
          <div class="hint full-span" id="max-duration-hint" style="display:none; margin-top:-4px;"></div>
          <div class="warning full-span" id="duration-warning" aria-live="polite"></div>
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
            <input type="text" name="name" maxlength="80" required autocomplete="name" enterkeyhint="next" />
          </label>
          <label>
            Poznámka (neveřejná)
            <textarea name="note" rows="2" maxlength="500" autocomplete="off" enterkeyhint="done"></textarea>
          </label>
          <label id="field-email" class="full-span">
            E-mail
            <input type="email" name="email" required autocomplete="email" inputmode="email" enterkeyhint="done" />
          </label>
          <div class="hint full-span" id="max-email-hint" style="display:none;"></div>
        </div>
        <button class="btn primary" type="submit">
          <span class="btn-text">Odeslat rezervaci</span>
          <span class="spinner" aria-hidden="true"></span>
        </button>
      </form>
    </div>
  </div>

  <div class="modal" id="modal-verify" aria-hidden="true">
    <div class="modal-card">
      <div class="modal-header">
        <div class="modal-title">Ověření e-mailu</div>
        <button class="icon-btn" data-close="modal-verify" aria-label="Zavřít">×</button>
      </div>
      <form id="form-verify" class="form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
        <input type="hidden" name="pending_id" />
        <label>
          Ověřovací kód
          <input type="text" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" enterkeyhint="done" required />
        </label>
        <div class="hint">Zbývá <span id="verify-countdown">10:00</span></div>
        <button class="btn primary" type="submit">
          <span class="btn-text">Potvrdit rezervaci</span>
          <span class="spinner" aria-hidden="true"></span>
        </button>
      </form>
    </div>
  </div>

  <div class="toast" id="toast"></div>

  <script src="/assets/pwa.js" defer></script>
  <script src="/assets/app.js" defer></script>
</body>
</html>
