<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para as consultas de avaliações do dashboard: as últimas avaliações (updated_at, id)
 * e a contagem da semana (created_at). Sem eles, com muitas avaliações, ambas percorriam a tabela inteira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->index(['updated_at', 'id'], 'reviews_updated_at_id_index');
            $table->index('created_at', 'reviews_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex('reviews_updated_at_id_index');
            $table->dropIndex('reviews_created_at_index');
        });
    }
};
