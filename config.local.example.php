<?php
// Copy this file to config.local.php on the server (cPanel File Manager) and fill it in.
// config.local.php is NOT in git and deploys never touch it.
return [
    // Password for /admin
    'admin_token' => 'STRONG_ADMIN_PASSWORD',

    // Optional: Discord webhook that receives the result when a veto finishes.
    // You can also set/change it later in the admin panel.
    'discord_webhook' => '',
];
