<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
    'api/*',
    'sanctum/csrf-cookie',
],

'allowed_origins' => [
    env('FRONTEND_URL', 'http://localhost:5173'),
],

'allowed_methods' => ['*'],

'allowed_headers' => ['*'],

'exposed_headers' => [],

// Reutiliza a validação CORS durante duas horas para evitar preflights repetidos.
'max_age' => 7200,

'supports_credentials' => true,

];
