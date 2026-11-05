<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies de confiança
    |--------------------------------------------------------------------------
    |
    | IPs (separados por vírgulas) ou "*" para confiar em qualquer proxy, como no Render, onde cada
    | pedido chega pelo proxy da plataforma. Vazio = não confiar em proxies: os cabeçalhos
    | X-Forwarded-* são ignorados e o ip() é o REMOTE_ADDR (local, testes, colegas).
    | Lido pelo middleware TrustProxies do Laravel em cada pedido.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
