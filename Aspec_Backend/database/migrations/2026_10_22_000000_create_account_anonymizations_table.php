<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prova mínima de cada anonimização (RGPD art. 5(2)): que conta, quem anonimizou e quando, sem
     * dados pessoais. user_id único (uma conta só é anonimizada uma vez) e com restrict, porque a conta
     * anonimizada nunca é apagada de vez e a prova tem de apontar sempre para ela. Sem updated_at: o
     * registo nunca muda.
     */
    public function up(): void
    {
        Schema::create('account_anonymizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('anonymized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_anonymizations');
    }
};
