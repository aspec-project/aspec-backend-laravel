<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Índices de reviews usados pelo dashboard: as últimas avaliações (updated_at, id) e as da semana (created_at).
 * Sem eles, com centenas de milhares de avaliações, as duas consultas percorriam a tabela inteira.
 */
class ReviewDashboardIndexesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reviews_has_an_index_on_updated_at_and_id(): void
    {
        $this->assertTrue(Schema::hasIndex('reviews', ['updated_at', 'id']));
    }

    #[Test]
    public function reviews_has_an_index_on_created_at(): void
    {
        $this->assertTrue(Schema::hasIndex('reviews', ['created_at']));
    }
}
