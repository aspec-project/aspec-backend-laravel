<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opções dos cartões de oração no evento: ativação e versículo da semana (definidos pelo admin) e o
     * momento da distribuição, que impede o mesmo evento de ser distribuído duas vezes.
     */
    public function up(): void
    {
        Schema::table('event_instances', function (Blueprint $table): void {
            $table->boolean('prayer_cards_enabled')->default(false);
            $table->string('prayer_verse', 500)->nullable();
            $table->timestamp('prayer_cards_distributed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_instances', function (Blueprint $table): void {
            $table->dropColumn(['prayer_cards_enabled', 'prayer_verse', 'prayer_cards_distributed_at']);
        });
    }
};
