<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'midtrans' => [
        'sandbox_base_url' => env('MIDTRANS_SANDBOX_BASE_URL', 'https://api.sandbox.midtrans.com/v2'),
        'sandbox_snap_base_url' => env('MIDTRANS_SANDBOX_SNAP_BASE_URL', 'https://app.sandbox.midtrans.com/snap/v1'),
        'timeout' => (int) env('MIDTRANS_TIMEOUT', 10),
        'connect_timeout' => (int) env('MIDTRANS_CONNECT_TIMEOUT', 5),
    ],

    'udev_internal' => [
        'enabled' => env('UDEV_INTERNAL_ENABLED', true),
        'service_id' => env('UDEV_INTERNAL_SERVICE_ID', 'udev-billing'),
        'token' => env('MIDTRANS_GATEWAY_SERVICE_TOKEN', env('UDEV_INTERNAL_SERVICE_TOKEN', 'midtrans_service_secret_token_sandbox_64chars_key')),
        'abilities' => [
            'midtrans:merchants:read',
            'midtrans:merchants:write',
            'midtrans:configuration:read',
            'midtrans:configuration:write',
            'midtrans:test:connection',
            'midtrans:test:charge',
            'midtrans:payments:read',
            'midtrans:payments:write',
            'midtrans:audits:read',
        ],
    ],

];
