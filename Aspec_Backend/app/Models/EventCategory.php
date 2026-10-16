<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoria (tipo) de evento, com nome e cor únicos; a cor é usada pelo frontend na agenda.
 */
class EventCategory extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'color',
    ];

    /**
     * Eventos (instâncias) desta categoria.
     *
     * @return HasMany<EventInstance, $this>
     */
    public function eventInstances(): HasMany
    {
        return $this->hasMany(EventInstance::class, 'category_id');
    }

    /**
     * Indica se a categoria tem eventos associados (impede a eliminação).
     *
     * @return bool true se existir pelo menos um evento desta categoria.
     */
    public function hasEvents(): bool
    {
        return $this->eventInstances()->exists();
    }

    /**
     * Guarda a cor sempre em maiúsculas (#RRGGBB), para a unicidade não depender de maiúsculas.
     *
     * @return Attribute<never, string>
     */
    protected function color(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtoupper($value),
        );
    }
}
