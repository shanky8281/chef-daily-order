<?php
/* Item list for the order page, and the admin item manager (F8–F10, F23–F31, design §2.5, §5.6). */
declare(strict_types=1);

function shown_price(array $it): int
{
    return $it['price_source'] === 'market' && $it['market_price'] !== null ? (int)$it['market_price'] : (int)$it['price'];
}

function item_out(array $it, bool $admin): array
{
    $out = [
        'id' => (int)$it['id'], 'categoryId' => (int)$it['category_id'],
        'es' => $it['name_es'], 'en' => $it['name_en'], 'unit' => $it['unit'],
        'price' => shown_price($it), 'source' => $it['price_source'],
    ];
    if ($it['price_source'] === 'market' && $it['market_prev'] && $it['market_price']) {
        $out['trend'] = (int)round(($it['market_price'] - $it['market_prev']) / $it['market_prev'] * 100);
    }
    if ($admin) {
        $out += [
            'manualPrice' => (int)$it['price'], 'marketPrice' => $it['market_price'] === null ? null : (int)$it['market_price'],
            'marketDate' => $it['market_date'], 'marketQuote' => $it['market_quote'], 'keywords' => $it['market_keywords'],
            'sort' => (int)$it['sort'], 'deleted' => $it['deleted_at'] !== null, 'updatedAt' => $it['updated_at'],
            'updatedBy' => $it['updated_by_name'] ?? null,
        ];
    }
    return $out;
}

function category_out(array $c): array
{
    return ['id' => (int)$c['id'], 'es' => $c['name_es'], 'en' => $c['name_en'], 'icon' => $c['icon'],
            'sort' => (int)$c['sort'], 'deleted' => $c['deleted_at'] !== null];
}

/** GET catalog — what the order page shows. Admins may ask for deleted ones too (?all=1). */
function api_catalog(): array
{
    $u = require_user();
    $all = $u['role'] === 'admin' && ($_GET['all'] ?? '') === '1';
    $cats = q('SELECT * FROM categories ' . ($all ? '' : 'WHERE deleted_at IS NULL ') . 'ORDER BY sort, id')->fetchAll();
    $items = q('SELECT i.*, u.username AS updated_by_name FROM items i
                JOIN categories c ON c.id = i.category_id LEFT JOIN users u ON u.id = i.updated_by '
               . ($all ? '' : 'WHERE i.deleted_at IS NULL AND c.deleted_at IS NULL ') . 'ORDER BY c.sort, i.sort, i.id')->fetchAll();
    $stamp = (string)q('SELECT MAX(updated_at) FROM items')->fetchColumn();
    return [
        'version' => substr(hash('sha256', $stamp . count($items) . json_encode($cats) . setting('market_updated')), 0, 12),
        'marketDate' => setting('market_date') ?: null,
        'units' => UNITS,
        'categories' => array_map('category_out', $cats),
        'items' => array_map(fn($i) => item_out($i, $u['role'] === 'admin'), $items),
    ];
}

function log_change(?int $itemId, ?int $categoryId, int $userId, string $action, ?array $before, ?array $after): void
{
    q('INSERT INTO item_changes (item_id, category_id, user_id, action, before_json, after_json, at) VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$itemId, $categoryId, $userId, $action,
       $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
       $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), now()]);
}

function find_item(int $id): array
{
    $it = q('SELECT * FROM items WHERE id = ?', [$id])->fetch();
    if (!$it) throw new ApiError(404, 'Item not found');
    return $it;
}

function find_category(int $id): array
{
    $c = q('SELECT * FROM categories WHERE id = ?', [$id])->fetch();
    if (!$c) throw new ApiError(404, 'Category not found');
    return $c;
}

/** Validate item fields from the edit form or an import row. */
function item_fields(array $in): array
{
    $unit = text($in['unit'] ?? '', 16);
    if (!in_array($unit, UNITS, true)) throw new ApiError(400, 'Unit must be one of: ' . implode(', ', UNITS));
    $source = ($in['source'] ?? 'manual') === 'market' ? 'market' : 'manual';
    $keywords = text($in['keywords'] ?? '', 255);
    if ($source === 'market' && $keywords === '') throw new ApiError(400, 'Market items need at least one market keyword');
    $cat = (int)($in['categoryId'] ?? 0);
    if (!q('SELECT id FROM categories WHERE id = ? AND deleted_at IS NULL', [$cat])->fetch()) throw new ApiError(400, 'Choose a category');
    return [
        'category_id' => $cat,
        'name_es' => need_text($in, 'es', 'Spanish name'),
        'name_en' => text($in['en'] ?? ''),
        'unit' => $unit,
        'price' => import_price(is_scalar($in['price'] ?? null) ? (string)$in['price'] : ''),   // "1.200" means 1200 in Chile
        'price_source' => $source,
        'market_keywords' => $keywords,
    ];
}

function api_item_create(): array
{
    $u = require_admin();
    $f = item_fields(body());
    $sort = (int)q('SELECT COALESCE(MAX(sort), 0) + 10 FROM items WHERE category_id = ?', [$f['category_id']])->fetchColumn();
    q('INSERT INTO items (category_id, name_es, name_en, unit, price, price_source, market_keywords, sort, updated_by, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [...array_values($f), $sort, $u['id'], now()]);
    $id = (int)db()->lastInsertId();
    log_change($id, $f['category_id'], (int)$u['id'], 'add', null, $f);
    return ['id' => $id];
}

function api_item_update(int $id): array
{
    $u = require_admin();
    $before = find_item($id);
    $f = item_fields(body());
    $sort = $f['category_id'] === (int)$before['category_id'] ? (int)$before['sort']
        : (int)q('SELECT COALESCE(MAX(sort), 0) + 10 FROM items WHERE category_id = ?', [$f['category_id']])->fetchColumn();
    q('UPDATE items SET category_id = ?, name_es = ?, name_en = ?, unit = ?, price = ?, price_source = ?, market_keywords = ?,
              sort = ?, updated_by = ?, updated_at = ? WHERE id = ?',
      [...array_values($f), $sort, $u['id'], now(), $id]);
    log_change($id, $f['category_id'], (int)$u['id'], 'edit', array_intersect_key($before, $f), $f);
    return ['ok' => true];
}

function api_item_delete(int $id): array
{
    $u = require_admin();
    $it = find_item($id);
    q('UPDATE items SET deleted_at = ?, updated_by = ?, updated_at = ? WHERE id = ?', [now(), $u['id'], now(), $id]);
    log_change($id, (int)$it['category_id'], (int)$u['id'], 'delete', null, null);
    return ['ok' => true];
}

function api_item_restore(int $id): array
{
    $u = require_admin();
    $it = find_item($id);
    if (q('SELECT deleted_at FROM categories WHERE id = ?', [$it['category_id']])->fetchColumn()) {
        throw new ApiError(400, 'Restore its category first, or move the item to another category');
    }
    q('UPDATE items SET deleted_at = NULL, updated_by = ?, updated_at = ? WHERE id = ?', [$u['id'], now(), $id]);
    log_change($id, (int)$it['category_id'], (int)$u['id'], 'restore', null, null);
    return ['ok' => true];
}

/** Move an item one place up or down inside its category (F27). */
function api_item_move(int $id): array
{
    $u = require_admin();
    $it = find_item($id);
    $up = (body()['direction'] ?? '') === 'up';
    $ids = q('SELECT id FROM items WHERE category_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$it['category_id']])->fetchAll(PDO::FETCH_COLUMN);
    $pos = array_search($id, array_map('intval', $ids), true);
    $to = $up ? $pos - 1 : $pos + 1;
    if ($pos === false || $to < 0 || $to >= count($ids)) return ['ok' => true];
    [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
    db()->beginTransaction();
    foreach ($ids as $i => $iid) q('UPDATE items SET sort = ? WHERE id = ?', [($i + 1) * 10, $iid]);
    q('UPDATE items SET updated_by = ?, updated_at = ? WHERE id = ?', [$u['id'], now(), $id]);
    db()->commit();
    log_change($id, (int)$it['category_id'], (int)$u['id'], 'move', null, ['direction' => $up ? 'up' : 'down']);
    return ['ok' => true];
}

function api_item_history(int $id): array
{
    require_admin();
    $rows = q('SELECT c.action, c.before_json, c.after_json, c.at, u.username FROM item_changes c
               LEFT JOIN users u ON u.id = c.user_id WHERE c.item_id = ? ORDER BY c.id DESC LIMIT 50', [$id])->fetchAll();
    return ['changes' => array_map(fn($r) => [
        'action' => $r['action'], 'at' => $r['at'], 'by' => $r['username'],
        'before' => $r['before_json'] ? json_decode($r['before_json'], true) : null,
        'after' => $r['after_json'] ? json_decode($r['after_json'], true) : null,
    ], $rows)];
}

// ── Categories (F26) ──────────────────────────────────────────────────────────
function category_fields(array $in): array
{
    return ['name_es' => need_text($in, 'es', 'Spanish name', 80), 'name_en' => text($in['en'] ?? '', 80),
            'icon' => text($in['icon'] ?? '', 16)];
}

function api_category_create(): array
{
    $u = require_admin();
    $f = category_fields(body());
    $sort = (int)q('SELECT COALESCE(MAX(sort), 0) + 10 FROM categories')->fetchColumn();
    q('INSERT INTO categories (name_es, name_en, icon, sort) VALUES (?, ?, ?, ?)', [...array_values($f), $sort]);
    $id = (int)db()->lastInsertId();
    log_change(null, $id, (int)$u['id'], 'cat-add', null, $f);
    return ['id' => $id];
}

function api_category_update(int $id): array
{
    $u = require_admin();
    $before = find_category($id);
    $f = category_fields(body());
    q('UPDATE categories SET name_es = ?, name_en = ?, icon = ? WHERE id = ?', [...array_values($f), $id]);
    touch_items_version();
    log_change(null, $id, (int)$u['id'], 'cat-edit', array_intersect_key($before, $f), $f);
    return ['ok' => true];
}

/** Remove a category. Refused while it still has items unless the admin confirmed (then its items go too). */
function api_category_delete(int $id): array
{
    $u = require_admin();
    find_category($id);
    $count = (int)q('SELECT COUNT(*) FROM items WHERE category_id = ? AND deleted_at IS NULL', [$id])->fetchColumn();
    if ($count > 0 && empty(body()['confirm'])) {
        throw new ApiError(409, "This category still has $count item(s). Delete them too?");
    }
    db()->beginTransaction();
    q('UPDATE items SET deleted_at = ?, updated_by = ?, updated_at = ? WHERE category_id = ? AND deleted_at IS NULL', [now(), $u['id'], now(), $id]);
    q('UPDATE categories SET deleted_at = ? WHERE id = ?', [now(), $id]);
    db()->commit();
    log_change(null, $id, (int)$u['id'], 'cat-delete', null, ['items' => $count]);
    return ['ok' => true];
}

function api_category_restore(int $id): array
{
    $u = require_admin();
    find_category($id);
    q('UPDATE categories SET deleted_at = NULL WHERE id = ?', [$id]);
    touch_items_version();
    log_change(null, $id, (int)$u['id'], 'cat-restore', null, null);
    return ['ok' => true];
}

/** Save a new category order: body {order: [id, id, …]}. */
function api_category_order(): array
{
    $u = require_admin();
    $ids = array_map('intval', (array)(body()['order'] ?? []));
    db()->beginTransaction();
    foreach ($ids as $i => $id) q('UPDATE categories SET sort = ? WHERE id = ?', [($i + 1) * 10, $id]);
    db()->commit();
    touch_items_version();
    log_change(null, null, (int)$u['id'], 'cat-order', null, ['order' => $ids]);
    return ['ok' => true];
}

function touch_items_version(): void
{
    set_setting('market_updated', now() . '#' . bin2hex(random_bytes(3)));
}

// ── Import (F28, design §5.6) ─────────────────────────────────────────────────
/** Map spreadsheet headers (Spanish or English, any case) to fields. */
function import_column(string $header): ?string
{
    $h = fold($header);
    if ($h === 'id') return 'id';
    $map = [
        'category' => ['categor'], 'keywords' => ['clave', 'keyword'],
        'source' => ['fuente', 'source'], 'price' => ['precio', 'price'], 'unit' => ['unidad', 'unit'],
        'es' => ['nombre', 'spanish', 'espanol'], 'en' => ['english', 'ingles', 'name'],
    ];
    foreach ($map as $field => $words) {
        foreach ($words as $w) if (str_contains($h, $w)) return $field;
    }
    return null;
}

/** "1.300", "$1.300", "1300", 1299.6 → whole pesos. */
function import_price(string $p): int
{
    $p = trim(str_replace(['$', ' ', 'CLP'], '', $p));
    if ($p === '') return 0;
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $p)) $p = str_replace(['.', ','], ['', '.'], $p);   // Chilean 1.300,5
    elseif (preg_match('/^\d+,\d+$/', $p)) $p = str_replace(',', '.', $p);
    if (!is_numeric($p)) throw new ApiError(400, "Price \"$p\" is not a number");
    if ($p < 0 || $p > 1e9) throw new ApiError(400, 'Price is out of range');
    return (int)round((float)$p);
}

/** Work out what an import would do. Nothing is written. */
function import_plan(array $rows): array
{
    $cats = q('SELECT * FROM categories WHERE deleted_at IS NULL')->fetchAll();
    $items = q('SELECT * FROM items WHERE deleted_at IS NULL')->fetchAll();
    $byId = array_column($items, null, 'id');
    $plan = [];
    $newCats = [];
    foreach (array_slice($rows, 0, 2000) as $n => $raw) {
        if (!is_array($raw)) continue;
        $r = [];
        foreach ($raw as $h => $v) if (($f = import_column((string)$h)) && !isset($r[$f])) $r[$f] = is_scalar($v) ? trim((string)$v) : '';
        $line = $n + 2;   // header is row 1
        if (implode('', $r) === '') continue;
        $entry = ['row' => $line, 'es' => $r['es'] ?? '', 'en' => $r['en'] ?? '', 'category' => $r['category'] ?? ''];
        try {
            $catName = text($r['category'] ?? '', 80);
            if ($catName === '') throw new ApiError(400, 'Category is required');
            $cat = null;
            foreach ($cats as $c) {
                if (fold($c['name_es']) === fold($catName) || fold($c['name_en']) === fold($catName)
                    || fold($c['name_es'] . ' / ' . $c['name_en']) === fold($catName)) { $cat = $c; break; }
            }
            $unit = mb_strtolower(text($r['unit'] ?? '', 16));
            $unit = ['un' => 'unit', 'unidad' => 'unit', 'units' => 'unit', 'lt' => 'l', 'litro' => 'l', 'kilo' => 'kg'][$unit] ?? $unit;
            $source = in_array(fold($r['source'] ?? ''), ['market', 'mercado', 'm'], true) ? 'market' : 'manual';
            $fields = [
                'es' => text($r['es'] ?? ''), 'en' => text($r['en'] ?? ''), 'unit' => $unit,
                'price' => import_price((string)($r['price'] ?? '')),
                'source' => $source, 'keywords' => text($r['keywords'] ?? '', 255),
            ];
            if ($fields['es'] === '') throw new ApiError(400, 'Spanish name is required');
            if (!in_array($unit, UNITS, true)) throw new ApiError(400, "Unknown unit \"$unit\"");
            if ($source === 'market' && $fields['keywords'] === '') throw new ApiError(400, 'Market items need keywords');

            $match = null;
            if (!empty($r['id'])) {
                $match = $byId[(int)$r['id']] ?? null;
                if (!$match) throw new ApiError(400, "No item with ID {$r['id']}");
            } elseif ($cat) {
                foreach ($items as $it) {
                    if ((int)$it['category_id'] === (int)$cat['id'] && fold($it['name_es']) === fold($fields['es'])) { $match = $it; break; }
                }
            }
            $entry += ['fields' => $fields, 'categoryId' => $cat ? (int)$cat['id'] : null, 'newCategory' => $cat ? null : $catName];
            if (!$cat) $newCats[fold($catName)] = $catName;
            if (!$match) {
                $entry['status'] = 'new';
            } else {
                $old = ['es' => $match['name_es'], 'en' => $match['name_en'], 'unit' => $match['unit'], 'price' => (int)$match['price'],
                        'source' => $match['price_source'], 'keywords' => $match['market_keywords']];
                $changes = [];
                foreach ($old as $k => $v) if ((string)$v !== (string)$fields[$k]) $changes[$k] = ['from' => $v, 'to' => $fields[$k]];
                if ($cat && (int)$match['category_id'] !== (int)$cat['id']) $changes['category'] = ['from' => (int)$match['category_id'], 'to' => $catName];
                $entry += ['id' => (int)$match['id'], 'status' => $changes ? 'changed' : 'unchanged', 'changes' => $changes];
            }
        } catch (ApiError $e) {
            $entry += ['status' => 'error', 'error' => $e->getMessage()];
        }
        $plan[] = $entry;
    }
    $count = fn($s) => count(array_filter($plan, fn($p) => $p['status'] === $s));
    return ['rows' => $plan, 'newCategories' => array_values($newCats),
            'summary' => ['new' => $count('new'), 'changed' => $count('changed'), 'unchanged' => $count('unchanged'), 'errors' => $count('error')]];
}

function api_import_preview(): array
{
    require_admin();
    return import_plan((array)(body()['rows'] ?? []));
}

function api_import_commit(): array
{
    $u = require_admin();
    $plan = import_plan((array)(body()['rows'] ?? []));
    db()->beginTransaction();
    $catIds = [];
    foreach ($plan['newCategories'] as $name) {
        $sort = (int)q('SELECT COALESCE(MAX(sort), 0) + 10 FROM categories')->fetchColumn();
        q('INSERT INTO categories (name_es, name_en, icon, sort) VALUES (?, ?, ?, ?)', [$name, '', '📦', $sort]);
        $catIds[fold($name)] = (int)db()->lastInsertId();
        log_change(null, $catIds[fold($name)], (int)$u['id'], 'cat-add', null, ['name_es' => $name, 'via' => 'import']);
    }
    $done = ['new' => 0, 'changed' => 0];
    foreach ($plan['rows'] as $p) {
        if (!in_array($p['status'], ['new', 'changed'], true)) continue;
        $f = $p['fields'];
        $cat = $p['categoryId'] ?? $catIds[fold($p['newCategory'])];
        $vals = [$cat, $f['es'], $f['en'], $f['unit'], $f['price'], $f['source'], $f['keywords']];
        if ($p['status'] === 'new') {
            $sort = (int)q('SELECT COALESCE(MAX(sort), 0) + 10 FROM items WHERE category_id = ?', [$cat])->fetchColumn();
            q('INSERT INTO items (category_id, name_es, name_en, unit, price, price_source, market_keywords, sort, updated_by, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$vals, $sort, $u['id'], now()]);
            log_change((int)db()->lastInsertId(), $cat, (int)$u['id'], 'import', null, $f);
        } else {
            q('UPDATE items SET category_id = ?, name_es = ?, name_en = ?, unit = ?, price = ?, price_source = ?, market_keywords = ?,
                      updated_by = ?, updated_at = ? WHERE id = ?', [...$vals, $u['id'], now(), $p['id']]);
            log_change($p['id'], $cat, (int)$u['id'], 'import', $p['changes'], $f);
        }
        $done[$p['status']]++;
    }
    db()->commit();
    return ['ok' => true, 'added' => $done['new'], 'updated' => $done['changed'], 'errors' => $plan['summary']['errors']];
}
