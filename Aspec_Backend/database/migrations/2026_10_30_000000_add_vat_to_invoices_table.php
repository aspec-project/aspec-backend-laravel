<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imposto aplicado em cada fatura, gravado no momento da emissão: a configuração do IVA pode
     * mudar, mas uma fatura emitida não. Nullable: faturas pendentes, falhadas e anteriores a
     * estas colunas ficam sem imposto.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedTinyInteger('vat_rate')->nullable()->after('currency');
            $table->string('vat_exemption_code', 3)->nullable()->after('vat_rate');
            $table->string('vat_exemption_reason', 255)->nullable()->after('vat_exemption_code');
        });
    }

    /**
     * Remove as colunas do imposto.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'vat_exemption_code', 'vat_exemption_reason']);
        });
    }
};
