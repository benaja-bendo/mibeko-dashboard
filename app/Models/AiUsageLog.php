<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne par appel IA, succès comme échec — mibeko-dashboard#61.
 *
 * En ajout seul : pas de `updated_at`, jamais de mise à jour d'une ligne
 * existante. Écrire via `AiUsageLogger`, jamais directement — c'est lui qui
 * calcule le coût estimé à partir de la grille tarifaire (`config('ai.pricing')`).
 */
class AiUsageLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_CACHED = 'cached';

    public const STATUS_NO_CONTENT = 'no_content';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'user_id',
        'route',
        'status',
        'provider',
        'model',
        'tokens_input',
        'tokens_output',
        'cost_estimated_fcfa',
        'conversation_id',
        // mibeko-dashboard#84 : déjà tronqués/nettoyés par
        // AiUsageLogger::sanitizeErrorMessage() avant d'arriver ici.
        'error_class',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
            'cost_estimated_fcfa' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
