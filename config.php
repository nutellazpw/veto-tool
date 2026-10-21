<?php
// =====================================================================
//  RELOG VETO — general settings
//
//  !!! DO NOT PUT PASSWORDS HERE !!!
//  This file is in git and every deploy overwrites it.
//  The admin password goes ONLY into config.local.php on the server
//  (template: config.local.example.php) — deploys never touch that file.
// =====================================================================
$local = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];
if (!is_array($local)) $local = [];

// Admin password. Empty = admin stays locked until config.local.php exists.
define('ADMIN_TOKEN', (string)($local['admin_token'] ?? ''));

// Optional default Discord webhook (can also be set in the admin panel, which takes priority).
define('DISCORD_WEBHOOK_DEFAULT', (string)($local['discord_webhook'] ?? ''));

// Where the veto state, team database and logos live (must be writable by PHP)
define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', __DIR__ . '/uploads');

// Max logo size
define('MAX_LOGO_BYTES', 5 * 1024 * 1024);
