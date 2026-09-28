<?php
/* Test runner:  php tests/run.php
 * Needs an empty MySQL/MariaDB database. Defaults suit CI; override with
 *   CDO_TEST_DSN="mysql:host=127.0.0.1;dbname=cdo_test"  CDO_TEST_USER=root  CDO_TEST_PASSWORD=...
 * Every run wipes that database, creates an admin, starts PHP's web server on a free port,
 * then runs every tests/*_test.php file. Exit code 0 = all passed.
 *   php tests/run.php --serve   only sets up and keeps the server running (for tests/browser.mjs) */
declare(strict_types=1);

$root = dirname(__DIR__);
$dsn = getenv('CDO_TEST_DSN') ?: 'mysql:host=127.0.0.1;dbname=cdo_test;charset=utf8mb4';
$user = getenv('CDO_TEST_USER') ?: 'root';
$pass = getenv('CDO_TEST_PASSWORD') ?: '';
if (!str_contains($dsn, 'test')) { fwrite(STDERR, "Refusing: the test database name must contain \"test\".\n"); exit(2); }

$tmp = sys_get_temp_dir() . '/cdo-test-' . getmypid();
@mkdir($tmp);
$mailLog = "$tmp/mail.log";
touch($mailLog);
$config = "$tmp/config.php";
file_put_contents($config, '<?php return ' . var_export([
    'db_dsn' => $dsn, 'db_user' => $user, 'db_password' => $pass,
    'cookie_secure' => false, 'base_url' => 'http://test.local', 'mail_log' => $mailLog,
], true) . ';');
putenv("CDO_CONFIG=$config");

// Empty database.
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo = null;

// First admin, the way it is done on the server (design §8).
$out = shell_exec('CDO_ADMIN_PASSWORD=admin-pass-1 ' . escapeshellarg(PHP_BINARY) . ' ' .
                  escapeshellarg("$root/public/api/cron/create-admin.php") . ' Shankar shan8281@gmail.com 2>&1');
if (!str_contains((string)$out, 'created')) { fwrite(STDERR, "create-admin failed: $out\n"); exit(1); }

// Web server on a free port.
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)explode(':', stream_socket_get_name($sock, false))[1];
fclose($sock);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/tests/router.php"],
    [1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']], $pipes, $root,
    // Several workers, like the real server: with one, a browser's unused spare connection can
    // stall the next request for up to 30 s (seen once on GitHub with Chrome 153).
    ['CDO_CONFIG' => $config, 'PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
register_shutdown_function(function () use ($server, $tmp) {
    proc_terminate($server);
    array_map('unlink', glob("$tmp/*") ?: []);
    @rmdir($tmp);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

define('BASE', "http://127.0.0.1:$port");

// --serve: set up only, then keep the server running for the browser tests (tests/browser.mjs).
if (in_array('--serve', $argv, true)) {
    echo "READY " . BASE . " $mailLog\n";
    while (true) sleep(60);
}
define('MAIL_LOG', $mailLog);
require __DIR__ . '/helpers.php';
foreach (['core', 'auth', 'catalog', 'orders', 'admin', 'sheets', 'market'] as $lib) require "$root/public/api/lib/$lib.php";

$files = glob(__DIR__ . '/*_test.php');
sort($files);
foreach ($files as $f) {
    echo "\n── " . basename($f) . "\n";
    require $f;
}
echo "\n" . ($GLOBALS['failures'] ? "{$GLOBALS['failures']} FAILED" : 'ALL PASSED') . " ({$GLOBALS['checks']} checks)\n";
if ($GLOBALS['failures']) echo implode("\n", preg_grep('/PHP |error/i', file("$tmp/server.log") ?: [])) . "\n";
exit($GLOBALS['failures'] ? 1 : 0);
