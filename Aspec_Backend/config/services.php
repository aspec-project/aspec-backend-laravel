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

    // Faturação dos pagamentos: 'log' (sem serviço externo) ou 'invoiceexpress'.
    'invoicing' => [
        'driver' => env('INVOICING_DRIVER', 'log'),
    ],

    'invoiceexpress' => [
        'account_name' => env('INVOICEEXPRESS_ACCOUNT_NAME'),
        'api_key' => env('INVOICEEXPRESS_API_KEY'),
        'document_type' => env('INVOICEEXPRESS_DOCUMENT_TYPE', 'invoice_receipts'),
        'sequence_id' => env('INVOICEEXPRESS_SEQUENCE_ID'),
        'item_name' => env('INVOICEEXPRESS_ITEM_NAME', 'Quota mensal ASPEC'),
        'tax_name' => env('INVOICEEXPRESS_TAX_NAME', 'IVA23'),
        'vat_rate' => (int) env('INVOICEEXPRESS_VAT_RATE', 23),
        'tax_exemption' => env('INVOICEEXPRESS_TAX_EXEMPTION'),
        'timeout' => (int) env('INVOICEEXPRESS_TIMEOUT', 10),
        'retry_times' => (int) env('INVOICEEXPRESS_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('INVOICEEXPRESS_RETRY_SLEEP_MS', 500),
    ],

];
