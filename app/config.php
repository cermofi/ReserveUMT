<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Prague');

function load_env_file(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if ($key === '') {
            continue;
        }
        if (($val[0] ?? '') === '"' && substr($val, -1) === '"') {
            $val = stripcslashes(substr($val, 1, -1));
        } elseif (($val[0] ?? '') === "'" && substr($val, -1) === "'") {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
        }
    }
}

load_env_file(__DIR__ . '/../.env');

function env(string $key, $default = null) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

$CONFIG = [
    'db_path' => env('DB_PATH', __DIR__ . '/../data/mrbs.sqlite'),
    'app_secret' => env('APP_SECRET', ''),
    'admin_password_hash' => env('ADMIN_PASSWORD_HASH', ''),
    'app_url' => env('APP_URL', ''),
    'grid_start' => env('GRID_START', '08:00'),
    'grid_end' => env('GRID_END', '21:00'),
    'grid_step_min' => (int) env('GRID_STEP_MIN', '30'),
    'space_label_a' => env('SPACE_LABEL_A', 'Půlka A'),
    'space_label_b' => env('SPACE_LABEL_B', 'Půlka B'),
    'debug_log_enabled' => (bool) env('DEBUG_LOG_ENABLED', ''),
    'debug_log_path' => env('DEBUG_LOG_PATH', __DIR__ . '/../data/debug.log'),
    'smtp' => [
        'host' => env('SMTP_HOST', ''),
        'port' => (int) env('SMTP_PORT', '587'),
        'user' => env('SMTP_USER', ''),
        'pass' => env('SMTP_PASS', ''),
        'from_email' => env('SMTP_FROM_EMAIL', ''),
        'from_name' => env('SMTP_FROM_NAME', 'UMT Rezervace'),
        'secure' => env('SMTP_SECURE', 'tls'),
    ],
];

const CATEGORIES = ['MP', 'MD', 'SŽ', 'Žáci', 'Dorost', 'Muži', 'Soukromá'];
const SPACES = ['WHOLE', 'HALF_A', 'HALF_B'];

function cfg(string $key, $default = null) {
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

function app_url(): string {
    $url = (string) cfg('app_url', '');
    if ($url !== '') {
        return rtrim($url, '/');
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $secure ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function space_label(string $space): string {
    if ($space === 'WHOLE') {
        return 'Celá UMT';
    }
    if ($space === 'HALF_A') {
        return (string) cfg('space_label_a', 'Půlka A');
    }
    if ($space === 'HALF_B') {
        return (string) cfg('space_label_b', 'Půlka B');
    }
    return $space;
}
