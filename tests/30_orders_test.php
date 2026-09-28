<?php
/* Orders: saving (incl. retries from the phone's outbox), past orders and who may see them, settings (F17–F22, F19). */

$admin = Client::login('Shankar', 'admin-pass-1');
$ravi = Client::login('Ravi', 'ravi-pass-3');
$maria = Client::login('Maria', 'maria-pass-1');

function sample_order(string $uid, array $extra = []): array
{
    return $extra + [
        'uid' => $uid, 'orderNo' => '260928-RA-1', 'date' => date('Y-m-d'), 'time' => '09:12',
        'priceNote' => 'Market prices of 25 Sept 2026 (ODEPA)', 'report' => "🛒 *MIRCHI — DAILY ORDER*\n…",
        'items' => [
            ['itemId' => 1, 'category' => 'Verduras / Vegetables', 'es' => 'Cebolla morada', 'en' => 'Onion (red)', 'unit' => 'kg', 'qty' => 2.5, 'price' => 950],
            ['itemId' => 3, 'category' => 'Verduras / Vegetables', 'es' => 'Tomate', 'en' => 'Tomato', 'unit' => 'kg', 'qty' => 3, 'price' => 1118],
            ['itemId' => 99999, 'category' => 'Old', 'es' => 'Borrado', 'en' => 'Removed item', 'unit' => 'kg', 'qty' => 1, 'price' => 100],
            ['itemId' => 4, 'category' => 'Verduras / Vegetables', 'es' => 'Papa', 'en' => 'Potato', 'unit' => 'kg', 'qty' => 0, 'price' => 930],
        ],
    ];
}

[$s, $r] = $ravi->post('orders', sample_order('11111111-aaaa-4bbb-8ccc-000000000001'));
check('F20 chef sends an order', $s === 200 && $r['success'] === true && $r['duplicate'] === false, [$s, $r]);
$o1 = $r['id'];
[$s, $r] = $ravi->post('orders', sample_order('11111111-aaaa-4bbb-8ccc-000000000001'));
check('F17 same order uploaded twice is saved once', $s === 200 && $r['success'] === true && $r['duplicate'] === true && $r['id'] === $o1, [$s, $r]);
check('F17 only one copy in the database', (int)q("SELECT COUNT(*) FROM orders WHERE uid = '11111111-aaaa-4bbb-8ccc-000000000001'")->fetchColumn() === 1);
[$s] = $maria->post('orders', sample_order('11111111-aaaa-4bbb-8ccc-000000000001'));
check('F17 another user cannot reuse an order id', $s === 409, $s);

$row = q('SELECT * FROM orders WHERE id = ?', [$o1])->fetch();
check('F20 order stores who sent it', (int)$row['user_id'] === (int)q("SELECT id FROM users WHERE username = 'Ravi'")->fetchColumn());
check('F20 total recomputed by the server, zero lines dropped', (int)$row['total'] === 2375 + 3354 + 100 && (int)$row['item_count'] === 3, $row);
$lines = q('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$o1])->fetchAll();
check('F20 item names are kept as sent (history survives later edits)', $lines[0]['name_es'] === 'Cebolla morada' && (float)$lines[0]['qty'] === 2.5);
check('F20 unknown item ids are kept as text only', $lines[2]['item_id'] === null && $lines[2]['name_es'] === 'Borrado', $lines[2]);

foreach ([
    'no items'   => sample_order('11111111-aaaa-4bbb-8ccc-000000000009', ['items' => []]),
    'bad date'   => sample_order('11111111-aaaa-4bbb-8ccc-000000000009', ['date' => '28/09/2026']),
    'bad time'   => sample_order('11111111-aaaa-4bbb-8ccc-000000000009', ['time' => '9am']),
    'bad id'     => sample_order('x'),
    'bad qty'    => sample_order('11111111-aaaa-4bbb-8ccc-000000000009', ['items' => [['es' => 'A', 'qty' => 'lots', 'price' => 1]]]),
    'all zero'   => sample_order('11111111-aaaa-4bbb-8ccc-000000000009', ['items' => [['es' => 'A', 'qty' => 0, 'price' => 1]]]),
] as $name => $body) {
    [$s] = $ravi->post('orders', $body);
    check("F20 order refused: $name", $s === 400, $s);
}
[$s] = (new Client())->post('orders', sample_order('11111111-aaaa-4bbb-8ccc-000000000010'));
check('F17 not logged in → 401 (the phone keeps the order and asks to log in)', $s === 401, $s);

// Past orders (F22)
[$s, $r] = $maria->post('orders', sample_order('22222222-aaaa-4bbb-8ccc-000000000001', ['orderNo' => '260928-MA-1']));
$o2 = $r['id'];
[$s, $r] = $ravi->get('orders?mine=1');
check('F22 chef sees own orders', $s === 200 && array_column($r['orders'], 'id') === [$o1], $r);
[$s, $r] = $ravi->get('orders');
check('F22 chef cannot list other chefs\' orders', $s === 200 && array_column($r['orders'], 'id') === [$o1], $r);
[$s, $r] = $ravi->get("orders/$o2");
check('F22 chef cannot open another chef\'s order', $s === 404, $s);
[$s, $r] = $ravi->get("orders/$o1");
check('F22 chef opens own order with report and lines', $s === 200 && $r['report'] !== '' && count($r['lines']) === 3 && $r['chef'] === 'Ravi', $r);
[$s, $r] = $admin->get('orders');
check('F22 admin sees everyone\'s orders, newest first', $s === 200 && array_column($r['orders'], 'id') === [$o2, $o1], $r);
[$s, $r] = $admin->get('orders?user=' . q("SELECT id FROM users WHERE username = 'Maria'")->fetchColumn());
check('F22 admin filters by chef', array_column($r['orders'], 'id') === [$o2], $r);
[$s, $r] = $admin->get("orders/$o1");
check('F22 admin opens any order', $s === 200 && $r['chef'] === 'Ravi', $s);
for ($i = 3; $i <= 25; $i++) $ravi->post('orders', sample_order(sprintf('33333333-aaaa-4bbb-8ccc-%012d', $i)));
[$s, $p1] = $ravi->get('orders?mine=1&limit=20');
[$s, $p2] = $ravi->get('orders?mine=1&limit=20&before=' . end($p1['orders'])['id']);
check('D7 past orders are paged ("Load more")', count($p1['orders']) === 20 && $p1['more'] === true && count($p2['orders']) === 4 && $p2['more'] === false,
      [count($p1['orders']), $p1['more'], count($p2['orders']), $p2['more']]);
q("UPDATE orders SET order_date = ? WHERE id = ?", [date('Y-m-d', strtotime('-90 days')), $o1]);
[$s, $r] = $ravi->get('orders?mine=1&limit=100');
check('D7 list shows the last 60 days', !in_array($o1, array_column($r['orders'], 'id'), true));
q("UPDATE orders SET order_date = ? WHERE id = ?", [date('Y-m-d'), $o1]);

// Settings (F19, F21)
[$s, $r] = $admin->put('settings', ['restaurant' => 'Mirchi', 'whatsapp' => '+56 9 1234 5678', 'sheetId' => 'bad id', 'sheetTab' => 'Orders']);
check('F21 wrong sheet id refused', $s === 400, [$s, $r]);
[$s] = $admin->put('settings', ['restaurant' => 'Mirchi', 'whatsapp' => '+56 9 1234 5678', 'sheetId' => '1LSLSQgAOYBJdhAkgYRG8xZFo6bi4Gpg-po1u7MKKaP0', 'sheetTab' => 'Orders']);
[$s2, $r] = $ravi->get('auth/me');
check('F19 owner WhatsApp number reaches the chef page', $s === 200 && $r['settings']['whatsapp'] === '+56 9 1234 5678', $r);
[$s, $r] = $admin->get('settings');
check('F21 settings show how many lines wait for the sheet', $s === 200 && $r['sync']['pending'] > 0 && $r['sheetTab'] === 'Orders', $r['sync'] ?? $r);
