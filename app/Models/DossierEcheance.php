<?php

namespace App\Models;

use App\Services\EffaceurContenuSupprime;
use Database\Factories\DossierEcheanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Échéance rattachée à un dossier (audience, délai de procédure, prescription…).
 *
 * UUID généré côté client et tombstone par suppression douce, comme les
 * dossiers, en prévision d'une synchronisation multi-appareils ultérieure. Le
 * tombstone est vidé de son contenu au moment même de la suppression (voir
 * `booted()`).
 */
class DossierEcheance extends Model
{
    /** @use HasFactory<DossierEcheanceFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** Catégories d'échéance (pilotent l'affichage et les préréglages par type). */
    public const TYPES = ['audience', 'delai_procedure', 'prescription', 'formalite', 'interne', 'autre'];

    /** Cycle de vie d'une échéance. */
    public const STATUSES = ['a_venir', 'fait', 'reporte', 'manque'];

    protected $fillable = [
        'id',
        'dossier_id',
        'type',
        'title',
        'due_date',
        'status',
        'trigger_event',
        'trigger_date',
        'rule_id',
        'basis_article_id',
        'is_confirmed',
        'reminders',
        'note',
        'client_created_at',
        'client_updated_at',
    ];

    protected $attributes = [
        'type' => 'autre',
        'status' => 'a_venir',
        'is_confirmed' => false,
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'trigger_date' => 'date',
            'is_confirmed' => 'boolean',
            'reminders' => 'array',
            'client_created_at' => 'integer',
            'client_updated_at' => 'integer',
        ];
    }

    /**
     * Supprimer, c'est effacer : comme pour le dossier, le tombstone ne garde
     * que son identifiant et ses horodatages (`EffaceurContenuSupprime`,
     * dashboard#205).
     */
    protected static function booted(): void
    {
        static::softDeleted(function (DossierEcheance $echeance): void {
            (new EffaceurContenuSupprime($echeance->getConnection()))->effacerEcheances([$echeance->getKey()]);
        });
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }
}
