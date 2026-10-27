<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Código postal e localidade do perfil, separados da morada (que passa a ter só a rua).
     * Nullable na BD: perfis antigos não os têm e o anonymizeAndDelete() limpa-os; são
     * obrigatórios só na validação.
     */
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table): void {
            $table->string('postal_code', 8)->nullable()->after('address');
            $table->string('city', 100)->nullable()->after('postal_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('member_profiles', function (Blueprint $table): void {
            $table->dropColumn(['postal_code', 'city']);
        });
    }
};
