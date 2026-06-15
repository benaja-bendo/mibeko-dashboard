<?php

namespace App\Models;

use Database\Factories\DossierFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dossier juridique d'un utilisateur (organisation d'articles par affaire).
 *
 * L'identifiant UUID est généré côté client pour permettre la création
 * hors-ligne ; la suppression douce sert de tombstone de synchronisation.
 *
 * Les champs « affaire » (type, status, client, partie adverse, juridiction…)
 * sont alimentés par le tableau de bord web ; la sync mobile n'utilise que le
 * sous-ensemble historique (name, legal_domain, tag, color, articles).
 */
class Dossier extends Model
{
    /** @use HasFactory<DossierFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** Types de dossier (formulaire web adaptatif). */
    public const TYPES = ['contentieux', 'conseil'];

    /** Statuts d'avancement d'une affaire. */
    public const STATUSES = ['ouvert', 'en_cours', 'en_attente', 'clos'];

    protected $fillable = [
        'id',
        'user_id',
        'type',
        'name',
        'legal_domain',
        'tag',
        'status',
        'internal_reference',
        'client_name',
        'client_role',
        'adverse_party',
        'jurisdiction',
        'nature',
        'description',
        'color',
        'client_created_at',
        'client_updated_at',
    ];

    /** Reflète les défauts de la migration pour les instances non persistées. */
    protected $attributes = [
        'type' => 'contentieux',
        'status' => 'ouvert',
    ];

    protected function casts(): array
    {
        return [
            'client_created_at' => 'integer',
            'client_updated_at' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'dossier_articles')
            ->withPivot(['personal_note', 'added_at']);
    }

    public function echeances(): HasMany
    {
        return $this->hasMany(DossierEcheance::class);
    }
}
