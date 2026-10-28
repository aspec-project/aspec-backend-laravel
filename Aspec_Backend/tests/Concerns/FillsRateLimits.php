<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Pré-enche os contadores dos limitadores com nome, para testar o 429 sem fazer centenas de pedidos.
 */
trait FillsRateLimits
{
    /**
     * Soma $hits ao contador de um Limit do limitador $limiter.
     *
     * Espelha a chave do ThrottleRequests com $shouldHashKeys ligado (o valor por omissão):
     * md5(nome do limitador . chave do Limit). Se o Laravel mudar esta regra, só este método muda.
     */
    protected function fillRateLimit(string $limiter, string $key, int $hits, int $decaySeconds = 60): void
    {
        RateLimiter::increment(md5($limiter.$key), $decaySeconds, $hits);
    }
}
