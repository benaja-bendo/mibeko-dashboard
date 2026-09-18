<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne par recherche, résultat trouvé comme recherche sans résultat —
 * mibeko-dashboard#111.
 *
 * En ajout seul : pas de `updated_at`, jamais de mise à jour d'une ligne
 * existante. Écrire via `SearchQueryLogger`, jamais directement — c'est lui
 * qui normalise la requête et dispatch l'écriture hors du chemin chaud.
 */
class SearchLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'query',
        'results_count',
        'surface',
    ];

    protected function casts(): array
    {
        return [
            'results_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
