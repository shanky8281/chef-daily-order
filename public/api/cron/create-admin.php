<?php
/* One-time: create the first admin (design §8). Run from cPanel Terminal:
 *     php api/cron/create-admin.php Shankar you@example.com
 * It asks for the password twice; nothing is stored in files or shell history. */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

[$username, $email] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($username === '') {
    fwrite(STDERR, "usage: php api/cron/create-admin.php USERNAME [EMAIL]\n");
    exit(2);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That e-mail address is not valid.\n");
    exit(2);
}
$password = getenv('CDO_ADMIN_PASSWORD') ?: '';   // for automated tests only
if ($password === '') {
    $ask = function (string $prompt): string {
        echo $prompt;
        system('stty -echo 2>/dev/null');
        $v = rtrim((string)fgets(STDIN), "\r\n");
        system('stty echo 2>/dev/null');
        echo "\n";
        return $v;
    };
    $password = $ask('Password: ');
    if ($password !== $ask('Password again: ')) {
        fwrite(STDERR, "The passwords do not match.\n");
        exit(1);
    }
}
try {
    check_password_rules($password);
    if (q('SELECT id FROM users WHERE username = ?', [$username])->fetch()) throw new ApiError(409, "User $username already exists");
    q('INSERT INTO users (username, email, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
      [$username, $email, hash_password($password), 'admin', now()]);
} catch (ApiError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Admin $username created. Log in at " . config('base_url') . "\n";
