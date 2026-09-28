<?php
/* Daily reference prices from ODEPA, Chile's wholesale fruit and vegetable prices (F29, design §5.5).
 * Only items whose price source is "market" are touched. If ODEPA is down or changes its format,
 * nothing changes. Ported from the previous project's update_market_prices.py. */
declare(strict_types=1);

const PREFERRED_MARKETS = ['lo valledor', 'vega central', 'vega mapocho'];
const MAX_AGE_DAYS = 14;
// Words ODEPA uses for each of our units, e.g. "$/caja 18 kilos", "$/paquete 10 atados".
const MARKET_UNIT_WORDS = ['kg' => 'kilos?|kgs?', 'unit' => 'unidades|unidad', 'bunch' => 'atados?|paquetes?'];

function mnorm($s): string
{
    return fold((string)$s);
}

/** '12.500', '12500', '1.234,5', '1234.5' → float. */
function mnumber($s): ?float
{
    $s = preg_replace('/[^\d,.\-]/', '', (string)$s);
    if ($s === '') return null;
    if (str_contains($s, ',') && str_contains($s, '.')) $s = str_replace(['.', ','], ['', '.'], $s);
    elseif (str_contains($s, ',')) $s = str_replace(',', '.', $s);
    elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    return is_numeric($s) ? (float)$s : null;
}

function mdate($s): ?string
{
    $s = substr(trim((string)$s), 0, 10);
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $f) {
        $d = DateTime::createFromFormat("!$f", $s);
        if ($d && $d->format($f) === $s) return $d->format('Y-m-d');
    }
    return null;
}

function http_get_text(string $url, int $timeout = 60): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout,
                            CURLOPT_USERAGENT => 'ChefDailyOrder/1.0']);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($out === false || $code >= 300) throw new RuntimeException("Download failed ($code): $url");
    if (str_starts_with($out, "\xEF\xBB\xBF")) $out = substr($out, 3);
    return mb_check_encoding($out, 'UTF-8') ? $out : mb_convert_encoding($out, 'UTF-8', 'ISO-8859-1');
}

/** Most recently modified CSV of ODEPA's wholesale price dataset. */
function discover_csv_url(): string
{
    $ckan = rtrim((string)config('odepa_ckan'), '/');
    $data = json_decode(http_get_text("$ckan/api/3/action/package_search?" . http_build_query(['q' => 'precios mayoristas frutas hortalizas', 'rows' => 10])), true);
    $found = [];
    foreach ($data['result']['results'] ?? [] as $pkg) {
        if (!str_contains(mnorm(($pkg['title'] ?? '') . ' ' . ($pkg['name'] ?? '')), 'mayorista')) continue;
        foreach ($pkg['resources'] ?? [] as $res) {
            $url = (string)($res['url'] ?? '');
            if (mnorm($res['format'] ?? '') === 'csv' || str_ends_with(strtolower($url), '.csv')) {
                $found[] = [($res['last_modified'] ?? '') ?: ($res['created'] ?? ''), $url];
            }
        }
    }
    if (!$found) throw new RuntimeException('No CSV found on the ODEPA portal');
    rsort($found);
    return $found[0][1];
}

function market_rows(string $csv): array
{
    $sample = substr($csv, 0, 5000);
    $delim = substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
    $fh = fopen('php://memory', 'w+');
    fwrite($fh, $csv);
    rewind($fh);
    $head = fgetcsv($fh, 0, $delim, '"', '');
    if (!$head) throw new RuntimeException('Empty CSV');
    $cols = [];
    foreach ($head as $i => $h) $cols[$i] = trim(preg_replace('/[^a-z0-9]+/', '_', mnorm($h)), '_');
    $find = function (string ...$words) use ($cols): ?int {
        foreach ($cols as $i => $k) {
            if (count(array_filter($words, fn($w) => str_contains($k, $w))) === count($words)) return $i;
        }
        return null;
    };
    $cProd = $find('producto');
    $cPrice = $find('precio', 'promedio') ?? $find('promedio');
    $cUnit = $find('unidad');
    $cDate = $find('fecha') ?? $find('desde');
    $cMkt = $find('mercado');
    $cVar = $find('variedad');
    if ($cProd === null || $cPrice === null || $cUnit === null) throw new RuntimeException('Unexpected CSV columns: ' . implode(', ', $cols));
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $price = mnumber($r[$cPrice] ?? '');
        if (!$price || $price <= 0) continue;
        $rows[] = [
            'product' => mnorm($r[$cProd] ?? ''), 'variety' => $cVar !== null ? mnorm($r[$cVar] ?? '') : '',
            'price' => $price, 'unit' => mnorm($r[$cUnit] ?? ''),
            'date' => $cDate !== null ? mdate($r[$cDate] ?? '') : null, 'market' => $cMkt !== null ? mnorm($r[$cMkt] ?? '') : '',
        ];
    }
    fclose($fh);
    return drop_stale($rows);
}

function drop_stale(array $rows): array
{
    $dates = array_filter(array_column($rows, 'date'));
    if (!$dates) return $rows;
    $cut = date('Y-m-d', strtotime(max($dates) . ' -' . MAX_AGE_DAYS . ' days'));
    return array_values(array_filter($rows, fn($r) => $r['date'] && $r['date'] >= $cut));
}

/** Convert a wholesale quote ("$/caja 18 kilos") to our unit. Null if it can't be done. */
function per_unit(float $price, string $marketUnit, string $unit): ?float
{
    $words = MARKET_UNIT_WORDS[$unit] ?? null;
    if (!$words) return null;
    // A pack size always wins over the pack name: "$/paquete 10 atados" is 10 bunches.
    if (preg_match("/(\\d+(?:[.,]\\d+)?)\\s*(?:$words)\\b/", $marketUnit, $m)) {
        $n = mnumber($m[1]);
        return $n ? $price / $n : null;
    }
    if ($unit !== 'kg') {
        if (preg_match('/(\d+)\s*docenas?\b/', $marketUnit, $m)) return $price / ((int)$m[1] * 12);
        if (preg_match('/\bdocenas?\b/', $marketUnit)) return $price / 12;
    }
    // "$/paquete 36 unidades" is not one bunch: a bare pack name counts only without another size.
    if (preg_match("/\\$\\/\\s*(?:$words)\\b/", $marketUnit) && !preg_match('/\d+\s*[a-z]/', $marketUnit)) return $price;
    return null;
}

/** Median converted price for the first keyword with a usable quote on the latest date. */
function market_price(array $rows, array $keywords, string $unit): ?array
{
    foreach ($keywords as $kw) {
        $kw = mnorm($kw);
        if ($kw === '') continue;
        $conv = [];
        foreach ($rows as $r) {
            $full = trim($r['product'] . ' ' . $r['variety']);
            // "cebolla" must not match "cebollin", but "pimenton" should match "pimenton rojo".
            if ($r['product'] !== $kw && !str_starts_with($r['product'], "$kw ") && $full !== $kw && !str_starts_with($full, "$kw ")) continue;
            $p = per_unit($r['price'], $r['unit'], $unit);
            if ($p) $conv[] = [$r, $p];
        }
        if (!$conv) continue;
        $pref = array_values(array_filter($conv, fn($c) => array_filter(PREFERRED_MARKETS, fn($m) => str_contains($c[0]['market'], $m))));
        $conv = $pref ?: $conv;
        $dates = array_filter(array_map(fn($c) => $c[0]['date'], $conv));
        $latest = $dates ? max($dates) : null;
        if ($latest) $conv = array_values(array_filter($conv, fn($c) => $c[0]['date'] === $latest));
        $ps = array_column($conv, 1);
        sort($ps);
        $n = count($ps);
        $median = $n % 2 ? $ps[intdiv($n, 2)] : ($ps[$n / 2 - 1] + $ps[$n / 2]) / 2;
        usort($conv, fn($a, $b) => abs($a[1] - $median) <=> abs($b[1] - $median));
        $row = $conv[0][0];
        $quote = '$' . number_format($row['price'], 0, ',', '.') . ' ' . $row['unit'] . ($row['market'] ? " ({$row['market']})" : '');
        return ['price' => (int)round($median), 'date' => $latest, 'quote' => mb_substr($quote, 0, 200)];
    }
    return null;
}

/** Update all market items from the given rows. Returns how many got a new price. */
function apply_market_prices(array $rows): array
{
    $dates = array_filter(array_column($rows, 'date'));
    $newest = $dates ? max($dates) : null;
    $cut = $newest ? date('Y-m-d', strtotime("$newest -" . MAX_AGE_DAYS . ' days')) : null;
    $items = q("SELECT * FROM items WHERE price_source = 'market' AND deleted_at IS NULL")->fetchAll();
    $updated = 0;
    $plan = [];
    foreach ($items as $it) {
        $kw = array_map('trim', explode(',', $it['market_keywords']));
        $mp = market_price($rows, $kw, $it['unit']);
        if ($mp) {
            $prev = $it['market_price'] !== null && (int)$it['market_price'] !== $mp['price'] ? (int)$it['market_price'] : $it['market_prev'];
            $plan[] = [$mp['price'], $prev, $mp['date'], $mp['quote'], $it['id']];
            $updated++;
        } elseif ($it['market_date'] && $cut && $it['market_date'] < $cut) {
            $plan[] = [null, null, null, '', $it['id']];   // too old: fall back to the manual price
        }
    }
    if ($updated === 0) return ['updated' => 0, 'marketDate' => null];
    db()->beginTransaction();
    foreach ($plan as $p) q('UPDATE items SET market_price = ?, market_prev = ?, market_date = ?, market_quote = ? WHERE id = ?', $p);
    set_setting('market_date', (string)$newest);
    set_setting('market_updated', now());
    db()->commit();
    return ['updated' => $updated, 'marketDate' => $newest];
}

function update_market_prices(?string $source = null): array
{
    $src = $source ?: discover_csv_url();
    $csv = preg_match('#^https?://#', $src) ? http_get_text($src, 180) : (string)file_get_contents($src);
    $result = apply_market_prices(market_rows($csv));
    if ($result['updated'] === 0) throw new RuntimeException('No market item matched the ODEPA data; prices unchanged');
    return $result;
}
