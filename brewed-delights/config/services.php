<?php

$settings = [
    'google_client_id' => '',
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'mail_from' => '',
    'mail_from_name' => 'Brewed Delights',
    'gcash_number' => '',
    'gcash_name' => '',
];

$localFile = __DIR__ . '/services.local.php';
if (is_file($localFile)) {
    $localSettings = require $localFile;
    if (is_array($localSettings)) {
        $settings = array_merge($settings, $localSettings);
    }
}

$environmentVariables = [
    'google_client_id' => 'GOOGLE_CLIENT_ID',
    'smtp_host' => 'SMTP_HOST',
    'smtp_port' => 'SMTP_PORT',
    'smtp_username' => 'SMTP_USERNAME',
    'smtp_password' => 'SMTP_PASSWORD',
    'smtp_encryption' => 'SMTP_ENCRYPTION',
    'mail_from' => 'MAIL_FROM',
    'mail_from_name' => 'MAIL_FROM_NAME',
    'gcash_number' => 'GCASH_NUMBER',
    'gcash_name' => 'GCASH_NAME',
];

foreach ($environmentVariables as $setting => $variable) {
    $value = getenv($variable);
    if ($value !== false) {
        $settings[$setting] = $value;
    }
}

return $settings;