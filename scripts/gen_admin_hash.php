<?php
declare(strict_types=1);

$pass = $argv[1] ?? '';
if ($pass === '') {
    fwrite(STDERR, "Usage: php scripts/gen_admin_hash.php <password>\n");
    exit(1);
}
echo password_hash($pass, PASSWORD_DEFAULT) . "\n";

