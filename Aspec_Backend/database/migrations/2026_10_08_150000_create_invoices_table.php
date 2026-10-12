<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faturas guardadas por obrigação fiscal: user_id com restrict (users usa soft delete; um hard
     * delete de quem tem faturas tem de falhar, igual a subscriptions); stripe_invoice_id único para o
     * mesmo pagamento nunca gerar duas faturas; amount em cêntimos, como o Stripe envia.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->string('provider');
            $table->string('provider_invoice_id')->nullable();
            $table->string('number')->nullable();
            $table->unsignedInteger('amount');
            $table->char('currency', 3);
            $table->string('status');
            $table->string('pdf_url')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
