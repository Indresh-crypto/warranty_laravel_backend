<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */
    
     'razorpay' => [
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'razorpay_key' => env('RAZORPAY_KEY'),
        'razorpay_secret' => env('RAZORPAY_SECRET')
    ],
    
    'phonepe' => [
    'merchant_id' => env('PHONEPE_MERCHANT_ID'),
    'salt_key'    => env('PHONEPE_SALT_KEY'),
    'salt_index'  => env('PHONEPE_SALT_INDEX'),
    'base_url'    => env('PHONEPE_BASE_URL'),
    ],
    
    
    
    'gupshup' => [
        'key' => env('GUPSHUP_API_KEY'),
        'source' => env('GUPSHUP_WHATSAPP_NUMBER'),
        'app_name' => env('GUPSHUP_APP_NAME'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],
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

];
