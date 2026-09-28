<?php
/* Daily ODEPA market prices (F29, design §5.5). Cases ported from the previous project's tests. */

check('F29 numbers: 12.500', mnumber('12.500') === 12500.0);
check('F29 numbers: $ 1.234,5', mnumber('$ 1.234,5') === 1234.5);
check('F29 numbers: 1234.5', mnumber('1234.5') === 1234.5);
check('F29 numbers: empty', mnumber('') === null);
check('F29 dates: 25/09/2026', mdate('25/09/2026') === '2026-09-25');
check('F29 dates: ISO with time', mdate('2026-09-25T00:00:00') === '2026-09-25');

foreach ([
    [18000, '$/caja 18 kilos', 'kg', 1000], [900, '$/kilo', 'kg', 900], [1200, '$/docena', 'unit', 100],
    [12000, '$/caja 10 docenas', 'unit', 100], [3000, '$/paquete 10 atados', 'bunch', 300], [1200, '$/docena de atados', 'bunch', 100],
    [250, '$/atado', 'bunch', 250], [3166, '$/paquete 36 unidades', 'bunch', null], [300000, '$/bins (400 kilos)', 'kg', 750],
    [1150, '$/kilo (volumen en unidades)', 'kg', 1150], [1200, '$/bandeja', 'kg', null], [1000, '$/caja 10 kilos', 'l', null],
] as [$price, $unit, $our, $want]) {
    $got = per_unit((float)$price, mnorm($unit), $our);
    check("F29 convert \"$unit\" → $our", $want === null ? $got === null : abs($got - $want) < 0.01, $got);
}
check('F29 convert "$/docena de atados (3 kilos)" → bunch', abs(per_unit(4762, mnorm('$/docena de atados (3 kilos)'), 'bunch') - 396.83) < 0.01);

$csv = <<<CSV
Fecha;Mercado;Producto;Variedad;Calidad;Precio minimo;Precio maximo;Precio promedio;Unidad de comercializacion
2026-09-24;Mercado Lo Valledor de Santiago;Tomate;Larga vida;Primera;15.000;19.000;18.000;$/caja 18 kilos
2026-09-25;Mercado Lo Valledor de Santiago;Tomate;Larga vida;Primera;18.000;22.000;21.600;$/caja 18 kilos
2026-09-25;Vega Monumental Concepción;Tomate;Larga vida;Primera;30.000;30.000;30.000;$/caja 18 kilos
2026-09-25;Mercado Lo Valledor de Santiago;Cebolla;Guarda;Primera;9.000;9.000;9.000;$/malla 18 kilos
2026-09-25;Mercado Lo Valledor de Santiago;Cebollín;;Primera;5.000;5.000;5.000;$/caja 20 atados
2026-09-25;Mercado Lo Valledor de Santiago;Lechuga;Escarola;Primera;6.000;6.000;6.000;$/caja 12 unidades
2026-09-25;Mercado Lo Valledor de Santiago;Palta;Hass;Primera;2.500,5;2.500,5;2.500,5;$/kilo
2026-09-25;Mercado Lo Valledor de Santiago;Choclo;;Primera;4.800;4.800;4.800;$/docena
2026-09-25;Mercado Lo Valledor de Santiago;Cilantro;;Primera;3.000;3.000;3.000;$/paquete 10 atados
2026-04-17;Mercado Lo Valledor de Santiago;Betarraga;;Primera;1.500;1.500;1.500;$/caja 16 kilos
CSV;
$rows = market_rows($csv);
check('F29 quotes older than 14 days are ignored', !in_array('betarraga', array_column($rows, 'product'), true), array_column($rows, 'product'));
$t = market_price($rows, ['tomate'], 'kg');
check('F29 latest date, Santiago only, median per kg', $t['price'] === 1200 && $t['date'] === '2026-09-25', $t);
check('F29 quote text kept', $t['quote'] === '$21.600 $/caja 18 kilos (mercado lo valledor de santiago)', $t['quote']);
check('F29 "cebolla" does not match "cebollín"', market_price($rows, ['cebolla'], 'kg')['price'] === 500);
check('F29 bunches', market_price($rows, ['cebollin'], 'bunch')['price'] === 250);
check('F29 units', market_price($rows, ['lechuga'], 'unit')['price'] === 500);
check('F29 first keyword with a quote wins', market_price($rows, ['palta hass', 'palta'], 'kg')['price'] === 2501);
check('F29 dozens', market_price($rows, ['choclo'], 'unit')['price'] === 400);
check('F29 no quote → null', market_price($rows, ['arveja'], 'kg') === null);

// Applied to the database: only market items change; yesterday's price becomes the trend base.
$admin = Client::login('Shankar', 'admin-pass-1');
[, $cat] = $admin->get('catalog?all=1');
$tomato = array_values(array_filter($cat['items'], fn($i) => $i['es'] === 'Tomate'))[0];
$ginger = array_values(array_filter($cat['items'], fn($i) => $i['es'] === 'Jengibre'))[0];
$beet = array_values(array_filter($cat['items'], fn($i) => $i['source'] === 'market' && str_contains($i['keywords'], 'betarraga')))[0] ?? null;
q("UPDATE items SET market_price = 1000, market_prev = NULL, market_date = '2026-09-24' WHERE id = ?", [$tomato['id']]);
if ($beet) q("UPDATE items SET market_price = 93, market_date = '2026-04-17' WHERE id = ?", [$beet['id']]);
$r = apply_market_prices($rows);
check('F29 prices applied, market date saved', $r['updated'] >= 5 && $r['marketDate'] === '2026-09-25' && setting('market_date') === '2026-09-25', $r);
[, $cat] = $admin->get('catalog?all=1');
$byId = array_column($cat['items'], null, 'id');
check('F29 new market price shown', $byId[$tomato['id']]['price'] === 1200 && $byId[$tomato['id']]['marketDate'] === '2026-09-25', $byId[$tomato['id']]);
check('F29 trend ▲ against yesterday', ($byId[$tomato['id']]['trend'] ?? null) === 20, $byId[$tomato['id']]);
check('F29 manual items are never touched', $byId[$ginger['id']]['price'] === $ginger['price'] && $byId[$ginger['id']]['source'] === 'manual');
if ($beet) check('F29 a very old market price falls back to the manual price', $byId[$beet['id']]['price'] === $byId[$beet['id']]['manualPrice'], $byId[$beet['id']]);
$before = setting('market_date');
check('F29 nothing matched → nothing changes', apply_market_prices(market_rows("Fecha;Producto;Precio promedio;Unidad\n2026-09-26;Durian;1000;\$/kilo"))['updated'] === 0
      && setting('market_date') === $before);
try {
    market_rows("a;b\n1;2");
    check('F29 unexpected ODEPA format is refused', false);
} catch (RuntimeException $e) {
    check('F29 unexpected ODEPA format is refused', str_contains($e->getMessage(), 'Unexpected CSV columns'));
}
