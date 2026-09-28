<?php
/* Copy to config.php (same folder) on the server and fill in. config.php is never stored in
 * GitHub, never overwritten by the deploy, and cannot be opened from the web. */
return [
    // cPanel → MySQL Databases. Tables are created automatically on the first visit.
    'db_dsn'      => 'mysql:host=localhost;dbname=CPANELUSER_chef;charset=utf8mb4',
    'db_user'     => 'CPANELUSER_chef',
    'db_password' => 'CHANGE-ME',

    'base_url'    => 'https://buy.mirchi.cl',   // used in password-reset links
    'mail_from'   => 'no-reply@mirchi.cl',      // a mailbox that exists in cPanel

    // Google service-account key (JSON), stored OUTSIDE the web folder. Share the Google
    // Sheet (Editor) with the "client_email" written inside this file.
    'google_key'  => '/home/CPANELUSER/chef-private/service-account.json',
];
