<?php

namespace App\Models;

use Database\Factories\DocumentRelecturePreuveFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preuve de relecture dirigée (mibeko-dashboard#142, § 2.5 du plan « boîte
 * de réception »). Append-only : une nouvelle relecture crée une nouvelle
 * ligne, jamais une mise à jour de la précédente.
 */
class DocumentRelecturePreuve extends Model
{
    /** @use HasFactory<DocumentRelecturePreuveFactory> */
    use HasFactory, HasUuids;

    protected $table = 'document_relecture_preuves';

    const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'actor_id',
        'document_controle_run_id',
        'points_vus',
        'sondage_articles',
        'sondage_confirmes',
    ];

    protected function casts(): array
    {
        return [
            'points_vus' => 'array',
            'sondage_articles' => 'array',
            'sondage_confirmes' => 'array',
        ];
    }

    /** Vrai quand tous les articles tirés au sondage ont été confirmés. */
    public function sondageComplet(): bool
    {
        $tires = $this->sondage_articles ?? [];
        $confirmes = $this->sondage_confirmes ?? [];

        return $tires !== [] && array_diff($tires, $confirmes) === [];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function controleRun(): BelongsTo
    {
        return $this->belongsTo(DocumentControleRun::class, 'document_controle_run_id');
    }
}
