<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Ancien slug d'un document, conservé pour que son URL publique reste
 * résolvable après un changement (mibeko-dashboard#155).
 *
 * L'alias vise le document, pas le slug qui l'a remplacé : une chaîne
 * A → B → C se résout en un seul saut vers le slug canonique courant.
 * Append-only ; la seule suppression légitime est le retour d'un document sur
 * un de ses anciens slugs (l'alias redevient canonique, cf. `LegalDocument`).
 */
class DocumentSlugAlias extends Model
{
    use HasUuids;

    protected $table = 'document_slug_aliases';

    const UPDATED_AT = null;

    protected $fillable = [
        'slug',
        'legal_document_id',
    ];

    protected static function booted(): void
    {
        // Invariant croisé (voir la migration) : un alias ne peut pas porter
        // le slug canonique d'un document, corbeille incluse — sinon l'alias
        // serait masqué (la résolution essaie le canonique d'abord) ou, pire,
        // ferait pointer une même URL vers deux textes selon le chemin.
        static::saving(function (DocumentSlugAlias $alias) {
            $alias->slug = trim((string) $alias->slug);

            if ($alias->slug === '') {
                throw ValidationException::withMessages([
                    'slug' => 'Un alias de slug ne peut pas être vide.',
                ]);
            }

            if (LegalDocument::withTrashed()->where('slug', $alias->slug)->exists()) {
                throw ValidationException::withMessages([
                    'slug' => "Le slug « {$alias->slug} » est déjà le slug canonique d'un document : il ne peut pas devenir un alias.",
                ]);
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }
}
