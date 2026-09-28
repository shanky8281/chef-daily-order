<?php
/* Item list and item manager: add, edit, delete/restore, move, categories, history, import (F8–F10, F23–F31). */

$admin = Client::login('Shankar', 'admin-pass-1');
$chef = Client::login('Ravi', 'ravi-pass-3');

[$s, $cat] = $chef->get('catalog');
check('F31 starting list: 102 items in 11 categories', $s === 200 && count($cat['items']) === 102 && count($cat['categories']) === 11,
      [count($cat['items'] ?? []), count($cat['categories'] ?? [])]);
$onion = $cat['items'][0];
check('F9 items have Spanish and English names', $onion['es'] === 'Cebolla morada' && $onion['en'] === 'Onion (red)', $onion);
check('F29 market items show the ODEPA price', $onion['source'] === 'market' && $onion['price'] === 950, $onion);
check('F8 categories have Spanish and English names', $cat['categories'][0]['es'] === 'Verduras' && $cat['categories'][0]['en'] === 'Vegetables', $cat['categories'][0]);
check('D3 unit list', $cat['units'] === ['kg', 'g', 'l', 'ml', 'unit', 'bunch', 'box', 'pack', 'sack', 'dozen', 'can', 'roll', 'tray'], $cat['units']);
$version = $cat['version'];

// Add (F24)
$veg = $cat['categories'][0]['id'];
foreach ([
    'no Spanish name' => ['es' => '', 'unit' => 'kg', 'price' => 100, 'categoryId' => $veg],
    'unknown unit'    => ['es' => 'Rábano', 'unit' => 'bag', 'price' => 100, 'categoryId' => $veg],
    'no category'     => ['es' => 'Rábano', 'unit' => 'kg', 'price' => 100, 'categoryId' => 9999],
    'negative price'  => ['es' => 'Rábano', 'unit' => 'kg', 'price' => -1, 'categoryId' => $veg],
    'market, no keywords' => ['es' => 'Rábano', 'unit' => 'kg', 'price' => 100, 'categoryId' => $veg, 'source' => 'market'],
] as $name => $body) {
    [$s] = $admin->post('items', $body);
    check("F24 add refused: $name", $s === 400, $s);
}
[$s, $r] = $admin->post('items', ['es' => 'Rábano', 'en' => 'Radish', 'unit' => 'kg', 'price' => '1.200', 'categoryId' => $veg]);
check('F24 admin adds an item (price typed the Chilean way, "1.200")', $s === 200 && $r['id'] > 0, [$s, $r]);
$radish = $r['id'];
[$s, $cat] = $chef->get('catalog');
$last = array_values(array_filter($cat['items'], fn($i) => $i['categoryId'] === $veg));
check('F24 new item shows for chefs, at the end of its category', end($last)['id'] === $radish && end($last)['price'] === 1200, end($last));
check('F24 list version changes', $cat['version'] !== $version);

// Edit (F24) and history (F30)
[$s] = $admin->put("items/$radish", ['es' => 'Rabanito', 'en' => 'Radish', 'unit' => 'bunch', 'price' => 800, 'categoryId' => $veg]);
[$s2, $h] = $admin->get("items/$radish/history");
check('F24 edit item', $s === 200, $s);
check('F30 history records who and what', $s2 === 200 && count($h['changes']) === 2 && $h['changes'][0]['action'] === 'edit'
      && $h['changes'][0]['by'] === 'Shankar' && $h['changes'][0]['before']['name_es'] === 'Rábano' && $h['changes'][0]['after']['price'] === 800, $h);
[$s, $all] = $admin->get('catalog?all=1');
$item = array_values(array_filter($all['items'], fn($i) => $i['id'] === $radish))[0];
check('F30 item shows last changed by', $item['updatedBy'] === 'Shankar' && $item['es'] === 'Rabanito' && $item['unit'] === 'bunch', $item);

// Move (F27)
$ids = fn($c) => array_map(fn($i) => $i['id'], array_values(array_filter($c['items'], fn($i) => $i['categoryId'] === $veg)));
[, $before] = $chef->get('catalog');
$order = $ids($before);
$admin->post("items/$radish/move", ['direction' => 'up']);
[, $after] = $chef->get('catalog');
$moved = $ids($after);
check('F27 move up swaps with the item above', $moved[count($moved) - 2] === $radish && end($moved) === $order[count($order) - 2], $moved);
$admin->post("items/{$order[0]}/move", ['direction' => 'up']);
[, $same] = $chef->get('catalog');
check('F27 first item cannot move further up', $ids($same)[0] === $order[0]);

// Delete / restore (F25)
[$s] = $admin->delete("items/$radish");
[, $cat] = $chef->get('catalog');
check('F25 deleted item disappears for chefs', $s === 200 && !in_array($radish, array_column($cat['items'], 'id'), true), $s);
[, $all] = $admin->get('catalog?all=1');
$item = array_values(array_filter($all['items'], fn($i) => $i['id'] === $radish))[0];
check('F25 admin can still see it as deleted', $item['deleted'] === true, $item);
[$s] = $admin->post("items/$radish/restore");
[, $cat] = $chef->get('catalog');
check('F25 restore brings it back', $s === 200 && in_array($radish, array_column($cat['items'], 'id'), true), $s);

// Categories (F26)
[$s, $r] = $admin->post('categories', ['es' => 'Congelados', 'en' => 'Frozen', 'icon' => '🧊']);
check('F26 add category', $s === 200, [$s, $r]);
$frozen = $r['id'];
[$s] = $admin->put("categories/$frozen", ['es' => 'Congelados', 'en' => 'Frozen food', 'icon' => '❄️']);
check('F26 rename category', $s === 200, $s);
[$s, $r] = $admin->post('items', ['es' => 'Arvejas congeladas', 'en' => 'Frozen peas', 'unit' => 'kg', 'price' => 2500, 'categoryId' => $frozen]);
$peas = $r['id'];
[$s, $r] = $admin->delete("categories/$frozen");
check('F26 removing a category with items asks first', $s === 409 && str_contains($r['error'], '1 item'), [$s, $r]);
[$s] = $admin->delete("categories/$frozen", ['confirm' => true]);
[, $cat] = $chef->get('catalog');
check('F26 confirmed: category and its items are gone', $s === 200 && !in_array($frozen, array_column($cat['categories'], 'id'), true)
      && !in_array($peas, array_column($cat['items'], 'id'), true), $s);
[$s] = $admin->post("items/$peas/restore");
check('F25 an item in a deleted category cannot be restored alone', $s === 400, $s);
$admin->post("categories/$frozen/restore");
$admin->post("items/$peas/restore");
[, $cat] = $chef->get('catalog');
check('F26 category restore', in_array($frozen, array_column($cat['categories'], 'id'), true) && in_array($peas, array_column($cat['items'], 'id'), true));
$order = array_column($cat['categories'], 'id');
$newOrder = array_merge([$frozen], array_values(array_diff($order, [$frozen])));
[$s] = $admin->put('categories/order', ['order' => $newOrder]);
[, $cat] = $chef->get('catalog');
check('F26 reorder categories', $s === 200 && $cat['categories'][0]['id'] === $frozen, array_column($cat['categories'], 'es'));
$admin->put('categories/order', ['order' => $order]);

// Import (F28)
$rows = [
    ['ID' => '', 'Categoría (category)' => 'Verduras', 'Nombre (Spanish)' => 'Chirivía', 'Name (English)' => 'Parsnip',
     'Unidad (unit)' => 'kg', 'Precio (price)' => '1.500', 'Fuente (Market/Manual)' => 'Manual', 'Palabras clave (market keywords)' => ''],
    ['ID' => (string)$radish, 'Categoría (category)' => 'Verduras', 'Nombre (Spanish)' => 'Rabanito', 'Name (English)' => 'Radish',
     'Unidad (unit)' => 'bunch', 'Precio (price)' => '900', 'Fuente (Market/Manual)' => 'Manual', 'Palabras clave (market keywords)' => ''],
    ['Categoría (category)' => 'Verduras', 'Nombre (Spanish)' => 'Cebolla', 'Name (English)' => 'Onion (white)',
     'Unidad (unit)' => 'kg', 'Precio (price)' => '800', 'Fuente (Market/Manual)' => 'Market', 'Palabras clave (market keywords)' => 'cebolla'],
    ['Categoría (category)' => 'Verduras', 'Nombre (Spanish)' => 'Malo', 'Unidad (unit)' => 'bag', 'Precio (price)' => '1'],
    ['Categoría (category)' => 'Postres', 'Nombre (Spanish)' => 'Helado', 'Name (English)' => 'Ice cream', 'Unidad (unit)' => 'l', 'Precio (price)' => '4990'],
    ['Categoría (category)' => '', 'Nombre (Spanish)' => '', 'Unidad (unit)' => ''],
];
$count = (int)q('SELECT COUNT(*) FROM items')->fetchColumn();
[$s, $plan] = $admin->post('items/import/preview', ['rows' => $rows]);
$st = array_column($plan['rows'] ?? [], 'status');
check('F28 preview: new / changed / unchanged / error', $s === 200 && $st === ['new', 'changed', 'unchanged', 'error', 'new'], [$s, $st]);
check('F28 preview: Chilean price "1.500" read as 1500', ($plan['rows'][0]['fields']['price'] ?? null) === 1500, $plan['rows'][0] ?? null);
check('F28 preview: shows what changes', ($plan['rows'][1]['changes']['price'] ?? null) === ['from' => 800, 'to' => 900], $plan['rows'][1] ?? null);
check('F28 preview: explains errors', str_contains($plan['rows'][3]['error'] ?? '', 'Unknown unit'), $plan['rows'][3] ?? null);
check('F28 preview: new categories listed', $plan['newCategories'] === ['Postres'], $plan['newCategories']);
check('F28 preview writes nothing', (int)q('SELECT COUNT(*) FROM items')->fetchColumn() === $count);
[$s, $r] = $admin->post('items/import/commit', ['rows' => $rows]);
check('F28 import: 2 added, 1 updated, 1 error skipped', $s === 200 && $r['added'] === 2 && $r['updated'] === 1 && $r['errors'] === 1, [$s, $r]);
[, $cat] = $chef->get('catalog');
$names = array_column($cat['items'], 'es');
check('F28 imported items are on the order page', in_array('Chirivía', $names, true) && in_array('Helado', $names, true));
check('F28 new category created', in_array('Postres', array_column($cat['categories'], 'es'), true));
$r = q("SELECT action, user_id FROM item_changes WHERE item_id = ? ORDER BY id DESC LIMIT 1", [$radish])->fetch();
check('F30 imports are in the history', $r['action'] === 'import' && (int)$r['user_id'] === 1, $r);
[$s, $r] = $admin->post('items/import/commit', ['rows' => $rows]);
check('F28 importing the same file again changes nothing', $r['added'] === 0 && $r['updated'] === 0, $r);
