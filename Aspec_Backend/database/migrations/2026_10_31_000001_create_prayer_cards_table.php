<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cartões de oração: um por membro por evento (unique), com os 3 pedidos encriptados pelo modelo.
     * Nome, email e telefone do autor não são copiados: lêem-se da conta ao mostrar. Apagar o evento
     * apaga os cartões; os utilizadores nunca são apagados de vez (soft delete), por isso restrict.
     */
    public function up(): void
    {
        Schema::create('prayer_cards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('recipient_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->text('personal_request')->nullable();
            $table->text('work_request')->nullable();
            $table->text('salvation_request')->nullable();
            $table->timestamp('consent_given_at');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['event_instance_id', 'author_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prayer_cards');
    }
};
