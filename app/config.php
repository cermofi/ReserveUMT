<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Prague');

// Upravit zde
$CONFIG = [
    'db_path' => __DIR__ . '/../../data/mrbs.sqlite',
    'app_secret' => 'a24f3cb3ffc1b625d209b3c462d31f11ec63c888cf374bfe86fee517028176d5',
    'admin_password_hash' => '$2y$10$N/.nTbjHUl9afmRkQB8Ra.qCd29fs8EoJlE3lwlC7Nu3.URDYi5ai',
    'app_url' => '',
    'grid_start' => '08:00',
    'grid_end' => '21:00',
    'grid_step_min' => 30,
    'space_label_a' => 'Půlka A',
    'space_label_b' => 'Půlka B',
    'debug_log_enabled' => true,
    'debug_log_path' => 'debug.log',
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'user' => 'cechiedubecrezervace@gmail.com',
        'pass' => 'pbez wlue rusn uvlz',
        'from_email' => 'cechiedubecrezervace@gmail.com',
        'from_name' => 'UMT Rezervace',
        'secure' => 'tls',
    ],
];

const CATEGORIES = ['MP', 'MD', 'SŽ', 'Žáci', 'Dorost', 'Muži', 'Soukromá'];
const SPACES = ['WHOLE', 'HALF_A', 'HALF_B'];

function app_version(): string {
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    $buildNumber = getenv('BUILD_NUMBER') ?: null;
    $gitSha = getenv('GIT_SHA') ?: null;
    $buildDate = getenv('BUILD_DATE') ?: null;

    // Fallback: derive from git if env vars are missing.
    if ($buildNumber === null || $gitSha === null) {
        $buildNumber = trim((string) @shell_exec('git rev-list --count HEAD 2>/dev/null')) ?: null;
        $gitSha = trim((string) @shell_exec('git rev-parse --short HEAD 2>/dev/null')) ?: null;
    }

    if ($buildNumber === null || $buildNumber === '') {
        $buildNumber = '0';
    }
    if ($gitSha === null || $gitSha === '') {
        $gitSha = 'dev';
    }

    $version = 'v' . $buildNumber . ' · ' . $gitSha;
    if ($buildDate !== null && $buildDate !== '') {
        $version .= ' · ' . $buildDate;
    }

    return $version;
}

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
