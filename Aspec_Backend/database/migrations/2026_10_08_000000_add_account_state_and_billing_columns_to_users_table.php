<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Acrescenta a users o estado da subscrição e os dados de faturação.
     * Tudo nullable: contas Pending, administradores e contas antigas não têm estes dados.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('grace_ends_at')->nullable();
            // String com enum PHP (App\Enums\InactiveReason): um motivo novo não precisa de migration.
            $table->string('inactive_reason', 20)->nullable();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('billing_name')->nullable();
            // String e não inteiro: o NIF pode ter zeros à esquerda e nunca entra em contas.
            $table->string('nif', 9)->nullable();
            $table->string('billing_address')->nullable();
            $table->string('billing_postal_code', 8)->nullable();
            $table->string('billing_city', 100)->nullable();
        });
    }

    /**
     * Remove as colunas acrescentadas.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'grace_ends_at',
                'inactive_reason',
                'stripe_checkout_session_id',
                'billing_name',
                'nif',
                'billing_address',
                'billing_postal_code',
                'billing_city',
            ]);
        });
    }
};
