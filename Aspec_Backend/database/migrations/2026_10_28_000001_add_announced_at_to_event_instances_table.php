<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Momento em que o evento foi anunciado aos membros; impede que o mesmo evento seja anunciado duas vezes.
     */
    public function up(): void
    {
        Schema::table('event_instances', function (Blueprint $table): void {
            $table->timestamp('announced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_instances', function (Blueprint $table): void {
            $table->dropColumn('announced_at');
        });
    }
};
