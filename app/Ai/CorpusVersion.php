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
     *
     * @param  string|null  $connexion  Connexion DB à cibler pour le store
     *                                  `database` (ex. `pgsql_prod_rw`) — sans
     *                                  elle, la connexion par défaut de l'appli
     *                                  (jamais la prod depuis une session dev,
     *                                  d'où l'intérêt de ce paramètre pour
     *                                  `mibeko:invalider-cache-corpus`).
     */
    public static function bump(?string $connexion = null): void
    {
        if ($connexion === null) {
            Cache::forever(self::KEY, self::token());

            return;
        }

        config(['cache.stores.database.connection' => $connexion]);
        Cache::forgetDriver('database');
        Cache::store('database')->forever(self::KEY, self::token());
    }

    /**
     * Jeton horodaté + aléatoire (unique même pour deux bumps rapprochés).
     */
    protected static function token(): string
    {
        return now()->format('YmdHisv').'-'.Str::random(8);
    }
}
