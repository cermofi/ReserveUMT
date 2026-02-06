<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

function is_admin(): bool {
    secure_session_start();
    return !empty($_SESSION['is_admin']);
}

function require_admin(): void {
    if (!is_admin()) {
        fail_json('Unauthorized', 401);
    }
}

