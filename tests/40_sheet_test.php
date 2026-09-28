<?php
/* Google Sheet copy (F21, design §5.4), with a stand-in for Google's API. */

class FakeSheets
{
    /** Like Google: refuse values wider than the range they are written to ("A1", "A1:L1", "A:L"). */
    private static function fits(string $path, array $values): void
    {
        preg_match("#!([A-Z]+)\d*(?::([A-Z]+)\d*)?#", $path, $m);
        $col = fn($l) => array_reduce(str_split($l), fn($n, $c) => $n * 26 + ord($c) - 64, 0);
        $width = $col($m[2] ?? $m[1]) - $col($m[1]) + 1;
        foreach ($values as $row) {
            if (count($row) > $width) {
                throw new RuntimeException("Google error 400: Requested writing within range [" . explode('?', explode('/values/', $path)[1])[0] . "], but tried writing to column beyond it");
            }
        }
    }

    public array $tabs = ['Sheet1' => []];
    public bool $down = false;
    public int $appends = 0;

    public function call(string $method, string $path, ?array $body = null): array
    {
        $path = rawurldecode($path);
        if (str_ends_with($path, '?fields=sheets.properties.title')) {
            return ['sheets' => array_map(fn($t) => ['properties' => ['title' => $t]], array_keys($this->tabs))];
        }
        if (str_ends_with($path, ':batchUpdate')) {
            $this->tabs[$body['requests'][0]['addSheet']['properties']['title']] = [];
            return [];
        }
        preg_match("#/values/'([^']+)'!#", $path, $m);
        $tab = $m[1];
        if (str_contains($path, ':append')) {
            if ($this->down) throw new RuntimeException('Google is down');
            self::fits($path, $body['values']);
            $this->appends++;
            array_push($this->tabs[$tab], ...$body['values']);
            return [];
        }
        if ($method === 'PUT') {
            self::fits($path, $body['values']);
            $this->tabs[$tab][0] = $body['values'][0];
            return [];
        }
        return $this->tabs[$tab] ? ['values' => [$this->tabs[$tab][0]]] : [];
    }
}

$pending = pending_rows();
check('F21 orders wait until copied', $pending > 0, $pending);

$down = new FakeSheets();
$down->down = true;
$r = sync_to_sheet($down);
check('F21 Google down: nothing lost, error remembered', isset($r['error']) && pending_rows() === $pending && str_contains(setting('sheet_last_error'), 'Google is down'), $r);

$sheet = new FakeSheets();
$r = sync_to_sheet($sheet);
check('F21 copy: every pending line in one append', $r['synced'] === $pending && $r['pending'] === 0 && $sheet->appends === 1, $r);
check('F21 tab "Orders" and header created', ($sheet->tabs['Orders'][0] ?? null) === SHEET_HEADER, $sheet->tabs['Orders'][0] ?? null);
$first = $sheet->tabs['Orders'][1];
check('F21 columns: Date, Time, Order #, Chef, Category, Spanish, English, Qty, Unit, Price, Line total, Order total',
      $first === [date('d/m/Y'), '09:12', '260928-RA-1', 'Ravi', 'Verduras / Vegetables', 'Cebolla morada', 'Onion (red)', 2.5, 'kg', 950, 2375, 5829], $first);
check('F21 error cleared after a good copy', setting('sheet_last_error') === '');
$r = sync_to_sheet($sheet);
check('F21 nothing is copied twice', $r['synced'] === 0 && $sheet->appends === 1, $r);

// A copy that crashed half-way leaves rows claimed; they are picked up again after 10 minutes.
$c = Client::login('Ravi', 'ravi-pass-3');
$c->post('orders', sample_order('44444444-aaaa-4bbb-8ccc-000000000001'));
q("UPDATE order_items SET sync_token = 'deadbeefdeadbeefdeadbeefdeadbeef', sync_claimed_at = ? WHERE synced_at IS NULL", [now(-60)]);
$r = sync_to_sheet($sheet);
check('F21 rows claimed by another copy are left alone', $r['synced'] === 0, $r);
q("UPDATE order_items SET sync_claimed_at = ? WHERE sync_token IS NOT NULL", [now(-11 * 60)]);
$r = sync_to_sheet($sheet);
check('F21 stuck rows are retried after 10 minutes', $r['synced'] === 3 && $r['pending'] === 0, $r);

$own = new FakeSheets();
$own->tabs['Orders'] = [['my own header']];
$c->post('orders', sample_order('44444444-aaaa-4bbb-8ccc-000000000002'));
sync_to_sheet($own);
check('F21 an existing tab and header are kept', $own->tabs['Orders'][0] === ['my own header'] && count($own->tabs['Orders']) === 4, count($own->tabs['Orders']));

// The Google login: a service-account JWT signed with RS256.
$pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($pk, $pem);
$pub = openssl_pkey_get_details($pk)['key'];
class SpySheets extends GoogleSheets
{
    public array $calls = [];
    protected function http(string $method, string $url, ?string $body = null, string $type = 'application/json', array $headers = []): array
    {
        $this->calls[] = compact('method', 'url', 'body', 'headers');
        return str_contains($url, 'token') ? ['access_token' => 'tok123'] : ['ok' => true];
    }
}
$spy = new SpySheets(['client_email' => 'bot@x.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
$spy->call('GET', 'abc?fields=x');
parse_str($spy->calls[0]['body'], $form);
[$h, $p, $sig] = explode('.', $form['assertion']);
$dec = fn($s) => base64_decode(strtr($s, '-_', '+/'));
$claims = json_decode($dec($p), true);
check('F21 Google login: signature verifies', openssl_verify("$h.$p", $dec($sig), $pub, OPENSSL_ALGO_SHA256) === 1);
check('F21 Google login: service account and Sheets scope', $claims['iss'] === 'bot@x.iam.gserviceaccount.com'
      && $claims['scope'] === 'https://www.googleapis.com/auth/spreadsheets', $claims);
check('F21 Google login: token is used for the Sheets call', in_array('Authorization: Bearer tok123', $spy->calls[1]['headers'], true)
      && $spy->calls[1]['url'] === 'https://sheets.googleapis.com/v4/spreadsheets/abc?fields=x', $spy->calls[1]);
