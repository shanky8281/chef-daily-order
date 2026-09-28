<?php
/* Chef Daily Order API — single entry point (design §4). Every URL under /api/ lands here. */
declare(strict_types=1);

foreach (['core', 'auth', 'catalog', 'orders', 'admin', 'sheets'] as $lib) require __DIR__ . "/lib/$lib.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** [method, path pattern, handler, runs after the reply] */
const ROUTES = [
    ['POST',   'auth/login',                    'api_login'],
    ['POST',   'auth/logout',                   'api_logout'],
    ['GET',    'auth/me',                       'api_me'],
    ['POST',   'auth/password',                 'api_change_password'],
    ['POST',   'auth/forgot',                   'api_forgot'],
    ['POST',   'auth/reset',                    'api_reset'],
    ['GET',    'catalog',                       'api_catalog'],
    ['POST',   'orders',                        'api_order_save', 'sync_after_reply'],
    ['GET',    'orders',                        'api_orders_list'],
    ['GET',    'orders/(\d+)',                  'api_order_get'],
    ['POST',   'items',                         'api_item_create'],
    ['PUT',    'items/(\d+)',                   'api_item_update'],
    ['DELETE', 'items/(\d+)',                   'api_item_delete'],
    ['POST',   'items/(\d+)/restore',           'api_item_restore'],
    ['POST',   'items/(\d+)/move',              'api_item_move'],
    ['GET',    'items/(\d+)/history',           'api_item_history'],
    ['POST',   'items/import/preview',          'api_import_preview'],
    ['POST',   'items/import/commit',           'api_import_commit'],
    ['POST',   'categories',                    'api_category_create'],
    ['PUT',    'categories/order',              'api_category_order'],
    ['PUT',    'categories/(\d+)',              'api_category_update'],
    ['DELETE', 'categories/(\d+)',              'api_category_delete'],
    ['POST',   'categories/(\d+)/restore',      'api_category_restore'],
    ['GET',    'users',                         'api_users_list'],
    ['POST',   'users',                         'api_user_create'],
    ['PUT',    'users/(\d+)',                   'api_user_update'],
    ['POST',   'users/(\d+)/reset',             'api_user_reset'],
    ['GET',    'settings',                      'api_settings_get'],
    ['PUT',    'settings',                      'api_settings_save'],
    ['POST',   'sync/sheet',                    'api_sync_now'],
];

function reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
}

/** After an order is saved: answer the phone first, then copy to the Google Sheet. */
function sync_after_reply(): void
{
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else flush();
    try {
        sync_to_sheet();
    } catch (Throwable $e) {
        error_log('Chef Daily Order: sheet sync failed: ' . $e->getMessage());
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$path = trim(preg_replace('#^.*?/api/#', '', $path) ?? '', '/');

try {
    // CSRF guard (design §7): changes must come from our page, which always sends this header.
    if ($method !== 'GET' && ($_SERVER['HTTP_X_CDO'] ?? '') !== '1') throw new ApiError(403, 'Missing request header');
    $after = null;
    foreach (ROUTES as $r) {
        if ($r[0] === $method && preg_match('#^' . $r[1] . '$#', $path, $m)) {
            $args = array_map('intval', array_slice($m, 1));
            $result = $r[2](...$args);
            $after = $r[3] ?? null;
            reply(200, $result);
            break;
        }
    }
    if (!isset($result)) throw new ApiError(404, 'Not found');
    if ($after && !empty($result['success']) && empty($result['duplicate'])) $after();
} catch (ApiError $e) {
    if (db_in_transaction()) db()->rollBack();
    reply($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if (db_in_transaction()) db()->rollBack();
    error_log('Chef Daily Order API error: ' . $e);
    reply(500, ['error' => 'Something went wrong on the server. Please try again.']);
}

function db_in_transaction(): bool
{
    try {
        return db()->inTransaction();
    } catch (Throwable) {
        return false;
    }
}
