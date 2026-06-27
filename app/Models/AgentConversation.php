<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentConversation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'title',
    ];

    protected static function booted(): void
    {
        // La table du package n'a pas de contrainte ON DELETE CASCADE : on
        // supprime explicitement les messages pour ne pas laisser d'orphelins
        // s'accumuler en base à chaque suppression de conversation.
        static::deleting(function (AgentConversation $conversation): void {
            $conversation->messages()->delete();
        });
    }

    public function messages()
    {
        // created_at étant tronqué à la seconde, on départage par id (UUID v7,
        // chronologique) : question et réponse d'un même tour ne s'inversent pas.
        return $this->hasMany(AgentConversationMessage::class, 'conversation_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
