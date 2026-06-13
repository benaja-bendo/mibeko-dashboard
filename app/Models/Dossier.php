<?php

namespace App\Models;

use Database\Factories\DossierFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dossier juridique d'un utilisateur (organisation d'articles par affaire).
 *
 * L'identifiant UUID est généré côté client pour permettre la création
 * hors-ligne ; la suppression douce sert de tombstone de synchronisation.
 */
class Dossier extends Model
{
    /** @use HasFactory<DossierFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'name',
        'legal_domain',
        'tag',
        'description',
        'color',
        'client_created_at',
        'client_updated_at',
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
}
