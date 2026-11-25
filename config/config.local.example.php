<?php
/**
 * Copy this file to config.local.php and adjust the values for your server.
 * config.local.php must never be committed to source control.
 */

return [
    'app' => [
        'debug'    => false,
        // Leave null for automatic detection, or force a path such as
        // '/online-voting-system' if the application is served through an alias.
        'base_url' => null,
        'timezone' => 'Asia/Kolkata',
    ],
    'database' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'online_voting_system',
        'user'     => 'root',
        'password' => '',
    ],
];
