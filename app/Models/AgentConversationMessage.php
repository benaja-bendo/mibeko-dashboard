<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentConversationMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'agent',
        'role',
        'content',
        'attachments',
        'tool_calls',
        'tool_results',
        'usage',
        'meta',
    ];

    protected $casts = [
        'attachments' => 'array',
        'tool_calls' => 'array',
        'tool_results' => 'array',
        'usage' => 'array',
        'meta' => 'array',
    ];

    public function conversation()
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id');
    }

    /**
     * Contenu tel que l'usager l'a écrit ou reçu : celui du fil de l'assistant
     * comme celui de l'export de ses données.
     *
     * D'anciens messages utilisateur embarquent, devant la question, le
     * contexte RAG (extraits de loi) : on le retire. Quantificateur paresseux
     * (.*?) pour éviter le backtracking catastrophique sur de gros messages
     * legacy ; on retombe sur le contenu d'origine si la regex échoue (jamais
     * de null silencieux qui supprimerait le message du fil).
     */
    public function displayContent(): string
    {
        $content = (string) $this->content;

        if ($this->role !== 'user') {
            return $content;
        }

        $pattern = '/Voici les extraits de loi pertinents trouvés dans la base Mibeko :\s*.*?Question de l\'utilisateur : /s';

        return preg_replace($pattern, '', $content) ?? $content;
    }
}
