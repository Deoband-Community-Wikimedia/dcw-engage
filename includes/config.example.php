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
        // Developer machines only. When true and no mail server is set up, the full text of every email
        // that would have been sent (recipient, codes, links) is written UNREDACTED to app_errors.log, so you can
        // test sign-up and verification flows. Never set this to true on the live server.
        'debug' => false,
        // Where errors are logged. An absolute path OUTSIDE public_html is safest, e.g.
        // '/home/<user>/logs/engage_errors.log' (create the folder first). Empty: app_errors.log in the app folder,
        // which .htaccess refuses to serve.
        'log_file' => '',
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
        'port' => 587,

        // Scholarship letters (award / decline emails written by the committee).
        // REQUIRED to switch letters on: replies to these emails go to the committee,
        // since the sending address (above) is a no-reply mailbox.
        'src_reply_to' => 'scholarships@dcwwiki.org', // committee address
        // OPTIONAL: display name shown to recipients and used on the Reply-To header.
        // If omitted or empty, the address alone is used.
        'src_name'     => 'DCW Scholarship Review Committee',
    ],
    'security' => [
        'magic_link_expiry_draft' => '+7 days',
        'magic_link_expiry_edit'  => '+2 hours',
        'invite_expiry'           => '+7 days',
        'email_verify_expiry'     => '+10 minutes',
        'field_encryption_key'    => 'replace-with-a-long-random-string',
        'otp_secret'              => 'replace-with-a-long-random-string',
        'feedback_retention_months' => 12
    ]
];
