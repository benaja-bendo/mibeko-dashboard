<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Lot de veille légale réservé, et état de sa diffusion.
 *
 * Créé par `LegalWatchNotifier` dans la transaction de réservation, consommé
 * par `SendLegalWatchNotifications`, rejoué par `mibeko:retry-legal-watch`.
 *
 * @property string $id
 * @property array<int, string> $document_ids
 * @property int $document_count
 * @property string $status
 */
class LegalWatchDispatch extends Model
{
    use HasUuids;

    /** Réservé, pas encore diffusé (ou diffusion en cours). */
    public const STATUS_PENDING = 'pending';

    /** Diffusion terminée : lignes in-app écrites et push mis en file. */
    public const STATUS_DELIVERED = 'delivered';

    /** Le job a épuisé ses tentatives : à rejouer explicitement. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'document_ids',
        'document_count',
        'status',
        'in_app_written_at',
        'pushes_dispatched_at',
        'delivered_at',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'document_ids' => 'array',
        'in_app_written_at' => 'datetime',
        'pushes_dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Lots dont la diffusion n'est pas allée à son terme : en attente depuis
     * trop longtemps (job perdu, worker arrêté) ou en échec définitif.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUndelivered(Builder $query, int $olderThanMinutes = 0): Builder
    {
        return $query->whereNot('status', self::STATUS_DELIVERED)
            ->when(
                $olderThanMinutes > 0,
                fn (Builder $q) => $q->where('created_at', '<=', now()->subMinutes($olderThanMinutes)),
            );
    }
}
