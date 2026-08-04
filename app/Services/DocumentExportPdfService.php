<?php

namespace App\Services;

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Rendu et mise en cache du PDF Mibeko (export « partage ») d'un document
 * complet.
 *
 * Le rendu DomPDF est coûteux (documents à plusieurs milliers d'articles) :
 * un fichier est conservé par document sur le disque `s3` (MinIO), keyé sur
 * `document->updated_at`. Cette date est déjà mise à jour automatiquement à
 * chaque changement de contenu grâce à la cascade `$touches`
 * (ArticleVersion → Article → LegalDocument), donc le cache s'invalide tout
 * seul sans hook supplémentaire dès qu'un article change.
 */
class DocumentExportPdfService
{
    private const DISK = 's3';

    private const DIRECTORY = 'exports/documents';

    /**
     * Chemin du PDF déjà en cache pour la version actuelle du document, ou
     * null si aucun rendu n'existe encore pour cette version.
     */
    public function cachedPath(LegalDocument $document): ?string
    {
        $path = $this->pathFor($document);

        try {
            return Storage::disk(self::DISK)->exists($path) ? $path : null;
        } catch (Throwable $e) {
            // Un MinIO indisponible ne doit pas faire échouer un partage :
            // on se rabat sur le rendu synchrone plutôt que de renvoyer une 500.
            report($e);

            return null;
        }
    }

    /**
     * `legal_documents.updated_at` est en précision seconde (`timestamp(0)`
     * côté Postgres) : deux sauvegardes dans la même seconde partagent donc
     * la même clé de cache. Fenêtre de risque négligeable ici (édition
     * humaine, pas d'écriture à haute fréquence) — la sauvegarde suivante,
     * même une seconde plus tard, invalide correctement le cache.
     */
    public function pathFor(LegalDocument $document): string
    {
        return self::DIRECTORY.'/'.$document->id.'/'.$document->updated_at->timestamp.'.pdf';
    }

    public function filenameFor(LegalDocument $document): string
    {
        return Str::slug($document->titre_officiel ?? 'document').'.pdf';
    }

    /**
     * Sert le PDF en cache, en le générant d'abord si nécessaire (repli
     * synchrone — ne devrait se produire que si le pré-chauffage en tâche de
     * fond n'a pas encore eu lieu, cf. LegalDocumentObserver).
     */
    public function respond(LegalDocument $document): StreamedResponse
    {
        $path = $this->cachedPath($document) ?? $this->generate($document);

        return Storage::disk(self::DISK)->download($path, $this->filenameFor($document), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Rend le document en PDF et remplace la version en cache. Sûr à appeler
     * en tâche de fond (job) comme en repli synchrone (contrôleur).
     */
    public function generate(LegalDocument $document): string
    {
        // Document volumineux : le rendu DomPDF peut prendre plusieurs
        // minutes et consommer beaucoup de mémoire.
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $document->loadMissing([
            'institution',
            'type',
            'structureNodes' => fn ($q) => $q->orderBy('sort_order'),
            'articles' => fn ($q) => $q->orderBy('ordre_affichage'),
            'articles.activeVersion',
            'articles.latestVersion',
        ]);

        [$sections, $orphans] = $this->buildSections($document);

        if (ob_get_length()) {
            ob_end_clean();
        }

        $pdf = Pdf::loadView('documents.pro_document_pdf', compact('document', 'sections', 'orphans'));
        $pdf->setPaper('a4');
        $pdf->setOption('isHtml5ParserEnabled', true);
        // Le contenu des articles est du texte échappé (`e()` dans le
        // template) : aucune balise HTML n'y survit, donc aucune ressource
        // distante n'est jamais référencée. Désactiver isRemoteEnabled
        // supprime les requêtes sortantes du rendu (plus rapide, même
        // durcissement anti-SSRF que l'export dossier).
        $pdf->setOption('isRemoteEnabled', false);

        $disk = Storage::disk(self::DISK);
        $path = $this->pathFor($document);
        $disk->put($path, $pdf->output());

        $this->purgeStaleVersions($document, $path);

        return $path;
    }

    private function purgeStaleVersions(LegalDocument $document, string $keep): void
    {
        $disk = Storage::disk(self::DISK);

        foreach ($disk->files(self::DIRECTORY.'/'.$document->id) as $existing) {
            if ($existing !== $keep) {
                $disk->delete($existing);
            }
        }
    }

    /**
     * Build the ordered, de-duplicated sections rendered in the document PDF.
     *
     * Ingested documents may carry duplicated structure nodes (same unit,
     * number and title) with the articles split between the copies; the
     * `tree_path` column holds opaque ids and is not sortable. Sections are
     * therefore ordered by `sort_order` and merged by their textual identity,
     * each one keeping its articles sorted by `ordre_affichage`.
     *
     * @return array{0: Collection<int, array{node: StructureNode, articles: Collection<int, Article>}>, 1: Collection<int, Article>}
     */
    private function buildSections(LegalDocument $document): array
    {
        $articlesByNode = $document->articles->groupBy('parent_node_id');

        /** @var Collection<int, array{node: StructureNode, articles: Collection<int, Article>}> $sections */
        $sections = collect();
        $indexByKey = [];

        foreach ($document->structureNodes as $node) {
            $key = mb_strtolower(trim(implode('|', [$node->type_unite, $node->numero, $node->titre])));
            $nodeArticles = $articlesByNode->get($node->id, collect());

            if (array_key_exists($key, $indexByKey)) {
                $existing = $sections[$indexByKey[$key]];
                $existing['articles'] = $existing['articles']->concat($nodeArticles);
                $sections[$indexByKey[$key]] = $existing;

                continue;
            }

            $indexByKey[$key] = $sections->count();
            $sections->push(['node' => $node, 'articles' => $nodeArticles]);
        }

        $sections = $sections->map(function (array $section): array {
            $section['articles'] = $section['articles']->sortBy('ordre_affichage')->values();

            return $section;
        });

        $orphans = $document->articles
            ->whereNull('parent_node_id')
            ->sortBy('ordre_affichage')
            ->values();

        return [$sections, $orphans];
    }
}
