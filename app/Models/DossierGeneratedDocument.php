<?php

namespace App\Models;

use Database\Factories\DossierGeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document généré depuis un modèle (mise en demeure, statuts…) et rattaché à un
 * dossier, avec son contenu HTML imprimable. Le web l'expose comme
 * `GeneratedDocument` (`createdAt` = `created_at` Eloquent).
 */
class DossierGeneratedDocument extends Model
{
    /** @use HasFactory<DossierGeneratedDocumentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'dossier_id',
        'template_id',
        'template_name',
        'title',
        'html',
    ];

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }
}
