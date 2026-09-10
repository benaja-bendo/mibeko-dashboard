<?php

namespace App\Models;

use Database\Factories\ManualPaymentOrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class ManualPaymentOrder extends Model implements Auditable
{
    /** @use HasFactory<ManualPaymentOrderFactory> */
    use HasFactory, HasUuids, \OwenIt\Auditing\Auditable;

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAYMENT_DECLARED = 'payment_declared';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_ACTIVATED = 'activated';

    public const STATUS_REJECTED = 'rejected';

    public const CHANNELS = ['mobile_money', 'bank_transfer', 'cash'];

    public const STATUSES = [
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_PAYMENT_DECLARED,
        self::STATUS_VERIFYING,
        self::STATUS_ACTIVATED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'user_id', 'created_by', 'verification_started_by', 'resolved_by', 'plan_grant_id',
        'idempotency_key', 'reference', 'offer_code', 'amount_fcfa', 'duration_months',
        'channel', 'payment_instructions', 'status', 'payment_reference', 'payment_declared_at',
        'verification_started_at', 'activated_at', 'rejected_at', 'rejection_reason', 'internal_notes',
    ];

    protected $casts = [
        'amount_fcfa' => 'integer',
        'duration_months' => 'integer',
        'payment_declared_at' => 'datetime',
        'verification_started_at' => 'datetime',
        'activated_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verificationStarter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verification_started_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function planGrant(): BelongsTo
    {
        return $this->belongsTo(PlanGrant::class);
    }

    /** Données visibles par le client, sans notes ni identité des agents. */
    public function customerPayload(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'offer_code' => $this->offer_code,
            'offer_label' => $this->offer_code === PlanGrant::PLAN_PRO ? 'Mibeko Pro' : $this->offer_code,
            'amount_fcfa' => $this->amount_fcfa,
            'duration_months' => $this->duration_months,
            'channel' => $this->channel,
            'payment_instructions' => $this->payment_instructions,
            'status' => $this->status,
            'payment_reference' => $this->payment_reference,
            'payment_declared_at' => $this->payment_declared_at?->toIso8601String(),
            'verification_started_at' => $this->verification_started_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'plan_grant_id' => $this->plan_grant_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function adminPayload(): array
    {
        return [
            ...$this->customerPayload(),
            'internal_notes' => $this->internal_notes,
            'user' => $this->user?->only(['id', 'name', 'email']),
            'creator' => $this->creator?->only(['id', 'name']),
            'verification_starter' => $this->verificationStarter?->only(['id', 'name']),
            'resolver' => $this->resolver?->only(['id', 'name']),
        ];
    }
}
