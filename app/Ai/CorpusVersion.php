<?php

namespace App\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Jeton de version du corpus juridique publié.
 *
 * Il entre dans la clé du cache des réponses de l'assistant : dès qu'un texte
 * publié change (nouvel article, correction, (dé)publication, suppression), le
 * jeton est « bumpé » et toutes les réponses mises en cache deviennent
 * inatteignables. On ne risque ainsi jamais de resservir une réponse citant du
 * droit périmé, sans avoir à cibler des clés md5 individuelles.
 */
class CorpusVersion
{
    protected const KEY = 'mibeko:corpus_version';

    /**
     * Jeton courant (généré et mémorisé au premier accès).
     */
    public static function current(): string
    {
        return (string) Cache::rememberForever(self::KEY, fn (): string => self::token());
    }

    /**
     * Invalide le corpus : tout cache indexé sur l'ancien jeton est abandonné.
     */
    public static function bump(): void
    {
        Cache::forever(self::KEY, self::token());
    }

    /**
     * Jeton horodaté + aléatoire (unique même pour deux bumps rapprochés).
     */
    protected static function token(): string
    {
        return now()->format('YmdHisv').'-'.Str::random(8);
    }
}
