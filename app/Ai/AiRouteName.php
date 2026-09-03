<?php

namespace App\Ai;

use Illuminate\Http\Request;

/**
 * Nom de route canonique pour le journal d'usage IA (mibeko-dashboard#61).
 *
 * Un seul endroit qui sait dériver `assistant/chat` de
 * `api/v1/assistant/chat/<uuid>` : le limiteur de débit (avant le
 * contrôleur) et les contrôleurs eux-mêmes (constante littérale) doivent
 * retomber sur la même valeur, sinon le coût par route devient incohérent.
 */
class AiRouteName
{
    public const ASSISTANT_CHAT = 'assistant/chat';

    public const LIBRARY_EXPLAIN = 'library/explain';

    public const LIBRARY_SYNTHESIS = 'library/synthesis';

    /**
     * Résout une route suivie par #61, ou `null` en dehors de ce périmètre.
     *
     * Le limiteur `ai_assistant` est partagé par d'autres endpoints IA
     * (`legal-documents/{id}/suggest-themes`, `legal-documents/{id}/analyze-ai`
     * — outillage éditeur, hors périmètre de la tarification côté usager).
     * Retourner leur chemin brut a fait déborder la colonne `route`
     * (varchar 40) dès le premier 429 sur `analyze-ai` : mieux vaut ne rien
     * journaliser pour ces routes que journaliser un chemin tronqué ou
     * ambigu.
     */
    public static function fromRequest(Request $request): ?string
    {
        $path = $request->path();

        return match (true) {
            str_contains($path, 'assistant/chat') => self::ASSISTANT_CHAT,
            str_contains($path, 'library/explain') => self::LIBRARY_EXPLAIN,
            str_contains($path, 'library/synthesis') => self::LIBRARY_SYNTHESIS,
            default => null,
        };
    }
}
