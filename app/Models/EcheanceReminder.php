<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'un rappel d'échéance déjà envoyé (idempotence des envois).
 */
class EcheanceReminder extends Model
{
    protected $fillable = [
        'echeance_id',
        'offset_days',
        'sent_on',
    ];

    protected function casts(): array
    {
        return [
            'offset_days' => 'integer',
            'sent_on' => 'date',
        ];
    }

    public function echeance(): BelongsTo
    {
        return $this->belongsTo(DossierEcheance::class);
    }
}
