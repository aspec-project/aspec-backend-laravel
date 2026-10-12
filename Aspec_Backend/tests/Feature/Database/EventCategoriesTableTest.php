<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventCategoriesTableTest extends TestCase
{
    use RefreshDatabase;

    // T31 — a tabela tem as colunas previstas e o índice unique no nome (última barreira contra duplicados).
    #[Test]
    public function event_categories_table_has_expected_columns_and_unique_name(): void
    {
        $this->assertTrue(Schema::hasTable('event_categories'));
        $this->assertTrue(Schema::hasColumns('event_categories', ['id', 'name', 'color', 'created_at', 'updated_at']));

        // Insere diretamente pela BD (sem o modelo) um nome que o seeder já criou.
        $this->expectException(QueryException::class);
        DB::table('event_categories')->insert([
            'id' => (string) Str::uuid(),
            'name' => 'Formações',
            'color' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
