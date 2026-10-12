<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EventCategory extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'color',
    ];

    /**
     * Indica se a categoria tem eventos associados.
     * A tabela event_instances ainda não existe (módulo de eventos): enquanto não existir,
     * nenhuma categoria pode ter eventos. Quando existir, passa a consultar a tabela.
     */
    public function hasEvents(): bool
    {
        if (! Schema::hasTable('event_instances')) {
            return false;
        }

        // DB::table e não uma relação Eloquent: a classe EventInstance ainda não existe,
        // e assim só dependemos do nome da tabela e da coluna fixados no ERD.
        return DB::table('event_instances')->where('category_id', $this->id)->exists();
    }
}
