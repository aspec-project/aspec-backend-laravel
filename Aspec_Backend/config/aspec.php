<?php

return [
    'admin' => [
        'email' => env('ASPEC_ADMIN_EMAIL'),
        'password' => env('ASPEC_ADMIN_PASSWORD'),
    ],

    'demo_password' => env('DEMO_PASSWORD'),

    'contact_email' => env('ASPEC_CONTACT_EMAIL', 'aspec@aspec.pt'),
];
