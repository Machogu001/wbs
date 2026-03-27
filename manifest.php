<?php
/**
 * Dynamic Web App Manifest
 * Served as application/manifest+json so the app name
 * can be read from the same APP_NAME environment variable
 * used throughout the application.
 */
header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=3600');

$appName   = getenv('APP_NAME') ?: 'Water Billing System';
$shortName = mb_strlen($appName) > 14 ? 'WaterBill' : $appName;

$manifest = [
    'name'             => $appName,
    'short_name'       => $shortName,
    'description'      => 'Manage your water bills, payments and account online.',
    'start_url'        => '/',
    'scope'            => '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'background_color' => '#0D47A1',
    'theme_color'      => '#0D47A1',
    'lang'             => 'en',
    'categories'       => ['utilities', 'finance'],
    'icons'            => [
        [
            'src'     => '/public/images/favicon-water.svg',
            'sizes'   => 'any',
            'type'    => 'image/svg+xml',
            'purpose' => 'any maskable',
        ],
    ],
    'shortcuts'        => [
        [
            'name'        => 'My Bills',
            'short_name'  => 'Bills',
            'description' => 'View your current water bill',
            'url'         => '/bills',
            'icons'       => [['src' => '/public/images/favicon-water.svg', 'sizes' => 'any']],
        ],
        [
            'name'        => 'Pay Bill',
            'short_name'  => 'Pay',
            'description' => 'Pay your water bill via M-Pesa',
            'url'         => '/pay',
            'icons'       => [['src' => '/public/images/favicon-water.svg', 'sizes' => 'any']],
        ],
    ],
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
