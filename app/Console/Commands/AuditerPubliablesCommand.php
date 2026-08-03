<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trie les brouillons entre « prêt à publier » et « à instruire », en lecture
 * seule, et écrit la liste des prêts au format attendu par `mibeko:publier-vague`.
 *
 * Pourquoi cette commande existe : les garde-fous de publication ne regardent que
 * la structure (≥ 1 article, anomalies `blocking` non résolues). Ils laissent
 * donc passer des documents qui n'auraient jamais dû devenir des documents — le
 * découpage des Journaux officiels promeut des fins de phrase en actes
 * (« arrêté pourra faire l'objet d'une suspension ou d'un », porté à l'identique
 * par 24 documents en production le 03/08/2026). Aucun `curation_flag` ne couvre
 * ce cas : publier sur la seule foi des flags mettrait ces fragments en ligne.
 *
 * Aucun modèle Eloquent : ils visent la connexion par défaut et interrogeraient
 * le développement sans que rien ne le montre (docs/infra/production.md § 5).
 *
 *   php artisan mibeko:auditer-publiables --connection=pgsql_prod_ro
 *   php artisan mibeko:auditer-publiables --connection=pgsql_prod_ro \
 *       --types=LOI,ORD,CODE --liste-prets=storage/app/vague-1.json
 */
class AuditerPubliablesCommand extends Command
{
    /** Un intitulé d'acte commence par sa nature. */
    private const DEBUT_CANONIQUE = '^\s*(loi|décret|decret|arrêté|arrete|ordonnance|décision|decision|code|règlement|reglement|acte)\b';

    /** Reliquat de conversion mathématique laissé par l'OCR : `$\mathbf{N}^{\circ}$`. */
    private const MOTIF_LATEX = '\$|\\\\math|\^\{';

    /**
     * Un intitulé d'acte porte un numéro (`n° 12`, `12-2023`) ou une date
     * (`21 mars 2004`, un millésime 15xx-2xxx). Un titre qui commence par un
     * mot-type sans jamais porter l'un ou l'autre n'est pas un intitulé, c'est
     * une phrase qui commence par ce mot — ex. « Loi-Cadre, cède la place à
     * une seule tête, choisie par vous. » (fragment de discours) ou « Loi sur
     * la concurrence ; et (c) adopté le Décret n° » (item d'énumération coupé),
     * publiés par erreur le 03/08/2026 avant l'ajout de ce garde-fou.
     */
    private const MOTIF_NUMERO_OU_DATE = 'n[°ºo]\s*\d|\d{1,3}[\-\/]\d{2,4}|\b(1[5-9]|20)\d{2}\b';

    protected $signature = 'mibeko:auditer-publiables
        {--connection=pgsql_prod_ro : Connexion à interroger (lecture seule par défaut)}
        {--types= : Restreint à des type_code, séparés par des virgules (ex. LOI,ORD,CODE)}
        {--liste-prets= : Fichier où écrire les documents prêts, au format --liste de publier-vague}
        {--rapport= : Fichier où écrire le détail des documents à instruire}';

    protected $description = 'Trie les brouillons en « prêt à publier » / « à instruire » (lecture seule).';

    public function handle(): int
    {
        $connexion = (string) $this->option('connection');
        $types = array_filter(array_map('trim', explode(',', (string) $this->option('types'))));

        $requete = DB::connection($connexion)
            ->table('legal_documents as d')
            ->whereNull('d.deleted_at')
            ->where('d.curation_status', 'draft')
            ->select([
                'd.id',
                'd.type_code',
                'd.titre_officiel',
                'd.document_role',
                DB::raw('(select count(*) from articles a where a.document_id = d.id and a.deleted_at is null) as nb_articles'),
                DB::raw("(select count(*) from curation_flags f where f.document_id = d.id and f.resolved = false and (f.severity = 'blocking' or f.severity is null)) as nb_bloquants"),
                DB::raw("(select count(*) from curation_flags f where f.document_id = d.id and f.resolved = false and f.severity = 'warning') as nb_warnings"),
                DB::raw('(select count(*) from legal_documents j where j.titre_officiel = d.titre_officiel and j.deleted_at is null and j.curation_status = \'draft\') as homonymes'),
            ]);

        if ($types !== []) {
            $requete->whereIn('d.type_code', $types);
        }

        $documents = $requete->orderBy('d.type_code')->orderBy('d.titre_officiel')->get();

        if ($documents->isEmpty()) {
            $this->warn('Aucun brouillon ne correspond à ces critères.');

            return self::SUCCESS;
        }

        $prets = [];
        $ainstruire = [];
        $bloques = [];

        foreach ($documents as $doc) {
            $motifs = $this->motifsDeBlocage($doc);

            if ($motifs !== []) {
                $bloques[] = ['id' => $doc->id, 'titre' => $doc->titre_officiel, 'motifs' => $motifs];

                continue;
            }

            $defauts = $this->defautsDeTitre($doc);

            if ($defauts !== []) {
                $ainstruire[] = [
                    'id' => $doc->id,
                    'type' => $doc->type_code,
                    'titre' => $doc->titre_officiel,
                    'articles' => (int) $doc->nb_articles,
                    'defauts' => $defauts,
                ];

                continue;
            }

            $prets[] = ['id' => $doc->id, 'titre' => $doc->titre_officiel];
        }

        $this->afficherLeBilan($documents->count(), $prets, $ainstruire, $bloques);
        $this->ecrire((string) $this->option('liste-prets'), $prets, 'Liste des prêts');
        $this->ecrire((string) $this->option('rapport'), $ainstruire, 'Rapport des documents à instruire');

        return self::SUCCESS;
    }

    /**
     * Ce que les garde-fous de publication refuseraient de toute façon.
     *
     * @return list<string>
     */
    private function motifsDeBlocage(object $doc): array
    {
        return array_values(array_filter([
            $doc->nb_articles == 0 ? 'aucun article' : null,
            $doc->nb_bloquants > 0 ? "{$doc->nb_bloquants} anomalie(s) bloquante(s)" : null,
        ]));
    }

    /**
     * Ce qu'aucun garde-fou ne voit : un intitulé qui trahit un document qui
     * n'aurait pas dû naître, ou un titre qu'on ne veut pas montrer au public.
     *
     * @return list<string>
     */
    private function defautsDeTitre(object $doc): array
    {
        $titre = (string) $doc->titre_officiel;

        return array_values(array_filter([
            // Deux documents ne partagent un intitulé exact que par accident de découpage.
            $doc->homonymes > 1 ? "intitulé partagé par {$doc->homonymes} documents" : null,
            preg_match('/'.self::MOTIF_LATEX.'/', $titre) === 1 ? 'LaTeX non converti' : null,
            // Une phrase coupée en plein milieu commence en minuscule ; un acte, jamais.
            preg_match('/^\p{Ll}/u', $titre) === 1 ? 'commence en minuscule (fragment probable)' : null,
            preg_match('/'.self::DEBUT_CANONIQUE.'/iu', $titre) !== 1 ? 'ne commence pas par une nature d\'acte' : null,
            preg_match('/'.self::DEBUT_CANONIQUE.'/iu', $titre) === 1
                && preg_match('/'.self::MOTIF_NUMERO_OU_DATE.'/u', $titre) !== 1
                ? 'aucun numéro ni date (probable phrase, pas un intitulé)' : null,
            mb_strlen($titre) < 25 ? 'intitulé très court' : null,
            // Une césure de fin de ligne trahit un texte repris au fil de la page.
            preg_match('/[-­]$/u', trim($titre)) === 1 ? 'se termine par une césure' : null,
        ]));
    }

    /**
     * @param  list<array{id: string, titre: string}>  $prets
     * @param  list<array<string, mixed>>  $ainstruire
     * @param  list<array<string, mixed>>  $bloques
     */
    private function afficherLeBilan(int $total, array $prets, array $ainstruire, array $bloques): void
    {
        $this->newLine();
        $this->table(
            ['Verdict', 'Documents', 'Part'],
            collect([
                ['Prêt à publier', count($prets), $this->part(count($prets), $total)],
                ['À instruire (intitulé)', count($ainstruire), $this->part(count($ainstruire), $total)],
                ['Bloqué (article / anomalie)', count($bloques), $this->part(count($bloques), $total)],
            ])->all(),
        );

        if ($ainstruire === []) {
            return;
        }

        $parDefaut = [];

        foreach ($ainstruire as $doc) {
            foreach ($doc['defauts'] as $defaut) {
                $parDefaut[$defaut] = ($parDefaut[$defaut] ?? 0) + 1;
            }
        }

        arsort($parDefaut);

        $this->newLine();
        $this->line('Motifs relevés sur les intitulés (un document peut en cumuler plusieurs) :');

        foreach ($parDefaut as $defaut => $n) {
            $this->line(sprintf('  · %-42s %d', $defaut, $n));
        }

        $this->newLine();
        $this->line('Échantillon :');

        foreach (array_slice($ainstruire, 0, 8) as $doc) {
            $this->line(sprintf('  · [%s] %s', $doc['type'], Str::limit($doc['titre'], 68)));
        }
    }

    private function part(int $n, int $total): string
    {
        return $total > 0 ? round($n * 100 / $total, 1).' %' : '—';
    }

    /**
     * @param  list<array<string, mixed>>  $donnees
     */
    private function ecrire(string $chemin, array $donnees, string $libelle): void
    {
        if ($chemin === '') {
            return;
        }

        file_put_contents($chemin, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');

        $this->newLine();
        $this->info("{$libelle} : {$chemin} (".count($donnees).' entrée(s))');
    }
}
