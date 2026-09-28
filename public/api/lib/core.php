<?php
/* Core: configuration, database (with automatic migrations), JSON I/O and errors. */
declare(strict_types=1);

const UNITS = ['kg', 'g', 'l', 'ml', 'unit', 'bunch', 'box', 'pack', 'sack', 'dozen', 'can', 'roll', 'tray'];

/** Raised by any handler; becomes a JSON error with this HTTP status. */
class ApiError extends RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}

function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = getenv('CDO_CONFIG') ?: __DIR__ . '/../config.php';
        $cfg = (is_file($file) ? require $file : []) + [
            'db_dsn'        => '',
            'db_user'       => '',
            'db_password'   => '',
            'base_url'      => 'https://buy.mirchi.cl',
            'cookie_secure' => true,
            'google_key'    => '',
            'mail_from'     => 'no-reply@mirchi.cl',
            'mail_log'      => '',     // tests: write e-mails to this file instead of sending
            'odepa_ckan'    => 'https://datos.odepa.gob.cl',
            'timezone'      => 'America/Santiago',
        ];
        date_default_timezone_set($cfg['timezone']);
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

function now(int $plusSeconds = 0): string
{
    config();   // sets the timezone
    return date('Y-m-d H:i:s', time() + $plusSeconds);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (config('db_dsn') === '') throw new ApiError(500, 'Server is not configured (api/config.php)');
    $pdo = new PDO(config('db_dsn'), config('db_user'), config('db_password'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    migrate($pdo);
    return $pdo;
}

/** Run new files from migrations/ in order (design §8). A lock stops two requests racing. */
function migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INT NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)');
    $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    sort($files);
    $done = array_map('intval', $pdo->query('SELECT version FROM schema_version')->fetchAll(PDO::FETCH_COLUMN));
    $todo = array_filter($files, fn($f) => !in_array((int)basename($f), $done, true));
    if (!$todo) return;

    $pdo->query("SELECT GET_LOCK('cdo_migrate', 30)");
    try {
        $done = array_map('intval', $pdo->query('SELECT version FROM schema_version')->fetchAll(PDO::FETCH_COLUMN));
        foreach ($todo as $file) {
            $version = (int)basename($file);
            if (in_array($version, $done, true)) continue;
            $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
            foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
                if (trim($stmt) !== '') $pdo->exec($stmt);
            }
            $pdo->prepare('INSERT INTO schema_version (version, applied_at) VALUES (?, ?)')->execute([$version, now()]);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('cdo_migrate')");
    }
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function setting(string $name): string
{
    return (string)(q('SELECT value FROM settings WHERE name = ?', [$name])->fetchColumn() ?: '');
}

function set_setting(string $name, string $value): void
{
    q('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
}

// ── Input helpers ─────────────────────────────────────────────────────────────
function body(): array
{
    static $body = null;
    if ($body === null) {
        $raw = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);
        $body = $raw === '' || $raw === false ? [] : json_decode($raw, true);
        if (!is_array($body)) throw new ApiError(400, 'Body must be JSON');
    }
    return $body;
}

function text($v, int $max = 120): string
{
    if (!is_scalar($v)) return '';
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v) ?? '');
    return mb_substr($v, 0, $max);
}

function need_text(array $in, string $key, string $label, int $max = 120): string
{
    $v = text($in[$key] ?? '', $max);
    if ($v === '') throw new ApiError(400, "$label is required");
    return $v;
}

function number($v, string $label, float $min = 0, float $max = 1e9): float
{
    if (is_string($v)) $v = str_replace(',', '.', trim($v));
    if (!is_numeric($v)) throw new ApiError(400, "$label must be a number");
    $n = (float)$v;
    if ($n < $min || $n > $max) throw new ApiError(400, "$label is out of range");
    return $n;
}

/** Lowercase without accents, for searching and matching names. */
function fold(string $s): string
{
    $s = mb_strtolower(trim($s));
    if (class_exists('Normalizer')) {
        $s = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($s, Normalizer::FORM_D)) ?? $s;
    }
    return preg_replace('/\s+/', ' ', $s) ?? $s;
}
