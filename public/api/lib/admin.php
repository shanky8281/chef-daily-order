<?php
/* Admin: users (F2, F3, design §2.6) and settings (F19, F21, design §2.7). */
declare(strict_types=1);

function api_users_list(): array
{
    require_admin();
    $rows = q('SELECT u.*, a.locked_until FROM users u LEFT JOIN login_attempts a ON a.username = LOWER(u.username) ORDER BY u.username')->fetchAll();
    return ['users' => array_map(fn($u) => public_user($u) + [
        'locked' => $u['locked_until'] !== null && $u['locked_until'] > now(),
    ], $rows)];
}

function user_fields(array $in): array
{
    $username = need_text($in, 'username', 'Username', 40);
    if (!preg_match('/^[\p{L}0-9._-]{2,40}$/u', $username)) throw new ApiError(400, 'Username: 2–40 letters, digits, dot, dash or underscore');
    $email = text($in['email'] ?? '', 190);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError(400, 'E-mail address is not valid');
    $role = ($in['role'] ?? 'chef') === 'admin' ? 'admin' : 'chef';
    return ['username' => $username, 'email' => $email, 'role' => $role, 'active' => !isset($in['active']) || !empty($in['active']) ? 1 : 0];
}

function api_user_create(): array
{
    require_admin();
    $in = body();
    $f = user_fields($in);
    $password = (string)($in['password'] ?? '');
    if ($password === '' && $f['email'] === '') throw new ApiError(400, 'Give a temporary password, or an e-mail so they can set one');
    if (q('SELECT id FROM users WHERE username = ?', [$f['username']])->fetch()) throw new ApiError(409, 'That username is taken');
    if ($password !== '') check_password_rules($password);
    // Without a password the account gets a random one and a "set your password" e-mail.
    q('INSERT INTO users (username, email, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, ?, ?)',
      [$f['username'], $f['email'], hash_password($password !== '' ? $password : bin2hex(random_bytes(24))), $f['role'], $f['active'], now()]);
    $id = (int)db()->lastInsertId();
    if ($password === '') send_reset_email(q('SELECT * FROM users WHERE id = ?', [$id])->fetch());
    return ['id' => $id, 'emailSent' => $password === ''];
}

function api_user_update(int $id): array
{
    $me = require_admin();
    $before = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    if (!$before) throw new ApiError(404, 'User not found');
    $f = user_fields(body());
    if ($id === (int)$me['id'] && ($f['role'] !== 'admin' || !$f['active'])) {
        throw new ApiError(400, 'You cannot remove your own admin rights or deactivate yourself');
    }
    if (q('SELECT id FROM users WHERE username = ? AND id <> ?', [$f['username'], $id])->fetch()) throw new ApiError(409, 'That username is taken');
    q('UPDATE users SET username = ?, email = ?, role = ?, active = ? WHERE id = ?', [...array_values($f), $id]);
    if (!$f['active'] && $before['active']) end_all_sessions($id);
    if ($f['username'] !== $before['username']) q('DELETE FROM login_attempts WHERE username = ?', [mb_strtolower($before['username'])]);
    return ['ok' => true];
}

/** Admin reset: either set a temporary password or e-mail a reset link. Always logs the user out everywhere. */
function api_user_reset(int $id): array
{
    require_admin();
    $u = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    if (!$u) throw new ApiError(404, 'User not found');
    $password = (string)(body()['password'] ?? '');
    if ($password !== '') {
        check_password_rules($password);
        q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $id]);
    } else {
        if ($u['email'] === '') throw new ApiError(400, 'This user has no e-mail. Set a temporary password instead.');
        send_reset_email($u);
    }
    end_all_sessions($id);
    q('DELETE FROM login_attempts WHERE username = ?', [mb_strtolower($u['username'])]);
    return ['ok' => true, 'emailSent' => $password === ''];
}

// ── Settings ──────────────────────────────────────────────────────────────────
function api_settings_get(): array
{
    require_admin();
    return [
        'restaurant' => setting('restaurant'), 'whatsapp' => setting('whatsapp'),
        'sheetId' => setting('sheet_id'), 'sheetTab' => setting('sheet_tab'),
        'marketDate' => setting('market_date'),
        'sync' => ['pending' => pending_rows(), 'lastError' => setting('sheet_last_error')],
    ];
}

function api_settings_save(): array
{
    require_admin();
    $in = body();
    $whatsapp = preg_replace('/[^\d+ ]/', '', text($in['whatsapp'] ?? '', 30));
    $sheetId = text($in['sheetId'] ?? '', 100);
    if ($sheetId !== '' && !preg_match('/^[A-Za-z0-9_-]{20,100}$/', $sheetId)) throw new ApiError(400, 'Google Sheet ID looks wrong');
    set_setting('restaurant', need_text($in, 'restaurant', 'Restaurant name', 60));
    set_setting('whatsapp', $whatsapp);
    set_setting('sheet_id', $sheetId);
    set_setting('sheet_tab', text($in['sheetTab'] ?? '', 60) ?: 'Orders');
    return ['ok' => true];
}
