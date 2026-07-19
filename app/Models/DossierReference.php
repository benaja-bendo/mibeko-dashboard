<?php

namespace App\Models;

use Database\Factories\DossierReferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Référence juridique (article ou document de la Bibliothèque) rattachée à un
 * dossier. `target_id` est l'UUID de l'entité visée ; il tient lieu d'identité
 * côté web et garantit l'unicité par dossier (idempotence de l'ajout).
 */
class DossierReference extends Model
{
    /** @use HasFactory<DossierReferenceFactory> */
    use HasFactory, HasUuids;

    /** Nature de la cible référencée. */
    public const TYPES = ['article', 'document'];

    protected $fillable = [
        'id',
        'dossier_id',
        'target_id',
        'type',
        'title',
        'breadcrumb',
        'number',
        'note',
    ];

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }
}
