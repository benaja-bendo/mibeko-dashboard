<?php

namespace App\Services\Operations;

use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Liste blanche et exécution des lots de la file d'opérations Classe 1
 * (docs/infra/production.md § 6 bis, décision du 08/08/2026).
 *
 * Le périmètre Classe 1 est la curation de staging, rien d'autre : les
 * curation_flags (créer, résoudre, rouvrir) et les métadonnées ou transitions
 * de `curation_status` de documents JAMAIS publiés (draft/review/validated) —
 * jamais vers `published`, jamais un document publié ni l'ayant été. Tout le
 * reste relève de la Classe 2 (méthode en 4 temps, autorisation par opération
 * dans le chat).
 *
 * Toute écriture passe par Eloquent, jamais par du SQL brut : la machine à
 * états de LegalDocument, l'audit owen-it/auditing et la policy
 * `documents.update` s'appliquent donc comme dans le reste de l'application.
 */
class OperationsClasseUne
{
    /** @var list<string> Opérations autorisées — tout autre lot est rejeté. */
    public const OPERATIONS = [
        'creer_signalements',
        'resoudre_signalements',
        'rouvrir_signalements',
        'changer_statut_documents',
        'modifier_metadonnees_documents',
        'retirer_documents_staging',
    ];

    /** @var list<string> Statuts de staging : un document Classe 1 en vient ET y reste. */
    public const STATUTS_STAGING = [
        LegalDocument::STATUS_DRAFT,
        LegalDocument::STATUS_REVIEW,
        LegalDocument::STATUS_VALIDATED,
    ];

    /**
     * Métadonnées modifiables par lot. `curation_status` en est exclu à
     * dessein (opération dédiée, seule à passer par la machine à états) ;
     * l'identité structurelle (document_role, stock_code, document_key) et
     * les champs pilotés par le pipeline (extraction_status) aussi.
     *
     * @var list<string>
     */
    public const CHAMPS_METADONNEES = [
        'titre_officiel',
        'slug',
        'reference_nor',
        'date_signature',
        'date_publication',
        'date_entree_vigueur',
        'date_entree_vigueur_inconnue',
        'legal_scope',
        'type_code',
        'institution_id',
    ];

    /** @var list<string> */
    private const SEVERITES = [
        CurationFlag::SEVERITY_BLOCKING,
        CurationFlag::SEVERITY_WARNING,
        CurationFlag::SEVERITY_INFO,
    ];

    /**
     * Contrôle un lot SANS rien écrire : structure du fichier, liste blanche,
     * éligibilité Classe 1 des cibles, droits du porteur du jeton.
     *
     * @param  array<string, mixed>  $lot
     * @return list<string> Violations constatées (vide = lot conforme).
     */
    public function violations(array $lot, User $utilisateur): array
    {
        $violations = $this->violationsStructure($lot);

        if ($violations !== []) {
            return $violations;
        }

        /** @var array<string, mixed> $params */
        $params = $lot['params'];

        return match ($lot['operation']) {
            'creer_signalements' => $this->violationsCreerSignalements($params, $utilisateur),
            'resoudre_signalements',
            'rouvrir_signalements' => $this->violationsSurSignalementsExistants($params, $utilisateur),
            'changer_statut_documents' => $this->violationsChangerStatut($params, $utilisateur),
            'modifier_metadonnees_documents' => $this->violationsModifierMetadonnees($params, $utilisateur),
            'retirer_documents_staging' => $this->violationsRetirerStaging($params, $utilisateur),
        };
    }

    /**
     * Décrit le lot pour l'écran de validation (cible / effet). À n'appeler
     * que sur un lot déjà déclaré conforme par `violations()`.
     *
     * @param  array<string, mixed>  $lot
     * @return array{cible: string, effet: string}
     */
    public function decrire(array $lot): array
    {
        /** @var array<string, mixed> $params */
        $params = $lot['params'];

        return match ($lot['operation']) {
            'creer_signalements' => [
                'cible' => count($params['signalements']).' signalement(s) à créer',
                'effet' => 'création de curation_flags (source human)',
            ],
            'resoudre_signalements' => [
                'cible' => count($params['ids']).' signalement(s) : '.$this->apercu($params['ids']),
                'effet' => 'resolved=true, resolved_at=maintenant, resolved_by=opérateur',
            ],
            'rouvrir_signalements' => [
                'cible' => count($params['ids']).' signalement(s) : '.$this->apercu($params['ids']),
                'effet' => 'resolved=false, resolved_at/resolved_by remis à zéro',
            ],
            'changer_statut_documents' => [
                'cible' => count($params['document_ids']).' document(s) : '.$this->apercu($params['document_ids']),
                'effet' => 'curation_status → '.$params['vers'].' (machine à états + audit)',
            ],
            'modifier_metadonnees_documents' => [
                'cible' => count($params['modifications']).' document(s) : '
                    .$this->apercu(array_column($params['modifications'], 'id')),
                'effet' => 'champs : '.implode(', ', array_unique(
                    array_merge(...array_map(fn (array $m): array => array_keys($m['champs']), $params['modifications'])),
                )),
            ],
            'retirer_documents_staging' => [
                'cible' => count($params['document_ids']).' document(s) : '.$this->apercu($params['document_ids']),
                'effet' => 'suppression DOUCE (deleted_at) — réversible par restore(), aucun octet perdu',
            ],
        };
    }

    /**
     * Exécute le lot et renvoie le nombre de lignes réellement touchées.
     *
     * À appeler dans une transaction : l'appelant compare ce nombre à
     * `expected_rows` et annule tout en cas d'écart. La liste blanche est
     * re-vérifiée ici même — l'état a pu bouger entre l'affichage et le `y`.
     *
     * @param  array<string, mixed>  $lot
     *
     * @throws RuntimeException Si le lot ne passe plus la liste blanche.
     */
    public function executer(array $lot, User $utilisateur): int
    {
        if (($violations = $this->violations($lot, $utilisateur)) !== []) {
            throw new RuntimeException('Lot non conforme : '.implode(' ; ', $violations));
        }

        /** @var array<string, mixed> $params */
        $params = $lot['params'];

        return match ($lot['operation']) {
            'creer_signalements' => $this->executerCreerSignalements($params),
            'resoudre_signalements' => $this->executerResoudreSignalements($params, $utilisateur),
            'rouvrir_signalements' => $this->executerRouvrirSignalements($params),
            'changer_statut_documents' => $this->executerChangerStatut($params),
            'modifier_metadonnees_documents' => $this->executerModifierMetadonnees($params),
            'retirer_documents_staging' => $this->executerRetirerStaging($params),
        };
    }

    // ── Structure du lot ─────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $lot
     * @return list<string>
     */
    private function violationsStructure(array $lot): array
    {
        $violations = [];

        $operation = $lot['operation'] ?? null;

        if (! is_string($operation) || ! in_array($operation, self::OPERATIONS, true)) {
            $violations[] = sprintf(
                'Opération « %s » hors liste blanche Classe 1 (autorisées : %s).',
                is_string($operation) ? $operation : gettype($operation),
                implode(', ', self::OPERATIONS),
            );
        }

        if (! is_array($lot['params'] ?? null)) {
            $violations[] = 'Champ `params` manquant ou invalide (objet attendu).';
        }

        $attendues = $lot['expected_rows'] ?? null;

        if (! is_int($attendues) || $attendues < 0) {
            $violations[] = 'Champ `expected_rows` manquant ou invalide (entier ≥ 0, mesuré sur la copie restaurée).';
        }

        $rollback = $lot['rollback'] ?? null;

        if (! is_array($rollback)
            || ! is_string($rollback['description'] ?? null) || trim($rollback['description']) === ''
            || ! is_string($rollback['moyen'] ?? null) || trim($rollback['moyen']) === '') {
            $violations[] = 'Champ `rollback` incomplet : `description` et `moyen` sont obligatoires.';
        }

        $dryRun = $lot['dry_run_output'] ?? null;

        if (! is_string($dryRun) || trim($dryRun) === '') {
            $violations[] = 'Champ `dry_run_output` manquant : un lot se prépare toujours avec un dry-run (§ 6 bis).';
        }

        $campagne = $lot['campagne'] ?? null;

        if (! is_string($campagne) || trim($campagne) === '') {
            $violations[] = 'Champ `campagne` manquant : les compteurs avant/après se consignent par campagne.';
        }

        return $violations;
    }

    // ── curation_flags ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function violationsCreerSignalements(array $params, User $utilisateur): array
    {
        $signalements = $params['signalements'] ?? null;

        if (! is_array($signalements) || $signalements === [] || ! array_is_list($signalements)) {
            return ['`params.signalements` doit être une liste non vide.'];
        }

        $violations = [];

        foreach ($signalements as $index => $signalement) {
            $rang = $index + 1;

            if (! is_array($signalement)) {
                $violations[] = "Signalement {$rang} : objet attendu.";

                continue;
            }

            $type = $signalement['type_probleme'] ?? null;

            if (! is_string($type) || trim($type) === '' || mb_strlen($type) > 50) {
                $violations[] = "Signalement {$rang} : `type_probleme` obligatoire (50 caractères max).";
            }

            $severite = $signalement['severity'] ?? null;

            if ($severite !== null && ! in_array($severite, self::SEVERITES, true)) {
                $violations[] = "Signalement {$rang} : `severity` doit valoir ".implode(', ', self::SEVERITES).'.';
            }

            $description = $signalement['description'] ?? null;

            if ($description !== null && (! is_string($description) || mb_strlen($description) > 5000)) {
                $violations[] = "Signalement {$rang} : `description` invalide (texte de 5000 caractères max).";
            }

            $document = $this->documentViseParLeSignalement($signalement);

            if ($document === null) {
                $violations[] = "Signalement {$rang} : cible introuvable (`document_id` ou `article_id` obligatoire, document non supprimé).";

                continue;
            }

            $nodeId = $signalement['node_id'] ?? null;

            if ($nodeId !== null && (! is_string($nodeId) || ! Str::isUuid($nodeId) || StructureNode::query()->find($nodeId) === null)) {
                $violations[] = "Signalement {$rang} : `node_id` ne correspond à aucune division.";
            }

            if (Gate::forUser($utilisateur)->denies('update', $document)) {
                $violations[] = "Signalement {$rang} : le jeton n'a pas le droit documents.update sur « {$document->titre_officiel} ».";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function executerCreerSignalements(array $params): int
    {
        $crees = 0;

        foreach ($params['signalements'] as $signalement) {
            // Source `human` : le lot est préparé par l'agent mais validé et
            // exécuté sous le jeton d'un éditeur humain. `report` est réservé
            // au public non authentifié, et les sources détecteurs
            // (heuristic/structural/llm) sont purgeables par les détecteurs.
            CurationFlag::create([
                'document_id' => $signalement['document_id'] ?? null,
                'article_id' => $signalement['article_id'] ?? null,
                'node_id' => $signalement['node_id'] ?? null,
                'source' => CurationFlag::SOURCE_HUMAN,
                'type_probleme' => $signalement['type_probleme'],
                'severity' => $signalement['severity'] ?? CurationFlag::SEVERITY_INFO,
                'description' => $signalement['description'] ?? null,
                'resolved' => false,
            ]);
            $crees++;
        }

        return $crees;
    }

    /**
     * Contrôles communs à `resoudre_signalements` et `rouvrir_signalements` :
     * identifiants valides, signalements existants, droit d'édition sur le
     * document visé (même garde que DocumentCurationController).
     *
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function violationsSurSignalementsExistants(array $params, User $utilisateur): array
    {
        $ids = $params['ids'] ?? null;

        if (! is_array($ids) || $ids === [] || ! array_is_list($ids)) {
            return ['`params.ids` doit être une liste non vide d\'identifiants de signalements.'];
        }

        $violations = [];
        $valides = [];

        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                $violations[] = 'Identifiant de signalement invalide : '.json_encode($id).'.';

                continue;
            }

            $valides[] = $id;
        }

        $flags = CurationFlag::query()
            ->with(['document', 'article.document'])
            ->whereIn('id', $valides)
            ->get()
            ->keyBy('id');

        foreach ($valides as $id) {
            $flag = $flags->get($id);

            if ($flag === null) {
                $violations[] = "Signalement introuvable : {$id}.";

                continue;
            }

            $document = $flag->document ?? $flag->article?->document;

            if ($document === null) {
                if (! $utilisateur->hasRole('admin')) {
                    $violations[] = "Signalement {$id} : cible supprimée, triage réservé au rôle admin.";
                }

                continue;
            }

            if (Gate::forUser($utilisateur)->denies('update', $document)) {
                $violations[] = "Signalement {$id} : le jeton n'a pas le droit documents.update sur « {$document->titre_officiel} ».";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function executerResoudreSignalements(array $params, User $utilisateur): int
    {
        $flags = CurationFlag::query()->whereIn('id', $params['ids'])->where('resolved', false)->get();

        foreach ($flags as $flag) {
            $flag->update([
                'resolved' => true,
                'resolved_at' => now(),
                'resolved_by' => $utilisateur->id,
            ]);
        }

        return $flags->count();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function executerRouvrirSignalements(array $params): int
    {
        $flags = CurationFlag::query()->whereIn('id', $params['ids'])->where('resolved', true)->get();

        foreach ($flags as $flag) {
            $flag->update([
                'resolved' => false,
                'resolved_at' => null,
                'resolved_by' => null,
            ]);
        }

        return $flags->count();
    }

    // ── Documents jamais publiés ─────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function violationsChangerStatut(array $params, User $utilisateur): array
    {
        $vers = $params['vers'] ?? null;

        if (! is_string($vers) || ! in_array($vers, self::STATUTS_STAGING, true)) {
            return [sprintf(
                'Statut cible « %s » hors liste blanche : un lot Classe 1 ne vise que %s — la publication passe par l\'API Laravel (Classe 2).',
                is_string($vers) ? $vers : gettype($vers),
                implode('/', self::STATUTS_STAGING),
            )];
        }

        $ids = $params['document_ids'] ?? null;

        if (! is_array($ids) || $ids === [] || ! array_is_list($ids)) {
            return ['`params.document_ids` doit être une liste non vide d\'identifiants de documents.'];
        }

        $violations = [];

        foreach ($ids as $id) {
            [$document, $violationsDocument] = $this->documentClasseUne($id, $utilisateur);
            $violations = [...$violations, ...$violationsDocument];

            if ($document === null) {
                continue;
            }

            $de = (string) $document->curation_status;

            if ($de === $vers) {
                $violations[] = "Document {$id} : déjà au statut « {$vers} » — l'effectif annoncé ne peut pas être tenu.";
            } elseif (! in_array($vers, LegalDocument::CURATION_TRANSITIONS_AVANT[$de] ?? [], true)
                && ! in_array($vers, LegalDocument::CURATION_TRANSITIONS_ARRIERE[$de] ?? [], true)) {
                $violations[] = "Document {$id} : transition « {$de} » → « {$vers} » refusée par la machine à états.";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function executerChangerStatut(array $params): int
    {
        $touches = 0;

        foreach ($params['document_ids'] as $id) {
            $document = LegalDocument::query()->findOrFail($id);
            $document->curation_status = $params['vers'];
            $document->save();
            $touches++;
        }

        return $touches;
    }

    /**
     * Retire de la file de curation des documents de staging structurellement
     * faux — sans les détruire.
     *
     * Ajoutée le 16/08/2026 : la campagne de mesure des intitulés a trouvé
     * 127 documents en brouillon qui ne sont pas des actes (fragments de
     * phrase promus en documents par le découpage de JO, coquilles sans aucun
     * article, blocs de signature isolés). Aucune opération existante ne
     * pouvait les traiter : `changer_statut_documents` les aurait poussés vers
     * `review`, c'est-à-dire dans la file de curation au lieu de l'en sortir —
     * il n'existe pas de statut « rejeté » dans la machine à états.
     *
     * Reste bien en Classe 1, et pour les trois raisons qui la définissent :
     * la suppression est DOUCE (`deleted_at`, réversible par `restore()`, pas
     * un octet perdu), elle ne peut viser qu'un document de staging jamais
     * publié (garde de `documentClasseUne`), et elle est invisible du public
     * puisque seul `published` est servi. Le `forceDelete` de
     * `DocumentDeletionService`, lui, reste hors de toute liste blanche.
     *
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function violationsRetirerStaging(array $params, User $utilisateur): array
    {
        $ids = $params['document_ids'] ?? null;

        if (! is_array($ids) || $ids === [] || ! array_is_list($ids)) {
            return ['`params.document_ids` doit être une liste non vide d\'identifiants de documents.'];
        }

        if (! is_string($params['motif'] ?? null) || trim((string) $params['motif']) === '') {
            return ['`params.motif` est obligatoire : un retrait sans motif écrit est irrelisible six mois plus tard.'];
        }

        $violations = [];

        foreach ($ids as $id) {
            [$document, $violationsDocument] = $this->documentClasseUne($id, $utilisateur);
            $violations = [...$violations, ...$violationsDocument];

            if ($document !== null && $document->trashed()) {
                $violations[] = "Document {$id} : déjà retiré — l'effectif annoncé ne peut pas être tenu.";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function executerRetirerStaging(array $params): int
    {
        $touches = 0;

        foreach ($params['document_ids'] as $id) {
            LegalDocument::query()->findOrFail($id)->delete();
            $touches++;
        }

        return $touches;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function violationsModifierMetadonnees(array $params, User $utilisateur): array
    {
        $modifications = $params['modifications'] ?? null;

        if (! is_array($modifications) || $modifications === [] || ! array_is_list($modifications)) {
            return ['`params.modifications` doit être une liste non vide de {id, champs}.'];
        }

        $violations = [];

        foreach ($modifications as $index => $modification) {
            $rang = $index + 1;

            if (! is_array($modification)) {
                $violations[] = "Modification {$rang} : objet {id, champs} attendu.";

                continue;
            }

            $champs = $modification['champs'] ?? null;

            if (! is_array($champs) || $champs === []) {
                $violations[] = "Modification {$rang} : `champs` doit être un objet non vide.";
            } elseif (($horsListe = array_diff(array_keys($champs), self::CHAMPS_METADONNEES)) !== []) {
                $violations[] = sprintf(
                    'Modification %d : champ(s) hors liste blanche : %s (autorisés : %s).',
                    $rang,
                    implode(', ', $horsListe),
                    implode(', ', self::CHAMPS_METADONNEES),
                );
            }

            [, $violationsDocument] = $this->documentClasseUne($modification['id'] ?? null, $utilisateur);
            $violations = [...$violations, ...$violationsDocument];
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function executerModifierMetadonnees(array $params): int
    {
        $touches = 0;

        foreach ($params['modifications'] as $modification) {
            $document = LegalDocument::query()->findOrFail($modification['id']);
            $document->fill($modification['champs']);

            if (! $document->isDirty()) {
                continue;
            }

            $document->save();
            $touches++;
        }

        return $touches;
    }

    // ── Gardes partagées ─────────────────────────────────────────────────────

    /**
     * Charge un document et vérifie son éligibilité Classe 1 : existant (non
     * supprimé), en staging, jamais publié, éditable par le porteur du jeton.
     *
     * @return array{0: LegalDocument|null, 1: list<string>}
     */
    private function documentClasseUne(mixed $id, User $utilisateur): array
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return [null, ['Identifiant de document invalide : '.json_encode($id).'.']];
        }

        $document = LegalDocument::query()->find($id);

        if ($document === null) {
            return [null, ["Document introuvable (ou supprimé) : {$id}."]];
        }

        if (! in_array($document->curation_status, self::STATUTS_STAGING, true)) {
            return [null, [
                "Document {$id} : statut « {$document->curation_status} » hors Classe 1 — un document publié (ou hors staging) relève de la Classe 2.",
            ]];
        }

        if ($this->aDejaEtePublie($document)) {
            return [null, [
                "Document {$id} : a déjà été publié (trace d'audit) — toute intervention relève de la Classe 2.",
            ]];
        }

        if (Gate::forUser($utilisateur)->denies('update', $document)) {
            return [null, ["Document {$id} : le jeton n'a pas le droit documents.update."]];
        }

        return [$document, []];
    }

    /**
     * Un document qui a déjà été publié ne relève plus de la Classe 1, même
     * revenu en staging par dépublication. La trace vit dans le journal
     * d'audit (publication et dépublication passent toujours par
     * Eloquent/API, donc par owen-it) ; la colonne `new_values`/`old_values`
     * étant `text`, la détection se fait par motif sur la sérialisation JSON
     * compacte de json_encode. Filet borné par la rétention des audits
     * (mibeko:prune-audits, 365 j par défaut) : il s'ajoute au contrôle du
     * statut courant, il ne remplace pas la relecture du lot par l'humain.
     */
    private function aDejaEtePublie(LegalDocument $document): bool
    {
        $motif = '%"curation_status":"published"%';

        return $document->audits()
            ->where(fn ($query) => $query
                ->where('new_values', 'like', $motif)
                ->orWhere('old_values', 'like', $motif))
            ->exists();
    }

    /**
     * Cible d'un signalement à créer : le document visé, directement ou via
     * l'article (les portées globales SoftDeletes excluent les supprimés).
     *
     * @param  array<string, mixed>  $signalement
     */
    private function documentViseParLeSignalement(array $signalement): ?LegalDocument
    {
        $documentId = $signalement['document_id'] ?? null;

        if (is_string($documentId) && Str::isUuid($documentId)) {
            return LegalDocument::query()->find($documentId);
        }

        $articleId = $signalement['article_id'] ?? null;

        if (is_string($articleId) && Str::isUuid($articleId)) {
            return Article::query()->find($articleId)?->document;
        }

        return null;
    }

    /**
     * Trois premiers identifiants, pour situer la cible sans noyer l'écran.
     *
     * @param  list<string>  $ids
     */
    private function apercu(array $ids): string
    {
        $extrait = implode(', ', array_slice($ids, 0, 3));

        return count($ids) > 3 ? $extrait.', …' : $extrait;
    }
}
