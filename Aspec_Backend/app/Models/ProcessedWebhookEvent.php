<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Evento do Stripe já processado pelo webhook. O stripe_event_id é único: um evento entregue
 * duas vezes só é processado uma.
 */
class ProcessedWebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'stripe_event_id',
        'type',
    ];
}
