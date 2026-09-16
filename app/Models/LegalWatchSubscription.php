<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Abonnement d'un utilisateur à un texte (`LegalDocument`) ou à un thème
 * (`Tag`) — mibeko-dashboard#125. Distinct du réglage global
 * `notification_preferences` : celui-ci cible un texte ou un thème précis,
 * l'autre gouverne la diffusion générale.
 */
class LegalWatchSubscription extends Model
{
    use HasFactory, HasUuids;

    /** Types de cible acceptés par l'API (valeur de `watchable_type`). */
    public const WATCHABLE_DOCUMENT = LegalDocument::class;

    public const WATCHABLE_THEME = Tag::class;

    public const WATCHABLE_TYPES = [self::WATCHABLE_DOCUMENT, self::WATCHABLE_THEME];

    protected $fillable = [
        'user_id',
        'watchable_type',
        'watchable_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function watchable(): MorphTo
    {
        return $this->morphTo();
    }
}
