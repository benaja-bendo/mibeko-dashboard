<?php

namespace App\Services\Curation;

use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use Illuminate\Support\Collection;

/**
 * Détecte, dans le corps d'un acte, qu'il modifie ou abroge un texte déjà
 * présent dans le corpus (dashboard#123).
 *
 * Jamais de certitude : chaque détection produit une ligne `document_relations`
 * au statut `candidate` — un relecteur humain la valide ou la rejette, cette
 * classe n'écrit jamais `confirmed`. Quand la référence détectée ne pointe
 * vers aucun texte du corpus, ou vers plusieurs à la fois, aucune relation
 * n'est créée : le cas est rendu visible via un `CurationFlag` plutôt que
 * silencieusement perdu (« statut inconnu préservé », critère de terminé du
 * ticket) — jamais une relation devinée au hasard entre plusieurs candidats.
 *
 * Portée volontairement restreinte à MODIFIE et ABROGE (titre du ticket) :
 * CREE/CITE/COMPLETE/RENUMEROTE restent créables à la main via l'API
 * `POST /document-relations` existante, non auto-détectés ici.
 */
class RelationCandidateDetector
{
    /**
     * Verbe(s) reconnus par relation, avec leurs variantes de conjugaison
     * courantes dans le dispositif d'un acte congolais.
     *
     * @var array<string, string>
     */
    private const VERBES = [
        DocumentRelation::TYPE_ABROGE => 'abrog(?:e|ent|é|ée|és|ées|eant)',
        DocumentRelation::TYPE_MODIFIE => 'modifi(?:e|ent|é|ée|és|ées|iant)',
    ];

    /** Types d'acte reconnus dans une référence, et leur forme canonique pour la recherche de cible. */
    private const MOTS_TYPE = [
        'décret' => 'décret', 'decret' => 'décret',
        'arrêté' => 'arrêté', 'arrete' => 'arrêté', 'arrêtê' => 'arrêté',
        'loi' => 'loi',
        'ordonnance' => 'ordonnance',
        'décision' => 'décision', 'decision' => 'décision',
    ];

    private const MOIS = [
        'janvier' => 1, 'février' => 2, 'fevrier' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8, 'aout' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12, 'decembre' => 12,
    ];

    /** Confiance quand un seul texte correspond et que sa date concorde. */
    private const CONFIANCE_HAUTE = 0.9;

    /** Confiance quand un seul texte correspond mais sans date confirmée. */
    private const CONFIANCE_MOYENNE = 0.6;

    /**
     * @return array{candidats: array<int, DocumentRelation>, ambigus: int}
     */
    public function detecter(LegalDocument $document): array
    {
        $candidats = [];
        $ambigus = 0;

        foreach ($document->articles as $article) {
            $texte = ($article->activeVersion ?: $article->latestVersion)?->contenu_texte;

            if (! $texte) {
                continue;
            }

            foreach (self::VERBES as $type => $verbes) {
                if (! preg_match_all($this->motif($verbes), $texte, $correspondances, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($correspondances as $correspondance) {
                    $resultat = $this->traiter($document, $article, $type, $correspondance);

                    if ($resultat instanceof DocumentRelation) {
                        $candidats[] = $resultat;
                    } elseif ($resultat === 'ambigu') {
                        $ambigus++;
                    }
                }
            }
        }

        return ['candidats' => $candidats, 'ambigus' => $ambigus];
    }

    private function motif(string $verbes): string
    {
        $typesActe = implode('|', array_keys(self::MOTS_TYPE));

        return '/\b(?:'.$verbes.')\b[^.;:]{0,80}?\b(?<type>'.$typesActe.')\s+n[°ºo]\s*'
            .'(?<numero>[\dA-Za-z\/\p{Pd}]+)'
            .'(?:\s+du\s+(?<jour>\d{1,2})(?:er)?\s+(?<mois>\p{L}+)\s+(?<annee>\d{4}))?/iu';
    }

    /**
     * @param  array<string, string>  $correspondance
     * @return DocumentRelation|'ambigu'|null
     */
    private function traiter(LegalDocument $document, Article $article, string $type, array $correspondance): DocumentRelation|string|null
    {
        $numero = trim($correspondance['numero']);
        $reference = trim($correspondance[0]);
        $dateDetectee = $this->dateDetectee($correspondance);

        $cibles = $this->chercherCibles($document, $numero, $correspondance['type']);

        if ($cibles->count() !== 1) {
            $this->signalerAmbiguite($document, $article, $type, $reference, $cibles);

            return 'ambigu';
        }

        $cible = $cibles->first();

        if ($this->relationDejaTraitee($article, $cible, $type)) {
            return null;
        }

        $dateConcorde = $dateDetectee !== null && (
            $cible->date_signature?->toDateString() === $dateDetectee
            || $cible->date_publication?->toDateString() === $dateDetectee
        );

        return DocumentRelation::create([
            'source_doc_id' => $document->id,
            'source_article_id' => $article->id,
            'target_doc_id' => $cible->id,
            'relation_type' => $type,
            'status' => DocumentRelation::STATUS_CANDIDATE,
            'source' => DocumentRelation::SOURCE_HEURISTIC,
            'confidence' => $dateConcorde ? self::CONFIANCE_HAUTE : self::CONFIANCE_MOYENNE,
            'meta' => [
                'extrait_source' => $reference,
                'numero_detecte' => $numero,
            ],
        ]);
    }

    private function chercherCibles(LegalDocument $source, string $numero, string $typeMot): Collection
    {
        $typeCanonique = self::MOTS_TYPE[mb_strtolower($typeMot)] ?? null;

        return LegalDocument::query()
            ->where('id', '!=', $source->id)
            ->where('titre_officiel', 'ilike', "%{$numero}%")
            ->when($typeCanonique, fn ($q) => $q->where('titre_officiel', 'ilike', "%{$typeCanonique}%"))
            ->get();
    }

    private function relationDejaTraitee(Article $article, LegalDocument $cible, string $type): bool
    {
        return DocumentRelation::query()
            ->where('source_article_id', $article->id)
            ->where('target_doc_id', $cible->id)
            ->where('relation_type', $type)
            ->exists();
    }

    /**
     * @param  array<string, string>  $correspondance
     */
    private function dateDetectee(array $correspondance): ?string
    {
        $mois = self::MOIS[mb_strtolower($correspondance['mois'] ?? '')] ?? null;
        $jour = $correspondance['jour'] ?? null;
        $annee = $correspondance['annee'] ?? null;

        if ($mois === null || ! $jour || ! $annee) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $annee, $mois, (int) $jour);
    }

    /**
     * Rend visible, sans deviner, une référence détectée qui ne pointe vers
     * aucun texte du corpus ou vers plusieurs à la fois — jamais silencieuse,
     * jamais recréée en double au prochain passage sur le même article.
     */
    private function signalerAmbiguite(LegalDocument $document, Article $article, string $type, string $reference, Collection $cibles): void
    {
        $dejaSignale = CurationFlag::query()
            ->where('article_id', $article->id)
            ->where('type_probleme', 'relation_ambigue')
            ->where('resolved', false)
            ->get()
            ->contains(fn (CurationFlag $flag) => ($flag->suggestion['reference_detectee'] ?? null) === $reference);

        if ($dejaSignale) {
            return;
        }

        $description = $cibles->isEmpty()
            ? "Référence détectée « {$reference} » ({$type}) : aucun texte correspondant trouvé dans le corpus."
            : "Référence détectée « {$reference} » ({$type}) : {$cibles->count()} textes correspondent, à départager manuellement.";

        CurationFlag::create([
            'document_id' => $document->id,
            'article_id' => $article->id,
            'type_probleme' => 'relation_ambigue',
            'description' => $description,
            'source' => CurationFlag::SOURCE_HEURISTIC,
            'severity' => CurationFlag::SEVERITY_INFO,
            'suggestion' => [
                'relation_type' => $type,
                'reference_detectee' => $reference,
                'candidats' => $cibles->pluck('id')->all(),
            ],
        ]);
    }
}
