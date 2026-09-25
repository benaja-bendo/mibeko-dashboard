<?php

namespace App\Support;

/**
 * Fragments SQL pour vider les valeurs du journal owen-it (`audits.old_values`
 * et `new_values`, du JSON stocké en texte) sans effacer la ligne.
 *
 * Quand un contenu part à la demande de l'usager, sa trace reste — l'événement,
 * la date, la liste des champs modifiés — mais plus ce qu'ils valaient. Sert à
 * la purge des comptes (`mibeko:purger-comptes-supprimes`).
 */
class ValeursAudit
{
    /**
     * Garde les clés d'un objet JSON (quels champs ont changé), remplace ses
     * valeurs par null ; laisse tel quel un tableau vide ou une valeur nulle.
     */
    public static function effacees(string $colonne): string
    {
        return <<<SQL
            case when {$colonne} is not null and jsonb_typeof({$colonne}::jsonb) = 'object'
                 then coalesce((select jsonb_object_agg(cle, 'null'::jsonb) from jsonb_object_keys({$colonne}::jsonb) as cle), '{}'::jsonb)::text
                 else {$colonne}
            end
            SQL;
    }
}
