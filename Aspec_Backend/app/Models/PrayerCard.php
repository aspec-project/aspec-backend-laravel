<?php

namespace App\Models;

use Database\Factories\PrayerCardFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cartão de oração escrito por um membro num evento e entregue a outro participante na distribuição
 * em cadeia. Guarda só os 3 pedidos (encriptados); o nome e os contactos do autor lêem-se da conta.
 */
class PrayerCard extends Model
{
    /** @use HasFactory<PrayerCardFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'event_instance_id',
        'author_user_id',
        'personal_request',
        'work_request',
        'salvation_request',
        'consent_given_at',
    ];

    /**
     * Os pedidos são dados de crenças religiosas (RGPD art. 9.º): encriptados com a APP_KEY, para não
     * serem legíveis numa cópia da base de dados.
     */
    protected function casts(): array
    {
        return [
            'personal_request' => 'encrypted',
            'work_request' => 'encrypted',
            'salvation_request' => 'encrypted',
            'consent_given_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function eventInstance(): BelongsTo
    {
        return $this->belongsTo(EventInstance::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * Colunas limpas ao apagar um cartão: o conteúdo e quem o recebeu. Ficam só o evento, o autor e a
     * hora de entrega, como prova de presença.
     *
     * @return array<string, null>
     */
    public static function erasedAttributes(): array
    {
        return [
            'personal_request' => null,
            'work_request' => null,
            'salvation_request' => null,
            'recipient_user_id' => null,
            'received_at' => null,
        ];
    }

    /**
     * Apaga o conteúdo e o destinatário e faz soft delete: a linha fica só como prova de presença no
     * evento. Usado ao retirar um cartão já distribuído, na expiração e na anonimização do autor.
     */
    public function erase(): void
    {
        $this->forceFill(self::erasedAttributes())->save();

        $this->delete();
    }
}
