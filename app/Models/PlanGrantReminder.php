<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'un rappel d'échéance d'abonnement déjà envoyé (idempotence des
 * envois) — mibeko-dashboard#121, même mécanique que `EcheanceReminder`.
 */
class PlanGrantReminder extends Model
{
    protected $fillable = [
        'plan_grant_id',
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

    public function planGrant(): BelongsTo
    {
        return $this->belongsTo(PlanGrant::class);
    }
}
