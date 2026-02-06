<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';

$db = db();
migrate($db);
echo "Migrations complete.\n";

