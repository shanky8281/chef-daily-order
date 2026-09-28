<?php
/* Shared start for command-line scripts. They refuse to run from the web. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
foreach (['core', 'auth', 'catalog', 'orders', 'admin', 'sheets', 'market'] as $lib) require __DIR__ . "/../lib/$lib.php";
