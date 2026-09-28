<?php
/* Router for PHP's built-in web server (tests and local use):
 *     php -S localhost:8000 -t public tests/router.php
 * Sends /api/... to the API and serves everything else from public/. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/../public/api/index.php';
    return true;
}
$file = realpath(__DIR__ . '/../public' . $path);
if ($path === '/' || !$file || !str_starts_with($file, realpath(__DIR__ . '/../public'))) {
    $_SERVER['SCRIPT_NAME'] = '/index.html';
    readfile(__DIR__ . '/../public/index.html');
    return true;
}
return false;
