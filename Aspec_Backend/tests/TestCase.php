<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Cashier\Cashier;

abstract class TestCase extends BaseTestCase
{
    // Com SQLite em memória o RefreshDatabase migra uma só vez por processo; o seed dos lookups tem de estar na base para valer em toda a suite.
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Rede de segurança: um pedido ao Stripe esquecido falha logo (porta local fechada) em vez de sair para a Internet.
        Cashier::$apiBaseUrl = 'http://127.0.0.1:9';
    }
}
