<?php

namespace App\Models;

use Database\Factories\DocumentControleRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un passage du jeu de détecteurs de contenu sur un document (mibeko-dashboard#141,
 * § 3.5 du plan). Append-only : jamais de mise à jour d'une ligne existante,
 * une nouvelle ligne par exécution — la mesure de conformité (registre #23)
 * lit toujours le DERNIER run par document et par `version_jeu`.
 */
class DocumentControleRun extends Model
{
    /** @use HasFactory<DocumentControleRunFactory> */
    use HasFactory, HasUuids;

    protected $table = 'document_controle_runs';

    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'version_jeu',
        'date',
        'resultats',
        'resultat',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'resultats' => 'array',
        ];
    }

    /** Résultats possibles d'un passage — voir `resultat` de `JeuDeDetecteurs`. */
    const RESULTAT_OK = 'ok';

    const RESULTAT_ECHEC = 'echec';

    const RESULTAT_INCOMPLET = 'incomplet';

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }
}
