<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function send_security_headers(): void {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

function secure_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function csrf_token(): string {
    secure_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    $token = $_SESSION['csrf'];
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('csrf_token', $token, [
        'expires' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Strict',
    ]);
    return $token;
}

function require_csrf(): void {
    secure_session_start();
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf'] ?? '';
    $cookieToken = $_COOKIE['csrf_token'] ?? '';
    $valid = is_string($token) && $token !== '' && (
        ($sessionToken !== '' && hash_equals($sessionToken, $token)) ||
        ($cookieToken !== '' && hash_equals($cookieToken, $token))
    );
    if (!$valid) {
        debug_log('csrf_failed', [
            'token_present' => $token !== '',
            'session_id' => session_id(),
            'session_token_present' => $sessionToken !== '',
            'cookie_token_present' => $cookieToken !== '',
            'path' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
        fail_json('CSRF validation failed', 400);
    }
}

function respond_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail_json(string $message, int $status = 400): void {
    respond_json(['ok' => false, 'error' => $message], $status);
}

function rate_limit(PDO $db, string $key, int $limit, int $windowSec): bool {
    $now = time();
    try {
        $db->exec('BEGIN IMMEDIATE');
        $stmt = $db->prepare('SELECT window_start, count FROM rate_limits WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) {
            $ins = $db->prepare('INSERT INTO rate_limits(key, window_start, count) VALUES (?, ?, 1)');
            $ins->execute([$key, $now]);
            $db->exec('COMMIT');
            return true;
        }
        $windowStart = (int) $row['window_start'];
        $count = (int) $row['count'];
        if ($now > $windowStart + $windowSec) {
            $upd = $db->prepare('UPDATE rate_limits SET window_start = ?, count = 1 WHERE key = ?');
            $upd->execute([$now, $key]);
            $db->exec('COMMIT');
            return true;
        }
        if ($count >= $limit) {
            $db->exec('COMMIT');
            return false;
        }
        $upd = $db->prepare('UPDATE rate_limits SET count = count + 1 WHERE key = ?');
        $upd->execute([$key]);
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        return false;
    }
}

function require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail_json('POST required', 405);
    }
}

function validate_date(string $date): bool {
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function validate_time(string $time): bool {
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
}

function validate_email_addr(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function h(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function debug_log(string $message, array $context = []): void {
    if (!cfg('debug_log_enabled', false)) {
        return;
    }
    $path = (string) cfg('debug_log_path', __DIR__ . '/../data/debug.log');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $entry = [
        'ts' => time(),
        'message' => $message,
        'context' => $context,
    ];
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
    $ok = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    if ($ok === false) {
        error_log('debug_log_write_failed: ' . $path);
    }
}

function init_app_error_logging(string $mode = 'html'): void {
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    $logContext = function (): array {
        $redact = function ($value, string $key) {
            if (preg_match('/(pass|password|secret|token|csrf)/i', $key)) {
                return '[redacted]';
            }
            return $value;
        };
        $filter = function (array $src) use ($redact): array {
            $out = [];
            foreach ($src as $k => $v) {
                if (is_scalar($v)) {
                    $out[$k] = $redact($v, (string) $k);
                } else {
                    $out[$k] = '[complex]';
                }
            }
            return $out;
        };
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'get' => $filter($_GET ?? []),
            'post' => $filter($_POST ?? []),
        ];
    };

    set_exception_handler(function (Throwable $e) use ($mode, $logContext): void {
        debug_log('exception', [
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'request' => $logContext(),
        ]);
        if (!headers_sent()) {
            if ($mode === 'json') {
                respond_json(['ok' => false, 'error' => 'Server error'], 500);
            }
            http_response_code(500);
            echo '<!doctype html><meta charset="utf-8"><title>Server error</title><h1>Server error</h1>';
        }
        exit;
    });

    register_shutdown_function(function () use ($mode, $logContext): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $err['request'] = $logContext();
            debug_log('fatal', $err);
            if (!headers_sent()) {
                if ($mode === 'json') {
                    respond_json(['ok' => false, 'error' => 'Server error'], 500);
                }
                http_response_code(500);
                echo '<!doctype html><meta charset="utf-8"><title>Server error</title><h1>Server error</h1>';
            }
        }
    });
}

function init_api_error_handler(): void {
    init_app_error_logging('json');
}
