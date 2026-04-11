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

    public function messages()
    {
        return $this->hasMany(AgentConversationMessage::class, 'conversation_id')->orderBy('created_at', 'asc');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
