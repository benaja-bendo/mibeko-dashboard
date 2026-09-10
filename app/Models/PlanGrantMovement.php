<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Une ligne du grand livre FCFA d'un octroi Pro — mibeko-dashboard#122.
 *
 * En ajout seul : pas de `updated_at`, jamais de mise à jour d'une ligne
 * existante. Écrire via `PlanGrantLedger`, jamais directement — même
 * discipline que `CreditLedgerEntry`/`CreditLedger`.
 */
class PlanGrantMovement extends Model implements Auditable
{
    use HasUuids, \OwenIt\Auditing\Auditable;

    public const UPDATED_AT = null;

    public const TYPE_COLLECTED = 'collected';

    public const TYPE_REFUND = 'refund';

    public const TYPE_CORRECTION = 'correction';

    protected $fillable = [
        'plan_grant_id',
        'manual_payment_order_id',
        'type',
        'amount_fcfa',
        'occurred_at',
        'reference_id',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_fcfa' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function planGrant(): BelongsTo
    {
        return $this->belongsTo(PlanGrant::class);
    }

    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(ManualPaymentOrder::class, 'manual_payment_order_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
