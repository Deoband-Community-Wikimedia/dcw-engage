<?php
/**
 * DCW Engage - Configuration Template
 * 
 * Duplicate this file to `config.php` and update the values.
 * NEVER COMMIT `config.php` TO VERSION CONTROL.
 */

return [
    'app' => [
        'name' => 'DCW Engage',
        'url'  => 'http://localhost:8000', // Update for production
        'env'  => 'development', // 'development' or 'production'
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'name'     => 'dcw_engage',
        'user'     => 'root',
        'password' => '',
        'charset'  => 'utf8mb4'
    ],
    'mail' => [
        'host' => 'smtp.example.com',
        'user' => 'no-reply@dcwwiki.org',
        'pass' => 'your_password',
        'port' => 587
    ],
    'security' => [
        'magic_link_expiry_draft' => '+7 days',
        'magic_link_expiry_edit'  => '+2 hours',
        // How long an emailed organizer invitation stays usable.
        'invite_expiry'           => '+7 days'
    ]
];
