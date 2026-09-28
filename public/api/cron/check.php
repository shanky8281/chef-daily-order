<?php
/* Server self-check for the deployment (docs/05-deploy-guide.md). Run from cPanel Terminal:
 *     php ~/buy.mirchi.cl/api/cron/check.php
 * Checks PHP, extensions, config.php, the database, the Google key and sheet, mail settings.
 * Changes nothing except creating the database tables on first run. */
declare(strict_types=1);

$ok = true;
function line(bool $pass, string $what, string $hint = ''): void
{
    global $ok;
    if (!$pass) $ok = false;
    echo ($pass ? '  ✔ ' : '  ✘ ') . $what . (!$pass && $hint ? "\n      → $hint" : '') . "\n";
}

echo "Chef Daily Order — server check\n\n";
line(PHP_VERSION_ID >= 80100, 'PHP ' . PHP_VERSION, 'Needs PHP 8.1 or newer. Use cPanel → MultiPHP Manager, and run this with the same PHP (see the guide).');
foreach (['pdo_mysql', 'curl', 'openssl', 'mbstring', 'json'] as $ext) {
    line(extension_loaded($ext), "PHP extension $ext", "Turn on \"$ext\" in cPanel → Select PHP Version → Extensions.");
}
line(extension_loaded('intl'), 'PHP extension intl (search ignores accents on the server)', 'Optional but recommended: turn on "intl" in Select PHP Version → Extensions.');
if (!$ok && !extension_loaded('pdo_mysql')) exit(1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
foreach (['core', 'auth', 'catalog', 'orders', 'admin', 'sheets', 'market'] as $lib) require __DIR__ . "/../lib/$lib.php";

$cfgFile = getenv('CDO_CONFIG') ?: __DIR__ . '/../config.php';
line(is_file($cfgFile), 'config.php exists', 'Copy api/config.sample.php to api/config.php and fill it in.');
if (!is_file($cfgFile)) exit(1);
line(!str_contains((string)file_get_contents($cfgFile), 'CHANGE-ME') && !str_contains((string)file_get_contents($cfgFile), 'CPANELUSER'),
     'config.php is filled in', 'Replace CPANELUSER and CHANGE-ME with your real values.');

echo "\n";
try {
    $n = (int)q('SELECT COUNT(*) FROM items')->fetchColumn();
    line(true, "Database connected; tables ready ($n items)");
    $admins = (int)q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
    line($admins > 0, "Admin users: $admins", 'Create one: php ' . __DIR__ . '/create-admin.php Shankar you@example.com');
} catch (Throwable $e) {
    line(false, 'Database', $e->getMessage() . ' — check db_dsn, db_user and db_password in config.php, and that the user has All privileges on the database.');
    exit(1);
}

echo "\n";
$key = (string)config('google_key');
line($key !== '' && is_readable($key), 'Google key file readable', "Upload the service-account JSON to $key (outside the web folder).");
$docroot = realpath(__DIR__ . '/../..');
line($key === '' || !$docroot || !str_starts_with((string)realpath($key), $docroot), 'Google key is outside the web folder', 'Move it out of the website folder, e.g. to ~/chef-private/, and update google_key.');
if ($key !== '' && is_readable($key)) {
    $email = json_decode((string)file_get_contents($key), true)['client_email'] ?? '';
    echo "      Share the Google Sheet (Editor) with: $email\n";
    try {
        $g = GoogleSheets::fromConfig();
        $meta = $g->call('GET', rawurlencode(setting('sheet_id')) . '?fields=properties.title,sheets.properties.title');
        line(true, 'Google Sheet reachable: "' . ($meta['properties']['title'] ?? '?') . '"');
        $tabs = array_map(fn($s) => $s['properties']['title'], $meta['sheets'] ?? []);
        echo '      Tabs: ' . implode(', ', $tabs) . (in_array(setting('sheet_tab'), $tabs, true) ? '' : ' (the "' . setting('sheet_tab') . '" tab will be created with the first order)') . "\n";
    } catch (Throwable $e) {
        line(false, 'Google Sheet', $e->getMessage() . (str_contains($e->getMessage(), '403') || str_contains($e->getMessage(), '404')
            ? " — share the sheet with $email as Editor, and enable the Google Sheets API in the Google Cloud project." : ''));
    }
}

echo "\n";
line(filter_var((string)config('mail_from'), FILTER_VALIDATE_EMAIL) !== false, 'Reset e-mails are sent from ' . config('mail_from'), 'Set mail_from in config.php to a mailbox that exists in cPanel.');
line(str_starts_with((string)config('base_url'), 'https://'), 'Links in e-mails point to ' . config('base_url'), "Set base_url to 'https://buy.mirchi.cl' in config.php.");
line(function_exists('mail'), 'PHP can send e-mail');

echo "\n" . ($ok ? "All good.\n" : "Fix the ✘ lines above, then run this again.\n");
exit($ok ? 0 : 1);
