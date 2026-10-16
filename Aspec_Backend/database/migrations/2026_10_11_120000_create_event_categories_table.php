<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // O índice unique é a última barreira contra nomes repetidos (ex.: dois admins a criar ao mesmo tempo).
            $table->string('name')->unique();
            // Cor #RRGGBB (em maiúsculas) usada pelo frontend nos cartões da agenda; obrigatória e única; não está no ERD (decisão de 09/10, ASPEC-326).
            $table->string('color', 7)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_categories');
    }
};
