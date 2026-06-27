<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentMessageFeedback extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'agent_message_feedback';

    public const RATING_UP = 'up';

    public const RATING_DOWN = 'down';

    protected $fillable = [
        'message_id',
        'user_id',
        'rating',
        'comment',
    ];

    public function message()
    {
        return $this->belongsTo(AgentConversationMessage::class, 'message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
