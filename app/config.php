<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Prague');

// Upravit zde
$CONFIG = [
    'db_path' => __DIR__ . '/../data/mrbs.sqlite',
    'app_secret' => 'change-me',
    'admin_password_hash' => '',
    'app_url' => '',
    'grid_start' => '08:00',
    'grid_end' => '21:00',
    'grid_step_min' => 30,
    'space_label_a' => 'Půlka A',
    'space_label_b' => 'Půlka B',
    'debug_log_enabled' => false,
    'debug_log_path' => __DIR__ . '/../data/debug.log',
    'smtp' => [
        'host' => '',
        'port' => 587,
        'user' => '',
        'pass' => '',
        'from_email' => '',
        'from_name' => 'UMT Rezervace',
        'secure' => 'tls',
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