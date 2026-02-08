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
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="<?= h($csrf) ?>" />
  <title>UMT Rozpis</title>
  <link rel="stylesheet" href="/assets/app.css" />
</head>
<body data-page="public" data-week-start="<?= h($weekStart->format('Y-m-d')) ?>" data-week-label="<?= h($weekLabel) ?>" data-grid-start="<?= h(cfg('grid_start')) ?>" data-grid-end="<?= h(cfg('grid_end')) ?>" data-step-min="<?= h((string) cfg('grid_step_min')) ?>" data-space-label-a="<?= h((string) cfg('space_label_a')) ?>" data-space-label-b="<?= h((string) cfg('space_label_b')) ?>">
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
        <div id="week-label" class="week-label"></div>
        <button class="btn ghost" id="week-next">→</button>
        <input type="date" id="week-date" aria-label="Přejít na datum" />
      </div>
      <div class="legend-split">
        <span>A = <?= h(space_label('HALF_A')) ?> (vlevo)</span>
        <span>B = <?= h(space_label('HALF_B')) ?> (vpravo)</span>
        <span>CELÁ = celá UMT</span>
      </div>

      <div id="calendar" class="calendar"></div>
      <div id="agenda" class="agenda"></div>
    </main>
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
          <label>
            Datum
            <input type="date" name="date" required />
          </label>
          <label>
            Začátek
            <div class="time-row">
              <button class="btn tiny ghost" type="button" data-time-adjust="start" data-delta="-30">−30</button>
              <input type="time" name="start" required />
              <button class="btn tiny ghost" type="button" data-time-adjust="start" data-delta="30">+30</button>
            </div>
          </label>
          <label>
            Konec
            <div class="time-row">
              <button class="btn tiny ghost" type="button" data-time-adjust="end" data-delta="-30">−30</button>
              <input type="time" name="end" required />
              <button class="btn tiny ghost" type="button" data-time-adjust="end" data-delta="30">+30</button>
            </div>
          </label>
          <div class="hint">Maximální délka veřejné rezervace je 2 hodiny.</div>
          <div class="warning" id="duration-warning" aria-live="polite"></div>
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
          <label>
            Poznámka (neveřejná)
            <textarea name="note" rows="2" maxlength="500"></textarea>
          </label>
        </div>
        <label id="field-email">
          E-mail
          <input type="email" name="email" required />
        </label>
        <div class="hint">Půlka A = levá část, Půlka B = pravá část. Popisky lze změnit v konfiguraci.</div>
        <button class="btn primary" type="submit">
          <span class="btn-text">Odeslat žádost</span>
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
          <input type="text" name="code" inputmode="numeric" maxlength="6" required />
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

  <script src="/assets/app.js" defer></script>
</body>
</html>
