<?php

namespace App\Models;

use Database\Factories\DossierPieceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce versée à un dossier. Métadonnées uniquement : aucun binaire n'est
 * stocké à cette étape (le web ne gère pas encore l'upload de fichier).
 */
class DossierPiece extends Model
{
    /** @use HasFactory<DossierPieceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'dossier_id',
        'name',
        'size',
        'mime',
        'note',
        'added_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'added_at' => 'datetime',
        ];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }
}
