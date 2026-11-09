<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /** A paleta fica aqui e não no config: o resultado de uma migração não pode mudar com o config. */
    private const PALETTE = [
        '#0D1F35', '#1E449A', '#2A6E9E', '#0A4251', '#1C747E',
        '#0B4D3F', '#0E784F', '#2B4A12', '#53731A', '#5E6013',
        '#7A6238', '#94591A', '#5A3A14', '#3A2E1C', '#38207E',
        '#6548B8', '#763B82', '#686693', '#5B6670', '#383B41',
    ];

    /** @var array<string, string> Cor semeada antiga => cor da paleta. */
    private const DESTINATIONS = [
        '#B84A00' => '#94591A',
        '#2E7D32' => '#0E784F',
        '#6A1B9A' => '#763B82',
        '#C62828' => '#1C747E',
        '#1565C0' => '#1E449A',
    ];

    /**
     * Passa para a paleta as categorias com cores fora dela: destino do mapa se estiver livre, senão a primeira
     * cor livre. Nunca escreve uma cor em uso, por causa do índice único; sem cores livres, a categoria fica como está.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $categories = DB::table('event_categories')->orderBy('created_at')->orderBy('id')->get(['id', 'color']);

            $free = array_values(array_diff(
                self::PALETTE,
                $categories->map(fn (object $category): string => strtoupper($category->color))->all()
            ));

            foreach ($categories as $category) {
                $current = strtoupper($category->color);

                if (in_array($current, self::PALETTE, true)) {
                    continue;
                }

                $destination = self::DESTINATIONS[$current] ?? null;
                $color = in_array($destination, $free, true) ? $destination : ($free[0] ?? null);

                if ($color === null) {
                    Log::warning('Categoria de eventos sem cor livre na paleta: ficou com a cor atual.', [
                        'event_category_id' => $category->id,
                    ]);

                    continue;
                }

                DB::table('event_categories')->where('id', $category->id)->update(['color' => $color]);
                $free = array_values(array_diff($free, [$color]));
            }
        });
    }

    /**
     * Sem reversão: não há alteração de esquema e a cor original não é guardada.
     */
    public function down(): void {}
};
