<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne du grand livre de crédits — mibeko-dashboard#66.
 *
 * En ajout seul : pas de `updated_at`, jamais de mise à jour d'une ligne
 * existante. Écrire via `CreditLedger`, jamais directement — c'est lui qui
 * pose le verrou consultatif nécessaire à une consommation atomique.
 */
class CreditLedgerEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_CONSUMPTION = 'consumption';

    public const TYPE_CORRECTION = 'correction';

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'reason',
        'reference_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
