<?php
/* Login, sessions, lockout, users, password change and reset (F1–F7, F2–F3, N6). */

$anon = new Client();
[$s] = $anon->get('auth/me');
check('F1 not logged in → 401', $s === 401, $s);
[$s] = $anon->get('catalog');
check('F1 item list needs login', $s === 401, $s);
[$s, $r] = $anon->post('auth/login', ['username' => 'Shankar', 'password' => 'wrong-password']);
check('F1 wrong password → generic message', $s === 401 && $r['error'] === 'Wrong username or password', [$s, $r]);
[$s, $r] = $anon->post('auth/login', ['username' => 'nobody', 'password' => 'whatever1']);
check('F1 unknown user → same message', $s === 401 && $r['error'] === 'Wrong username or password', [$s, $r]);
[$s] = $anon->call('POST', 'auth/login', ['username' => 'Shankar', 'password' => 'admin-pass-1'], false);
check('N6 change without X-CDO header is refused', $s === 403, $s);

config();   // same timezone as the server for the time checks below
$admin = Client::login('Shankar', 'admin-pass-1');
[$s, $r] = $admin->get('auth/me');
check('F1 admin logged in', $s === 200 && $r['user']['role'] === 'admin' && $r['user']['username'] === 'Shankar', $r);

// Users (F2, F3)
[$s, $r] = $admin->post('users', ['username' => 'Ravi', 'email' => 'ravi@example.com', 'role' => 'chef', 'password' => 'ravi-pass-1']);
check('F2 admin adds a chef with a temporary password', $s === 200 && $r['emailSent'] === false, [$s, $r]);
$raviId = $r['id'];
$mails = mail_count();
[$s, $r] = $admin->post('users', ['username' => 'Maria', 'email' => 'maria@example.com', 'role' => 'chef']);
check('F2 chef without password gets a "set your password" e-mail', $s === 200 && $r['emailSent'] === true && mail_count() === $mails + 1, [$s, $r]);
$mariaId = $r['id'];
[$s] = $admin->post('users', ['username' => 'Ravi', 'password' => 'another-1']);
check('F2 duplicate username refused', $s === 409, $s);
[$s] = $admin->post('users', ['username' => 'x y', 'password' => 'another-1']);
check('F2 bad username refused', $s === 400, $s);
[$s] = $admin->post('users', ['username' => 'NoPass']);
check('F2 needs a password or an e-mail', $s === 400, $s);
[$s] = $admin->post('users', ['username' => 'Short', 'password' => 'short']);
check('D5 password shorter than 8 refused', $s === 400, $s);
[$s, $r] = $admin->put('users/1', ['username' => 'Shankar', 'email' => 'shan8281@gmail.com', 'role' => 'chef', 'active' => true]);
check('F3 admin cannot remove own admin rights', $s === 400, [$s, $r]);

$ravi = Client::login('Ravi', 'ravi-pass-1');
[$s, $r] = $ravi->get('auth/me');
check('F2 chef logs in as chef', $s === 200 && $r['user']['role'] === 'chef', $r);
foreach ([['GET', 'users'], ['POST', 'users'], ['GET', 'settings'], ['POST', 'items'], ['POST', 'items/import/preview'],
          ['POST', 'categories'], ['POST', 'sync/sheet'], ['GET', 'items/1/history']] as [$m, $p]) {
    [$s] = $ravi->call($m, $p, $m === 'GET' ? null : []);
    check("N6 chef cannot $m $p", $s === 403, $s);
}
[$s, $r] = $ravi->get('catalog?all=1');
check('N6 chef never sees admin fields or deleted items', $s === 200 && !isset($r['items'][0]['manualPrice']), array_keys($r['items'][0] ?? []));

// Lockout (F7)
$bad = new Client();
for ($i = 1; $i <= 4; $i++) [$s] = $bad->post('auth/login', ['username' => 'Ravi', 'password' => "wrong-$i-xx"]);
check('F7 4 wrong passwords → still just "wrong"', $s === 401, $s);
[$s, $r] = $bad->post('auth/login', ['username' => 'Ravi', 'password' => 'wrong-5-xx']);
check('F7 5th wrong password locks the account', $s === 429, [$s, $r]);
[$s] = $bad->post('auth/login', ['username' => 'Ravi', 'password' => 'ravi-pass-1']);
check('F7 locked even with the right password', $s === 429, $s);
[$s, $r] = $admin->get('users');
$lockedRavi = array_values(array_filter($r['users'], fn($u) => $u['username'] === 'Ravi'))[0];
check('F3 admin sees the lock', $lockedRavi['locked'] === true, $lockedRavi);
[$s] = $ravi->get('auth/me');
check('F7 lock does not end an existing session', $s === 200, $s);
[$s] = $admin->post("users/$raviId/reset", ['password' => 'ravi-pass-2']);
check('F3 admin sets a temporary password (also unlocks)', $s === 200, $s);
[$s] = $ravi->get('auth/me');
check('F3 reset logs the user out everywhere', $s === 401, $s);
$ravi = Client::login('Ravi', 'ravi-pass-2');
check('F3 chef logs in with the temporary password', true);

// Change own password (F4)
[$s, $r] = $ravi->post('auth/password', ['current' => 'nope-nope', 'new' => 'ravi-pass-3']);
check('F4 wrong current password refused', $s === 400, [$s, $r]);
$raviPhone2 = Client::login('Ravi', 'ravi-pass-2');
[$s] = $ravi->post('auth/password', ['current' => 'ravi-pass-2', 'new' => 'ravi-pass-3']);
check('F4 change own password', $s === 200, $s);
[$s] = $ravi->get('auth/me');
check('F4 still logged in on this device', $s === 200, $s);
[$s] = $raviPhone2->get('auth/me');
check('F4 other devices are logged out', $s === 401, $s);

// Forgot password by e-mail (F5)
[$s, $r] = $anon->post('auth/forgot', ['who' => 'Maria']);
$mail = last_mail();
check('F5 reset e-mail sent to the user', $s === 200 && $mail['to'] === 'maria@example.com' && str_contains($mail['body'], '#reset='), [$s, $mail]);
preg_match('/#reset=([a-f0-9]{64})/', $mail['body'], $m);
$token = $m[1];
$before = mail_count();
[$s, $r2] = $anon->post('auth/forgot', ['who' => 'nobody-at-all']);
check('F5 unknown account: same answer, no e-mail', $s === 200 && $r2['message'] === $r['message'] && mail_count() === $before, $r2);
[$s] = $anon->post('auth/reset', ['token' => $token, 'password' => 'short']);
check('F5 reset keeps the password rules', $s === 400, $s);
[$s, $r] = $anon->post('auth/reset', ['token' => $token, 'password' => 'maria-pass-1']);
check('F5 reset link sets a new password', $s === 200 && $r['username'] === 'Maria', [$s, $r]);
[$s] = $anon->post('auth/reset', ['token' => $token, 'password' => 'maria-pass-2']);
check('F5 reset link works only once', $s === 400, $s);
$maria = Client::login('Maria', 'maria-pass-1');
check('F5 log in with the new password', true);
q('INSERT INTO password_resets (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)',
  [hash('sha256', str_repeat('ab', 32)), $mariaId, now(-7200), now(-3600)]);
[$s] = $anon->post('auth/reset', ['token' => str_repeat('ab', 32), 'password' => 'maria-pass-9']);
check('F5 expired link refused', $s === 400, $s);
for ($i = 0; $i < 5; $i++) $anon->post('auth/forgot', ['who' => 'maria@example.com']);
$sent = (int)q('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?', [$mariaId, now(-3600)])->fetchColumn();
check('F5 at most 3 reset e-mails per hour', $sent === 3, $sent);

// Sessions (F6)
$cookie = q('SELECT expires_at FROM sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$mariaId])->fetchColumn();
check('F6 session lasts 30 days', abs(strtotime($cookie) - time() - 30 * 86400) < 120, $cookie);
[$s] = $maria->post('auth/logout');
[$s2] = $maria->get('auth/me');
check('F6 log out ends the session', $s === 200 && $s2 === 401, [$s, $s2]);
$maria = Client::login('Maria', 'maria-pass-1');

// Deactivate (F3)
[$s] = $admin->put("users/$mariaId", ['username' => 'Maria', 'email' => 'maria@example.com', 'role' => 'chef', 'active' => false]);
[$s2] = $maria->get('auth/me');
[$s3] = (new Client())->post('auth/login', ['username' => 'Maria', 'password' => 'maria-pass-1']);
check('F3 deactivated user is logged out and cannot log in', $s === 200 && $s2 === 401 && $s3 === 401, [$s, $s2, $s3]);
$admin->put("users/$mariaId", ['username' => 'Maria', 'email' => 'maria@example.com', 'role' => 'chef', 'active' => true]);
q("DELETE FROM login_attempts");
