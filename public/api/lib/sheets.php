<?php
/* Copy order lines to the Google Sheet (F21, design §5.4). Lines are claimed before copying,
 * so running this from several places at once never appends a row twice. */
declare(strict_types=1);

const SHEET_HEADER = ['Date', 'Time', 'Order #', 'Chef', 'Category', 'Spanish name', 'English name',
                      'Qty', 'Unit', 'Price', 'Line total', 'Order total'];
const CLAIM_MINUTES = 10;

/** Minimal Google Sheets client: a service account signs a JWT (RS256) for an access token. */
class GoogleSheets
{
    private string $token = '';

    public function __construct(private array $key) {}

    public static function fromConfig(): self
    {
        $path = (string)config('google_key');
        if ($path === '' || !is_readable($path)) throw new RuntimeException('Google key file not found (google_key in api/config.php)');
        $key = json_decode((string)file_get_contents($path), true);
        if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            throw new RuntimeException('Google key file is not a service-account JSON key');
        }
        return new self($key);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function accessToken(): string
    {
        if ($this->token !== '') return $this->token;
        $aud = $this->key['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $unsigned = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . self::b64(json_encode([
            'iss' => $this->key['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => $aud, 'iat' => $now, 'exp' => $now + 3600,
        ]));
        if (!openssl_sign($unsigned, $sig, $this->key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign with the Google key');
        }
        $res = $this->http('POST', $aud, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $unsigned . '.' . self::b64($sig),
        ]), 'application/x-www-form-urlencoded');
        return $this->token = $res['access_token'] ?? throw new RuntimeException('Google gave no access token');
    }

    protected function http(string $method, string $url, ?string $body = null, string $type = 'application/json', array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge(["Content-Type: $type"], $headers),
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) throw new RuntimeException("Google request failed: $err");
        $json = json_decode((string)$out, true) ?? [];
        if ($code >= 300) {
            $msg = $json['error']['message'] ?? $json['error_description'] ?? substr((string)$out, 0, 200);
            throw new RuntimeException("Google error $code: $msg");
        }
        return $json;
    }

    public function call(string $method, string $path, ?array $body = null): array
    {
        return $this->http($method, 'https://sheets.googleapis.com/v4/spreadsheets/' . $path,
            $body === null ? null : json_encode($body), 'application/json',
            ['Authorization: Bearer ' . $this->accessToken()]);
    }
}

function pending_rows(): int
{
    return (int)q('SELECT COUNT(*) FROM order_items WHERE synced_at IS NULL')->fetchColumn();
}

/** Create the tab and header row if missing. */
function ensure_tab($sheets, string $id, string $tab): void
{
    $meta = $sheets->call('GET', rawurlencode($id) . '?fields=sheets.properties.title');
    $titles = array_map(fn($s) => $s['properties']['title'], $meta['sheets'] ?? []);
    if (!in_array($tab, $titles, true)) {
        $sheets->call('POST', rawurlencode($id) . ':batchUpdate', ['requests' => [['addSheet' => ['properties' => [
            'title' => $tab, 'gridProperties' => ['frozenRowCount' => 1]]]]]]);
    }
    if (empty($sheets->call('GET', rawurlencode($id) . '/values/' . rawurlencode("'$tab'!A1:A1"))['values'])) {
        // The range must be as wide as the header (A1:L1); Google refuses to write past it.
        $sheets->call('PUT', rawurlencode($id) . '/values/' . rawurlencode("'$tab'!A1:L1") . '?valueInputOption=RAW', ['values' => [SHEET_HEADER]]);
    }
}

function num_out($v)
{
    $f = (float)$v;
    return floor($f) == $f ? (int)$f : $f;
}

function sync_to_sheet($sheets = null): array
{
    $id = setting('sheet_id');
    $tab = setting('sheet_tab') ?: 'Orders';
    if ($id === '') return ['synced' => 0, 'pending' => pending_rows(), 'error' => 'No Google Sheet set in Settings'];

    $token = bin2hex(random_bytes(16));
    q('UPDATE order_items SET sync_token = ?, sync_claimed_at = ?
       WHERE synced_at IS NULL AND (sync_token IS NULL OR sync_claimed_at < ?)', [$token, now(), now(-CLAIM_MINUTES * 60)]);
    $rows = q('SELECT o.order_date, o.order_time, o.order_no, u.username, i.category_name, i.name_es, i.name_en,
                      i.qty, i.unit, i.price, i.line_total, o.total
               FROM order_items i JOIN orders o ON o.id = i.order_id JOIN users u ON u.id = o.user_id
               WHERE i.sync_token = ? ORDER BY o.id, i.id', [$token])->fetchAll();
    if (!$rows) return ['synced' => 0, 'pending' => pending_rows()];

    $values = array_map(fn($r) => [
        date('d/m/Y', strtotime($r['order_date'])), $r['order_time'], $r['order_no'], $r['username'], $r['category_name'],
        $r['name_es'], $r['name_en'], num_out($r['qty']), $r['unit'], (int)$r['price'], (int)$r['line_total'], (int)$r['total'],
    ], $rows);

    try {
        $sheets = $sheets ?? GoogleSheets::fromConfig();
        ensure_tab($sheets, $id, $tab);
        $sheets->call('POST', rawurlencode($id) . '/values/' . rawurlencode("'$tab'!A:L") . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS',
            ['values' => $values]);
    } catch (Throwable $e) {
        q('UPDATE order_items SET sync_token = NULL WHERE sync_token = ?', [$token]);   // retry straight away next time
        set_setting('sheet_last_error', now() . ' — ' . mb_substr($e->getMessage(), 0, 400));
        error_log('Chef Daily Order: Google Sheet copy failed, will retry: ' . $e->getMessage());
        return ['synced' => 0, 'pending' => count($rows), 'error' => $e->getMessage()];
    }
    q('UPDATE order_items SET synced_at = ?, sync_token = NULL WHERE sync_token = ?', [now(), $token]);
    set_setting('sheet_last_error', '');
    return ['synced' => count($rows), 'pending' => pending_rows()];
}

function api_sync_now(): array
{
    require_admin();
    return sync_to_sheet();
}
