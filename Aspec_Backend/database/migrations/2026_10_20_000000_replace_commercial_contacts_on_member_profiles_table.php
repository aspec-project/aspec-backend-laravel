<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Troca o texto livre commercial_contacts por commercial_email + commercial_phone (E.164).
     * Os valores antigos são descartados: não há produção, só dados de teste/demo, e extrair
     * email e telefone de texto livre seria adivinhar. Colunas nullable porque os perfis antigos
     * não têm valor e a anonimização limpa-as; a obrigatoriedade fica na validação.
     * Acrescentar e apagar em Schema::table separados: no SQLite o Laravel recria a tabela para apagar colunas.
     */
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->string('commercial_email')->nullable()->after('website_url');
            $table->string('commercial_phone', 20)->nullable()->after('commercial_email');
        });

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropColumn('commercial_contacts');
        });
    }

    /**
     * Repõe commercial_contacts com o email e o telefone comerciais separados por "\n"
     * (incluindo perfis soft deleted) e apaga as duas colunas novas.
     */
    public function down(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->text('commercial_contacts')->nullable()->after('website_url');
        });

        DB::table('member_profiles')
            ->select(['id', 'commercial_email', 'commercial_phone'])
            ->chunkById(200, function ($profiles) {
                foreach ($profiles as $profile) {
                    $contacts = implode("\n", array_filter([$profile->commercial_email, $profile->commercial_phone]));

                    DB::table('member_profiles')
                        ->where('id', $profile->id)
                        ->update(['commercial_contacts' => $contacts === '' ? null : $contacts]);
                }
            });

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropColumn(['commercial_email', 'commercial_phone']);
        });
    }
};
