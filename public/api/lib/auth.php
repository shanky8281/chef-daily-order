<?php
/* Login, sessions, lockout, password change and e-mail reset (requirements F1–F7, design §5.1–5.2). */
declare(strict_types=1);

const SESSION_COOKIE   = 'cdo_session';
const SESSION_DAYS     = 30;
const MAX_FAILURES     = 5;
const LOCK_MINUTES     = 15;
const RESET_MINUTES    = 60;
const RESETS_PER_HOUR  = 3;
const MIN_PASSWORD     = 8;

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

function public_user(array $u): array
{
    return [
        'id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'],
        'role' => $u['role'], 'active' => (bool)$u['active'], 'lastLoginAt' => $u['last_login_at'],
    ];
}

/** The logged-in user, or null. Refreshes the session's last-seen time. */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (!is_string($token) || strlen($token) < 32) return null;
    $s = q('SELECT s.id AS sid, u.* FROM sessions s JOIN users u ON u.id = s.user_id
            WHERE s.token_hash = ? AND s.expires_at > ? AND u.active = 1', [token_hash($token), now()])->fetch();
    if (!$s) return null;
    // Sliding 30 days: every visit pushes the expiry forward.
    q('UPDATE sessions SET last_seen_at = ?, expires_at = ? WHERE id = ?', [now(), now(SESSION_DAYS * 86400), $s['sid']]);
    unset($s['sid']);
    return $user = $s;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) throw new ApiError(401, 'Please log in');
    return $u;
}

function require_admin(): array
{
    $u = require_user();
    if ($u['role'] !== 'admin') throw new ApiError(403, 'Admins only');
    return $u;
}

function set_session_cookie(string $token, int $expires): void
{
    setcookie(SESSION_COOKIE, $token, [
        'expires' => $expires, 'path' => '/', 'secure' => (bool)config('cookie_secure'),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function start_session(array $user): void
{
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO sessions (token_hash, user_id, created_at, expires_at, last_seen_at, user_agent) VALUES (?, ?, ?, ?, ?, ?)',
      [token_hash($token), $user['id'], now(), now(SESSION_DAYS * 86400), now(), text($_SERVER['HTTP_USER_AGENT'] ?? '', 255)]);
    set_session_cookie($token, time() + SESSION_DAYS * 86400);
}

function end_all_sessions(int $userId): void
{
    q('DELETE FROM sessions WHERE user_id = ?', [$userId]);
}

function check_password_rules(string $password): void
{
    if (mb_strlen($password) < MIN_PASSWORD) throw new ApiError(400, 'Password must have at least ' . MIN_PASSWORD . ' characters');
    if (strlen($password) > 200) throw new ApiError(400, 'Password is too long');
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]);
}

// ── Endpoints ─────────────────────────────────────────────────────────────────
function api_login(): array
{
    $in = body();
    $username = text($in['username'] ?? '', 40);
    $password = is_string($in['password'] ?? null) ? $in['password'] : '';
    if ($username === '' || $password === '') throw new ApiError(400, 'Enter username and password');
    $key = mb_strtolower($username);

    $a = q('SELECT failures, locked_until FROM login_attempts WHERE username = ?', [$key])->fetch();
    if ($a && $a['locked_until'] && $a['locked_until'] > now()) {
        throw new ApiError(429, 'Too many attempts. Try again in ' . LOCK_MINUTES . ' minutes.');
    }

    $user = q('SELECT * FROM users WHERE username = ?', [$username])->fetch();
    // Always run bcrypt so a wrong username takes as long as a wrong password.
    $ok = password_verify($password, $user['password_hash'] ?? '$2y$11$' . str_repeat('x', 53));
    if (!$user || !$ok || !$user['active']) {
        $failures = ($a && (!$a['locked_until'] || $a['locked_until'] <= now()) ? (int)$a['failures'] : 0) + 1;
        $locked = $failures >= MAX_FAILURES ? now(LOCK_MINUTES * 60) : null;
        q('INSERT INTO login_attempts (username, failures, locked_until, updated_at) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE failures = VALUES(failures), locked_until = VALUES(locked_until), updated_at = VALUES(updated_at)',
          [$key, $locked ? 0 : $failures, $locked, now()]);
        if ($locked) throw new ApiError(429, 'Too many attempts. Try again in ' . LOCK_MINUTES . ' minutes.');
        throw new ApiError(401, 'Wrong username or password');
    }

    q('DELETE FROM login_attempts WHERE username = ?', [$key]);
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 11])) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $user['id']]);
    }
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
    start_session($user);
    return ['user' => public_user($user), 'settings' => page_settings()];
}

function api_logout(): array
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (is_string($token) && $token !== '') q('DELETE FROM sessions WHERE token_hash = ?', [token_hash($token)]);
    set_session_cookie('', time() - 3600);
    return ['ok' => true];
}

/** Settings every logged-in page needs. */
function page_settings(): array
{
    return ['restaurant' => setting('restaurant'), 'whatsapp' => setting('whatsapp')];
}

function api_me(): array
{
    return ['user' => public_user(require_user()), 'settings' => page_settings()];
}

function api_change_password(): array
{
    $u = require_user();
    $in = body();
    if (!password_verify((string)($in['current'] ?? ''), $u['password_hash'])) throw new ApiError(400, 'Current password is wrong');
    $new = (string)($in['new'] ?? '');
    check_password_rules($new);
    q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($new), $u['id']]);
    end_all_sessions((int)$u['id']);
    start_session($u);   // stay logged in here, log out everywhere else
    return ['ok' => true];
}

/** Always answers the same, so it can't be used to find out which accounts exist. */
function api_forgot(): array
{
    $who = text(body()['who'] ?? '', 190);
    $answer = ['ok' => true, 'message' => 'If that account exists, we sent a link to its e-mail.'];
    if ($who === '') return $answer;
    $u = q('SELECT * FROM users WHERE (username = ? OR (email <> \'\' AND email = ?)) AND active = 1 LIMIT 1', [$who, $who])->fetch();
    if (!$u || $u['email'] === '') return $answer;
    $recent = (int)q('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?', [$u['id'], now(-3600)])->fetchColumn();
    if ($recent >= RESETS_PER_HOUR) return $answer;
    send_reset_email($u);
    return $answer;
}

function send_reset_email(array $u): void
{
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO password_resets (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)',
      [token_hash($token), $u['id'], now(), now(RESET_MINUTES * 60)]);
    $link = rtrim((string)config('base_url'), '/') . '/#reset=' . $token;
    $restaurant = setting('restaurant') ?: 'Daily Order';
    send_mail($u['email'], "$restaurant Daily Order — reset your password",
        "Hello {$u['username']},\n\nTo choose a new password, open this link within " . RESET_MINUTES . " minutes:\n\n$link\n\n" .
        "If you did not ask for this, you can ignore this e-mail; your password stays the same.\n");
}

function api_reset(): array
{
    $in = body();
    $token = is_string($in['token'] ?? null) ? $in['token'] : '';
    $new = (string)($in['password'] ?? '');
    $r = q('SELECT * FROM password_resets WHERE token_hash = ?', [token_hash($token)])->fetch();
    if (!$r || $r['used_at'] || $r['expires_at'] <= now()) throw new ApiError(400, 'This link is invalid or has expired. Ask for a new one.');
    check_password_rules($new);
    db()->beginTransaction();
    q('UPDATE password_resets SET used_at = ? WHERE id = ?', [now(), $r['id']]);
    q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($new), $r['user_id']]);
    end_all_sessions((int)$r['user_id']);
    db()->commit();
    $u = q('SELECT username FROM users WHERE id = ?', [$r['user_id']])->fetch();
    q('DELETE FROM login_attempts WHERE username = ?', [mb_strtolower($u['username'])]);
    return ['ok' => true, 'username' => $u['username']];
}

function send_mail(string $to, string $subject, string $body): void
{
    if ($log = config('mail_log')) {
        file_put_contents($log, json_encode(['to' => $to, 'subject' => $subject, 'body' => $body]) . "\n", FILE_APPEND);
        return;
    }
    $from = (string)config('mail_from');
    $headers = "From: $from\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    if (!mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . $from)) {
        error_log("Chef Daily Order: could not send e-mail to $to");
    }
}
