<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitation d'un membre d'équipe (admin/editor) à rejoindre Mibeko.
 *
 * Le `token` est conservé hashé. Une invitation est « en attente » tant que
 * `accepted_at` est nul et que `expires_at` n'est pas dépassé.
 */
class UserInvitation extends Model
{
    use HasUuids;

    protected $fillable = [
        'email',
        'token',
        'roles',
        'invited_by',
        'accepted_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Admin à l'origine de l'invitation.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Vrai si l'invitation est encore exploitable (non acceptée, non expirée).
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
