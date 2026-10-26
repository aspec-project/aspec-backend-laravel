<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converte os telefones portugueses guardados em 9 dígitos (formato da antiga regra só para
 * Portugal) para E.164 com +351. Usa DB::table e não o modelo User nem a regra de validação, para
 * continuar a funcionar se esse código mudar; DB::table inclui também os utilizadores com soft delete.
 */
return new class extends Migration
{
    /** Formatos que a antiga regra aceitava (telemóvel, fixo e nómada 30), já sem separadores. */
    private const PORTUGUESE_NINE_DIGITS = '/^(9[1236]\d{7}|2\d{8}|30\d{7})$/D';

    private const PORTUGUESE_E164 = '/^\+351(\d{9})$/D';

    /**
     * Acrescenta +351 aos números de 9 dígitos. Ignora null, os valores já em E.164, o marcador
     * de anonimização (000000000) e qualquer outro formato. Correr duas vezes não altera nada.
     */
    public function up(): void
    {
        $this->convert(function (string $phone): ?string {
            return preg_match(self::PORTUGUESE_NINE_DIGITS, $phone) ? '+351'.$phone : null;
        });
    }

    /**
     * Tira o +351 aos números portugueses. Os estrangeiros ficam em E.164, porque não têm
     * representação no formato antigo.
     */
    public function down(): void
    {
        $this->convert(function (string $phone): ?string {
            return preg_match(self::PORTUGUESE_E164, $phone, $matches) ? $matches[1] : null;
        });
    }

    /**
     * Percorre os utilizadores com telefone e grava o valor devolvido por $convert (null = não mexer).
     * Lê em blocos de 100 paginados pelo id (chunkById), seguro quando se alteram as linhas lidas.
     * A concatenação é feita em PHP porque o SQL difere entre MySQL (CONCAT) e SQLite (||).
     *
     * @param  callable(string): ?string  $convert
     */
    private function convert(callable $convert): void
    {
        DB::table('users')
            ->select(['id', 'phone'])
            ->whereNotNull('phone')
            ->chunkById(100, function ($users) use ($convert) {
                foreach ($users as $user) {
                    $phone = $convert($user->phone);

                    if ($phone !== null) {
                        DB::table('users')->where('id', $user->id)->update(['phone' => $phone]);
                    }
                }
            });
    }
};
