<?php
/* The version in VERSION (see CHANGELOG.md) and the one shown in the app's menu must match. */

$version = trim((string)file_get_contents(__DIR__ . '/../VERSION'));
check('Version number is MAJOR.MINOR.PATCH', (bool)preg_match('/^\d+\.\d+\.\d+$/', $version), $version);
preg_match('#id="app-version">([^<]+)<#', (string)file_get_contents(__DIR__ . '/../public/index.html'), $m);
check('The menu shows the same version as VERSION', ($m[1] ?? null) === $version, [$m[1] ?? null, $version]);
check('CHANGELOG.md has an entry for this version', str_contains((string)file_get_contents(__DIR__ . '/../CHANGELOG.md'), "## [$version]"));
