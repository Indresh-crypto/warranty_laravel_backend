<?php

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'companies',
        ],
    ],

    'providers' => [

        // ✅ REQUIRED (even if you don't use User model)
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        // ✅ Your custom provider
        'companies' => [
            'driver' => 'eloquent',
            'model' => App\Models\Company::class,
        ],
    ],

    'passwords' => [

        // ✅ REQUIRED default broker
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        // ✅ Company password reset
        'companies' => [
            'provider' => 'companies',
            'table' => 'company_password_resets',
            'expire' => 60,
            'throttle' => 5,
        ],
    ],

    'password_timeout' => 10800,
];