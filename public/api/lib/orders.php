<?php
/* Saving orders (online or from the phone's outbox) and past orders (F17–F22, design §5.3). */
declare(strict_types=1);

const MAX_ORDER_LINES = 500;

/** POST orders — idempotent: the same uid twice is stored once. */
function api_order_save(): array
{
    $u = require_user();
    $in = body();
    $uid = text($in['uid'] ?? '', 64);
    if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uid)) throw new ApiError(400, 'Order id is missing');

    $existing = q('SELECT id, user_id FROM orders WHERE uid = ?', [$uid])->fetch();
    if ($existing) {
        if ((int)$existing['user_id'] !== (int)$u['id']) throw new ApiError(409, 'Order id already used');
        return ['success' => true, 'id' => (int)$existing['id'], 'duplicate' => true];
    }

    $date = text($in['date'] ?? '', 10);
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) throw new ApiError(400, 'Date must be YYYY-MM-DD');
    $time = text($in['time'] ?? '', 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) throw new ApiError(400, 'Time must be HH:MM');

    $lines = $in['items'] ?? null;
    if (!is_array($lines) || !$lines) throw new ApiError(400, 'The order has no items');
    if (count($lines) > MAX_ORDER_LINES) throw new ApiError(400, 'Too many items');
    $known = array_map('intval', q('SELECT id FROM items')->fetchAll(PDO::FETCH_COLUMN));
    $rows = [];
    foreach ($lines as $l) {
        if (!is_array($l)) throw new ApiError(400, 'Bad item');
        $qty = number($l['qty'] ?? null, 'Quantity', 0, 1e6);
        if ($qty <= 0) continue;
        $price = (int)round(number($l['price'] ?? 0, 'Price'));
        $itemId = (int)($l['itemId'] ?? 0);
        $rows[] = [
            'item_id' => in_array($itemId, $known, true) ? $itemId : null,
            'category_name' => text($l['category'] ?? '', 170),
            'name_es' => text($l['es'] ?? ''), 'name_en' => text($l['en'] ?? ''),
            'qty' => round($qty, 3), 'unit' => text($l['unit'] ?? '', 16),
            'price' => $price, 'line_total' => (int)round($qty * $price),
        ];
    }
    if (!$rows) throw new ApiError(400, 'The order has no items');
    $total = array_sum(array_column($rows, 'line_total'));

    db()->beginTransaction();
    try {
        q('INSERT INTO orders (uid, order_no, user_id, order_date, order_time, total, item_count, price_note, report_text, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$uid, text($in['orderNo'] ?? '', 32), $u['id'], $date, $time, $total, count($rows),
           text($in['priceNote'] ?? '', 200), mb_substr((string)($in['report'] ?? ''), 0, 20000), now()]);
        $orderId = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT INTO order_items (order_id, item_id, category_name, name_es, name_en, qty, unit, price, line_total)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $r) $ins->execute([$orderId, ...array_values($r)]);
        db()->commit();
    } catch (PDOException $e) {
        db()->rollBack();
        // The same order arriving twice at the same moment: the other copy won.
        $again = q('SELECT id FROM orders WHERE uid = ? AND user_id = ?', [$uid, $u['id']])->fetchColumn();
        if ($again) return ['success' => true, 'id' => (int)$again, 'duplicate' => true];
        throw $e;
    }
    return ['success' => true, 'id' => $orderId, 'duplicate' => false];
}

/** GET orders — chefs get their own; admins get everyone's (or one chef's with ?user=). */
function api_orders_list(): array
{
    $u = require_user();
    $where = ['o.order_date >= ?'];
    $args = [date('Y-m-d', strtotime('-' . max(1, min(3650, (int)($_GET['days'] ?? 60))) . ' days'))];
    if ($u['role'] !== 'admin' || ($_GET['mine'] ?? '') === '1') {
        $where[] = 'o.user_id = ?';
        $args[] = $u['id'];
    } elseif (!empty($_GET['user'])) {
        $where[] = 'o.user_id = ?';
        $args[] = (int)$_GET['user'];
    }
    if (!empty($_GET['before'])) {
        $where[] = 'o.id < ?';
        $args[] = (int)$_GET['before'];
    }
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $rows = q('SELECT o.id, o.uid, o.order_no, o.order_date, o.order_time, o.total, o.item_count, u.username
               FROM orders o JOIN users u ON u.id = o.user_id WHERE ' . implode(' AND ', $where) .
              " ORDER BY o.id DESC LIMIT " . ($limit + 1), $args)->fetchAll();
    $more = count($rows) > $limit;
    return [
        'orders' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'uid' => $r['uid'], 'orderNo' => $r['order_no'], 'date' => $r['order_date'],
            'time' => $r['order_time'], 'total' => (int)$r['total'], 'items' => (int)$r['item_count'], 'chef' => $r['username'],
        ], array_slice($rows, 0, $limit)),
        'more' => $more,
    ];
}

/** GET orders/{id} — the order's owner or an admin. Others get 404, not 403. */
function api_order_get(int $id): array
{
    $u = require_user();
    $o = q('SELECT o.*, u.username FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?', [$id])->fetch();
    if (!$o || ($u['role'] !== 'admin' && (int)$o['user_id'] !== (int)$u['id'])) throw new ApiError(404, 'Order not found');
    $lines = q('SELECT item_id, category_name, name_es, name_en, qty, unit, price, line_total, synced_at
                FROM order_items WHERE order_id = ? ORDER BY id', [$id])->fetchAll();
    return [
        'id' => (int)$o['id'], 'uid' => $o['uid'], 'orderNo' => $o['order_no'], 'date' => $o['order_date'],
        'time' => $o['order_time'], 'total' => (int)$o['total'], 'chef' => $o['username'], 'report' => $o['report_text'],
        'inSheet' => !array_filter($lines, fn($l) => $l['synced_at'] === null),
        'lines' => array_map(fn($l) => [
            'itemId' => $l['item_id'] === null ? null : (int)$l['item_id'], 'category' => $l['category_name'],
            'es' => $l['name_es'], 'en' => $l['name_en'], 'qty' => (float)$l['qty'], 'unit' => $l['unit'],
            'price' => (int)$l['price'], 'lineTotal' => (int)$l['line_total'],
        ], $lines),
    ];
}
