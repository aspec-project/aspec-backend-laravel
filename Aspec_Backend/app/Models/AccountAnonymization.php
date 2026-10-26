<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prova de que uma conta foi anonimizada: só a conta, o admin que a anonimizou (null fora da API)
 * e a data. Imutável (sem updated_at) e sem dados pessoais.
 */
class AccountAnonymization extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'anonymized_by_user_id',
    ];

    /**
     * Conta anonimizada; inclui contas apagadas (soft delete), porque é sempre esse o caso.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Admin que anonimizou; inclui admins entretanto apagados, para a prova continuar válida.
     *
     * @return BelongsTo<User, $this>
     */
    public function anonymizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anonymized_by_user_id')->withTrashed();
    }
}
