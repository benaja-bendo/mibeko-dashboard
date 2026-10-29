<?php

namespace App\Services\Curation;

use Illuminate\Support\Facades\DB;

/**
 * Jumeaux d'un même acte parmi les documents vivants : même titre officiel
 * (normalisé comme `DoublonTitreDate`, `lower(trim())`) et même date
 * (`date_signature`, à défaut `date_publication`).
 *
 * Cause connue (mibeko-python#52) : un même numéro de Journal officiel publié
 * sous plusieurs fichiers PDF est structuré une fois par fichier, d'où des
 * groupes de brouillons identiques. La définition vit ici, et nulle part
 * ailleurs, pour que la commande de nettoyage, la routine de tri et la carte
 * « À traiter » du tableau de bord comptent la même chose.
 *
 * Aucun modèle Eloquent : ils visent la connexion par défaut et interrogeraient
 * le développement sans que rien ne le montre (docs/infra/production.md § 5).
 * Lecture seule, quelle que soit la connexion.
 */
class DoublonsBrouillons
{
    public const SIGNALEMENT = 'doublon_titre_date';

    /** Longueur de texte minimale (plus court / plus long) pour que deux jumeaux soient un même texte. */
    private const RATIO_LONGUEUR_MIN = 0.9;

    /**
     * Un groupe n'est écarté automatiquement que si c'est exactement le
     * mécanisme de mibeko-python#52 : le même numéro de JO ingéré depuis
     * plusieurs fichiers PDF (`source_url` distincts, même `congo-jo-AAAA-N`),
     * donnant des brouillons interchangeables sans perte : tous brouillons, de
     * même type, de même nombre d'articles, de longueurs de texte voisines,
     * sans aucun article déjà supprimé auparavant (la restauration d'un
     * document rétablit TOUS ses articles supprimés, `LegalDocument::booted`,
     * donc elle ne serait plus exacte). Deux actes homonymes d'un même fichier,
     * ou de JO différents, sont de vrais actes distincts : titre et date ne
     * suffisent pas, d'où le critère de source. Tout autre groupe va au
     * rapport, pour un humain.
     *
     * @return array{
     *     groupes: int,
     *     ecartes: list<array<string, mixed>>,
     *     a_instruire: list<array<string, mixed>>,
     *     signalements_a_resoudre: list<array<string, mixed>>
     * }
     */
    public function analyser(string $connexion): array
    {
        $groupes = $this->groupes($connexion);

        $ecartes = [];
        $aInstruire = [];
        $conserves = [];
        $enGroupe = [];

        foreach ($groupes as $membres) {
            foreach ($membres as $membre) {
                $enGroupe[$membre['id']] = true;
            }

            $motifs = $this->motifsDeRefus($membres);

            if ($motifs !== []) {
                $aInstruire[] = $this->rapport($membres, $motifs);

                continue;
            }

            usort($membres, $this->parQualiteDecroissante(...));
            $gardien = array_shift($membres);
            $conserves[$gardien['id']] = true;

            foreach ($membres as $jumeau) {
                $ecartes[] = [
                    'id' => $jumeau['id'],
                    'titre' => $jumeau['titre'],
                    'type' => $jumeau['type'],
                    'date' => $jumeau['date'],
                    'articles' => $jumeau['nb_articles'],
                    'bloquants' => $jumeau['nb_bloquants'],
                    'conserve_id' => $gardien['id'],
                    'conserve_bloquants' => $gardien['nb_bloquants'],
                ];
            }
        }

        return [
            'groupes' => count($groupes),
            'ecartes' => $ecartes,
            'a_instruire' => $aInstruire,
            'signalements_a_resoudre' => $this->signalementsPerimes($connexion, $conserves, $enGroupe),
        ];
    }

    /**
     * Groupes de 2 documents vivants ou plus, chacun avec ses mesures.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function groupes(string $connexion): array
    {
        $lignes = DB::connection($connexion)->select(<<<'SQL'
            select d.id, d.type_code, d.titre_officiel, d.curation_status, d.created_at,
                   coalesce(d.metadata::jsonb->>'source_url', '') as source_url,
                   lower(trim(d.titre_officiel)) as cle_titre,
                   coalesce(d.date_signature, d.date_publication)::date as cle_date
            from legal_documents d
            where d.deleted_at is null
              and trim(coalesce(d.titre_officiel, '')) <> ''
              and coalesce(d.date_signature, d.date_publication) is not null
            SQL);

        $parCle = [];

        foreach ($lignes as $ligne) {
            $parCle[$ligne->cle_titre.'|'.$ligne->cle_date][] = $ligne;
        }

        $parCle = array_filter($parCle, fn (array $membres) => count($membres) > 1);
        ksort($parCle);

        $ids = [];

        foreach ($parCle as $membres) {
            array_push($ids, ...array_map(fn ($m) => $m->id, $membres));
        }

        $mesures = $this->mesures($connexion, $ids);
        $groupes = [];

        foreach ($parCle as $membres) {
            $groupes[] = array_map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type_code,
                'titre' => $m->titre_officiel,
                'date' => $m->cle_date,
                'statut' => $m->curation_status,
                'cree' => (string) $m->created_at,
                'source' => $m->source_url,
            ] + $mesures[$m->id], $membres);
        }

        return $groupes;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{nb_articles: int, nb_articles_supprimes: int, nb_bloquants: int, nb_caracteres: int}>
     */
    private function mesures(string $connexion, array $ids): array
    {
        $mesures = [];

        foreach (array_chunk($ids, 500) as $lot) {
            $lignes = DB::connection($connexion)->table('legal_documents as d')
                ->whereIn('d.id', $lot)
                ->selectRaw('d.id')
                ->selectRaw('(select count(*) from articles a where a.document_id = d.id and a.deleted_at is null) as nb_articles')
                ->selectRaw('(select count(*) from articles a where a.document_id = d.id and a.deleted_at is not null) as nb_articles_supprimes')
                ->selectRaw('(select coalesce(sum(length(v.contenu_texte)), 0) from articles a join article_versions v on v.article_id = a.id and v.deleted_at is null where a.document_id = d.id and a.deleted_at is null) as nb_caracteres')
                // Hors `doublon_titre_date` : c'est ce signalement qu'on résout, il ne dit rien de la qualité du texte.
                ->selectRaw("(select count(*) from curation_flags f where f.document_id = d.id and f.resolved = false and (f.severity = 'blocking' or f.severity is null) and f.type_probleme <> ?) as nb_bloquants", [self::SIGNALEMENT])
                ->get();

            foreach ($lignes as $ligne) {
                $mesures[$ligne->id] = [
                    'nb_articles' => (int) $ligne->nb_articles,
                    'nb_articles_supprimes' => (int) $ligne->nb_articles_supprimes,
                    'nb_bloquants' => (int) $ligne->nb_bloquants,
                    'nb_caracteres' => (int) $ligne->nb_caracteres,
                ];
            }
        }

        return $mesures;
    }

    /**
     * @param  list<array<string, mixed>>  $membres
     * @return list<string>
     */
    private function motifsDeRefus(array $membres): array
    {
        return array_values(array_filter([
            array_values(array_unique(array_column($membres, 'statut'))) !== ['draft']
                ? 'un membre au moins n\'est pas un brouillon' : null,
            count(array_unique(array_column($membres, 'type'))) > 1 ? 'types différents' : null,
            count(array_unique(array_column($membres, 'nb_articles'))) > 1 ? 'nombres d\'articles différents' : null,
            max(array_column($membres, 'nb_articles_supprimes')) > 0
                ? 'articles déjà supprimés avant (restauration non exacte)' : null,
            $this->memeNumeroDeJo($membres) ? null : 'pas le même numéro de JO (ou source inconnue)',
            count(array_unique(array_column($membres, 'source'))) < count($membres)
                ? 'deux jumeaux viennent du même fichier (actes distincts de même titre ?)' : null,
            $this->ratioDeLongueur($membres) < self::RATIO_LONGUEUR_MIN ? 'textes de longueurs très différentes' : null,
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $membres
     */
    private function memeNumeroDeJo(array $membres): bool
    {
        $numeros = array_map(
            fn (array $m) => preg_match('~congo-jo-(\d{4})-(\d+)~i', $m['source'], $trouve) === 1 ? $trouve[1].'-'.$trouve[2] : null,
            $membres,
        );

        return ! in_array(null, $numeros, true) && count(array_unique($numeros)) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $membres
     */
    private function ratioDeLongueur(array $membres): float
    {
        $longueurs = array_column($membres, 'nb_caracteres');

        return max($longueurs) > 0 ? min($longueurs) / max($longueurs) : 1.0;
    }

    /**
     * Le jumeau à garder : le moins de bloquants (hors doublon), puis le plus
     * d'articles, puis le plus ancien, puis l'identifiant, pour que deux
     * exécutions donnent le même choix.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function parQualiteDecroissante(array $a, array $b): int
    {
        return [$a['nb_bloquants'], -$a['nb_articles'], $a['cree'], $a['id']]
            <=> [$b['nb_bloquants'], -$b['nb_articles'], $b['cree'], $b['id']];
    }

    /**
     * @param  list<array<string, mixed>>  $membres
     * @param  list<string>  $motifs
     * @return array<string, mixed>
     */
    private function rapport(array $membres, array $motifs): array
    {
        return [
            'titre' => $membres[0]['titre'],
            'date' => $membres[0]['date'],
            'motifs' => $motifs,
            'documents' => array_map(fn (array $m) => [
                'id' => $m['id'],
                'statut' => $m['statut'],
                'type' => $m['type'],
                'articles' => $m['nb_articles'],
                'source' => $m['source'],
            ], $membres),
        ];
    }

    /**
     * Signalements `doublon_titre_date` encore ouverts alors que le document
     * n'a plus de jumeau vivant (ou n'en aura plus une fois les jumeaux
     * écartés). Les signalements ne se referment jamais d'eux-mêmes
     * (`JeuDeDetecteurs::poserSignalement` ne fait que poser) : sans cette
     * passe, l'exemplaire conservé resterait bloqué pour la publication par un
     * doublon qui n'existe plus.
     *
     * @param  array<string, true>  $conserves  exemplaires gardés dans les groupes écartés
     * @param  array<string, true>  $enGroupe  tous les documents appartenant à un groupe de jumeaux
     * @return list<array<string, mixed>>
     */
    private function signalementsPerimes(string $connexion, array $conserves, array $enGroupe): array
    {
        $ouverts = DB::connection($connexion)->table('curation_flags as f')
            ->join('legal_documents as d', 'd.id', '=', 'f.document_id')
            ->whereNull('d.deleted_at')
            ->where('d.curation_status', '<>', 'published')
            ->where('f.resolved', false)
            ->where('f.type_probleme', self::SIGNALEMENT)
            ->orderBy('f.created_at')->orderBy('f.id')
            ->get(['f.id as signalement_id', 'f.document_id']);

        $perimes = [];

        foreach ($ouverts as $ligne) {
            $id = $ligne->document_id;

            // Un jumeau écarté disparaît, un groupe laissé à un humain garde ses
            // jumeaux : dans les deux cas le signalement reste vrai.
            if (isset($enGroupe[$id]) && ! isset($conserves[$id])) {
                continue;
            }

            $perimes[] = [
                'signalement_id' => $ligne->signalement_id,
                'document_id' => $id,
                'motif' => isset($conserves[$id]) ? 'exemplaire conservé' : 'plus de jumeau vivant',
            ];
        }

        return $perimes;
    }
}
