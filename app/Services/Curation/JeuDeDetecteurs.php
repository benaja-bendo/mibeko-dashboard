<?php

namespace App\Services\Curation;

use App\Models\CurationFlag;
use App\Models\DocumentControleRun;
use App\Models\LegalDocument;
use App\Services\Curation\Detecteurs\ArtefactTechniqueResiduel;
use App\Services\Curation\Detecteurs\D10TitreTronque;
use App\Services\Curation\Detecteurs\D1NumeroDoublon;
use App\Services\Curation\Detecteurs\D2NumeroHorsListeBlanche;
use App\Services\Curation\Detecteurs\D3ArticleAmputeDebut;
use App\Services\Curation\Detecteurs\D4EnteteJoIncruste;
use App\Services\Curation\Detecteurs\D5FragmentSommaire;
use App\Services\Curation\Detecteurs\D6LatexResiduel;
use App\Services\Curation\Detecteurs\D7ContenuQuasiVide;
use App\Services\Curation\Detecteurs\D8ConfusionOcr;
use App\Services\Curation\Detecteurs\D9BalisageHtmlBrut;
use App\Services\Curation\Detecteurs\DetecteurContenu;
use App\Services\Curation\Detecteurs\DoublonTitreDate;
use App\Services\Curation\Detecteurs\PseudoTitre;
use App\Services\Curation\Detecteurs\SequenceRepartAUn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jeu de détecteurs de CONTENU v3 (mibeko-dashboard#141, § 3.5 du plan
 * « boîte de réception »). Onze détecteurs portent la requête SQL v2 du
 * corps de `#23`, un par condition de son CTE `flags`/`d10` ; trois ajouts
 * v3 (`PseudoTitre`, `DoublonTitreDate`, `SequenceRepartAUn`) comblent les
 * trous connus (`#41`, protocole étape 2) — voir chaque classe de
 * `Detecteurs/` pour sa condition d'origine et sa justification.
 *
 * Distinct de `StructuralAnomalyDetector` : celui-ci lit l'ARBRE (position
 * des feuilles), celui-ci lit le TEXTE (`contenu_texte`/`titre_officiel`).
 * `source = CurationFlag::SOURCE_CONFORMITE`, jamais `structural` — les deux
 * mécanismes purgent des ensembles disjoints, ils ne se marchent jamais
 * dessus (§ 3.5, revue technique du 14/09).
 *
 * Idempotence et exceptions (§ 3.5) : ne crée jamais un second signalement
 * pour la même identité (document, détecteur, article) tant que le dernier
 * connu — ouvert OU résolu — porte la même empreinte de contenu. Un
 * signalement ouvert n'est donc jamais dupliqué (peu importe si le contenu a
 * bougé entre-temps : la revue humaine reste sur la version actuelle du
 * texte) ; un signalement résolu (exception « fidèle à la source », `#27`)
 * ne ressort que si le contenu à son ancrage a réellement changé depuis —
 * jamais à chaque passage planifié sur un texte inchangé.
 */
class JeuDeDetecteurs
{
    /**
     * Toute évolution du jeu incrémente cette constante — le protocole
     * (règle du certificat) déclasse alors tous les runs antérieurs : ils
     * ne comptent plus dans la mesure de conformité tant qu'un nouveau
     * passage ne les a pas recontrôlés.
     */
    const VERSION = 'v3';

    /** @var array<int, DetecteurContenu> */
    private array $detecteurs;

    /**
     * @param  array<int, DetecteurContenu>|null  $detecteurs  Jeu à exécuter —
     *                                                         `null` (défaut, y compris en production) utilise le jeu v3 complet.
     *                                                         Injectable pour les tests (ex. un détecteur factice qui lève,
     *                                                         pour prouver le comportement `incomplet` sans dépendre d'un vrai
     *                                                         détecteur en échec).
     */
    public function __construct(?array $detecteurs = null)
    {
        $this->detecteurs = $detecteurs ?? [
            new D1NumeroDoublon,
            new D2NumeroHorsListeBlanche,
            new D3ArticleAmputeDebut,
            new D4EnteteJoIncruste,
            new D5FragmentSommaire,
            new D6LatexResiduel,
            new D7ContenuQuasiVide,
            new D8ConfusionOcr,
            new D9BalisageHtmlBrut,
            new D10TitreTronque,
            new ArtefactTechniqueResiduel,
            new PseudoTitre,
            new DoublonTitreDate,
            new SequenceRepartAUn,
        ];
    }

    /**
     * Contrôle un document : exécute chaque détecteur, pose les
     * signalements candidats (idempotent), et enregistre le run.
     *
     * `$version` : étiquette écrite dans `document_controle_runs.version_jeu`
     * (défaut `self::VERSION`) — sert à comparer le portage aux compteurs
     * historiques de la v2 pendant la transition (critère de clôture de
     * #141), jamais à faire tourner un jeu de détecteurs différent : le code
     * exécuté est toujours celui de cette classe.
     *
     * `$dryRun` : n'écrit NI `curation_flags` NI `document_controle_runs` —
     * l'objet renvoyé n'est jamais persisté. Le verdict ignore alors le
     * mécanisme d'idempotence/exceptions (rien n'existe encore à comparer) :
     * `echec` dès qu'un détecteur trouve au moins un candidat, exactement le
     * comportement brut de la requête SQL v2 — c'est ce qui rend la
     * comparaison de compteurs valide.
     */
    public function controler(LegalDocument $document, ?string $version = null, bool $dryRun = false): DocumentControleRun
    {
        $version ??= self::VERSION;

        $executer = function () use ($document, $version, $dryRun) {
            $resultats = [];
            $incomplet = false;
            $anomalieTrouvee = false;

            foreach ($this->detecteurs as $detecteur) {
                try {
                    $candidats = $detecteur->detecter($document);
                } catch (Throwable $e) {
                    // Un détecteur en échec ne doit jamais faire échouer les
                    // autres (protocole, étape 3 : « un détecteur non
                    // exécuté se déclare non exécuté ») — jamais `null`
                    // silencieux dans `resultats`, la valeur `false` dit
                    // explicitement « non évalué », distincte de `0`.
                    $resultats[$detecteur->code()] = false;
                    $incomplet = true;
                    Log::error('JeuDeDetecteurs : détecteur en échec', [
                        'document_id' => $document->id,
                        'detecteur' => $detecteur->code(),
                        'exception' => $e->getMessage(),
                    ]);

                    continue;
                }

                $resultats[$detecteur->code()] = count($candidats);
                if (count($candidats) > 0) {
                    $anomalieTrouvee = true;
                }
                if (! $dryRun) {
                    foreach ($candidats as $candidat) {
                        $this->poserSignalement($document, $detecteur, $candidat);
                    }
                }
            }

            if ($dryRun) {
                $reserveOuverte = $anomalieTrouvee;
            } else {
                $reserveOuverte = CurationFlag::where('document_id', $document->id)
                    ->where('source', CurationFlag::SOURCE_CONFORMITE)
                    ->where('resolved', false)
                    ->exists();
            }

            $resultat = match (true) {
                $incomplet => DocumentControleRun::RESULTAT_INCOMPLET,
                $reserveOuverte => DocumentControleRun::RESULTAT_ECHEC,
                default => DocumentControleRun::RESULTAT_OK,
            };

            $attributs = [
                'document_id' => $document->id,
                'version_jeu' => $version,
                'date' => now(),
                'resultats' => $resultats,
                'resultat' => $resultat,
            ];

            return $dryRun ? new DocumentControleRun($attributs) : DocumentControleRun::create($attributs);
        };

        return $dryRun ? $executer() : DB::transaction($executer);
    }

    /**
     * Pose un signalement pour un candidat — sauf s'il en existe déjà un pour
     * la même identité (document, détecteur, article) portant la MÊME
     * empreinte de contenu, qu'il soit encore ouvert ou déjà résolu (§ 3.5) :
     * un ouvert n'est jamais dupliqué (la revue humaine voit déjà le texte
     * actuel), un résolu reste une exception valable tant que le texte à cet
     * ancrage n'a pas changé. Seul un contenu different depuis le dernier
     * signalement connu (ou l'absence de tout signalement antérieur) fait
     * naître une nouvelle ligne.
     *
     * @param  array{article_id?: string, description: string, empreinte_source: string, anchor?: array<string, mixed>|null}  $candidat
     */
    private function poserSignalement(LegalDocument $document, DetecteurContenu $detecteur, array $candidat): void
    {
        $empreinte = hash('sha256', $candidat['empreinte_source']);

        // Tiebreaker sur `id` (UUID ordonné, `HasUuids`) : deux signalements
        // posés dans la même seconde ne doivent jamais rendre ce choix
        // arbitraire (même piège que l'ordre par `date` seul sur
        // document_controle_runs, tests/Feature/DocumentControleRunTest.php).
        $dernierSignalement = CurationFlag::where('document_id', $document->id)
            ->where('type_probleme', $detecteur->code())
            ->where('article_id', $candidat['article_id'] ?? null)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($dernierSignalement !== null) {
            $empreinteConnue = $dernierSignalement->anchor['empreinte'] ?? null;
            if (! $dernierSignalement->resolved || $empreinteConnue === $empreinte) {
                return;
            }
        }

        CurationFlag::create([
            'document_id' => $document->id,
            'article_id' => $candidat['article_id'] ?? null,
            'source' => CurationFlag::SOURCE_CONFORMITE,
            'type_probleme' => $detecteur->code(),
            'severity' => $detecteur->severity(),
            'description' => $candidat['description'],
            'anchor' => array_merge($candidat['anchor'] ?? [], ['empreinte' => $empreinte]),
            'resolved' => false,
        ]);
    }
}
