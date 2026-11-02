<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Período pago de cada fatura, como o Stripe o envia na linha da subscrição (o fim é exclusivo:
     * é o início do período seguinte). Nullable: faturas anteriores a esta coluna e payloads sem
     * período válido ficam sem ele.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('period_start')->nullable()->after('currency');
            $table->timestamp('period_end')->nullable()->after('period_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['period_start', 'period_end']);
        });
    }
};
