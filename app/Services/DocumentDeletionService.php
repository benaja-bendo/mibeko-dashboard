<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use App\Models\MediaFile;
use App\Models\StructureNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Centralise la suppression définitive d'un document et de TOUTES ses
 * dépendances, de façon transactionnelle et cohérente.
 *
 * Depuis la migration d'alignement des ON DELETE, l'essentiel part en CASCADE
 * (structure_nodes, articles, article_versions, media_files, extraction_runs,
 * curation_flags, document_relations). Ce service couvre ce que les clés
 * étrangères ne peuvent pas :
 *  - les pivots polymorphes `taggables` (thèmes) qui n'ont aucune FK ;
 *  - la purge best-effort des fichiers physiques (objets MinIO/S3) ;
 *  - le calcul de l'impact (compteurs) et des dépendances entrantes (garde-fou).
 */
class DocumentDeletionService
{
    /**
     * Récapitule ce qu'une suppression définitive emporterait, et signale les
     * dépendances externes (citations entrantes, articles enregistrés dans des
     * dossiers utilisateurs) pour une confirmation éclairée.
     *
     * @return array{nodes:int, articles:int, versions:int, flags:int, media:int, relations:int, incoming_relations:int, dossier_references:int}
     */
    public function impact(LegalDocument $document): array
    {
        $articleIds = $this->articleIds($document);

        return [
            'nodes' => StructureNode::where('document_id', $document->id)->count(),
            'articles' => $articleIds->count(),
            'versions' => $articleIds->isEmpty()
                ? 0
                : ArticleVersion::whereIn('article_id', $articleIds)->count(),
            'flags' => CurationFlag::where('document_id', $document->id)
                ->when($articleIds->isNotEmpty(), fn ($q) => $q->orWhereIn('article_id', $articleIds))
                ->count(),
            'media' => MediaFile::where('document_id', $document->id)->count(),
            'relations' => $this->relationsTouchingDocument($document, $articleIds)->count(),
            'incoming_relations' => $this->incomingRelations($document, $articleIds)->count(),
            'dossier_references' => $articleIds->isEmpty()
                ? 0
                : DB::table('dossier_articles')->whereIn('article_id', $articleIds)->count(),
        ];
    }

    /**
     * Supprime définitivement le document et toutes ses dépendances.
     *
     * Les pivots `taggables` du document ET de ses articles sont purgés AVANT le
     * forceDelete (le CASCADE ne les atteint pas : relation polymorphe sans FK).
     * Les fichiers physiques sont retirés hors transaction, en best-effort : une
     * panne de stockage ne doit pas annuler la suppression en base.
     */
    public function forceDelete(LegalDocument $document): void
    {
        $articleIds = $this->articleIds($document)->all();
        $mediaFiles = MediaFile::where('document_id', $document->id)
            ->get(['storage_provider', 'object_key', 'file_path']);

        DB::transaction(function () use ($document, $articleIds): void {
            // Thèmes du document (pivot polymorphe).
            $document->tags()->detach();

            // Thèmes des articles : aucune FK vers articles → purge explicite,
            // sinon lignes orphelines après le CASCADE sur les articles.
            if (! empty($articleIds)) {
                DB::table('taggables')
                    ->where('taggable_type', (new Article)->getMorphClass())
                    ->whereIn('taggable_id', $articleIds)
                    ->delete();
            }

            // Le reste part en CASCADE (cf. migration fix_document_deletion_cascades).
            $document->forceDelete();
        });

        $this->purgePhysicalMedia($mediaFiles);
    }

    /**
     * Ids de TOUS les articles du document, corbeille incluse (le CASCADE en base
     * ignore le soft-delete : il faut les compter/nettoyer tous).
     *
     * @return Collection<int, string>
     */
    private function articleIds(LegalDocument $document): Collection
    {
        return Article::withTrashed()->where('document_id', $document->id)->pluck('id');
    }

    /**
     * Relations dont le document — ou l'un de ses articles — est une extrémité.
     *
     * @param  Collection<int, string>  $articleIds
     */
    private function relationsTouchingDocument(LegalDocument $document, Collection $articleIds): Builder
    {
        return DocumentRelation::query()->where(function ($q) use ($document, $articleIds): void {
            $q->where('source_doc_id', $document->id)
                ->orWhere('target_doc_id', $document->id);

            if ($articleIds->isNotEmpty()) {
                $q->orWhereIn('source_article_id', $articleIds)
                    ->orWhereIn('target_article_id', $articleIds);
            }
        });
    }

    /**
     * Relations ENTRANTES : un AUTRE document (ou article) cite celui-ci. Garde-fou
     * — supprimer ce texte cassera ces citations. On exclut les liens internes.
     *
     * @param  Collection<int, string>  $articleIds
     */
    private function incomingRelations(LegalDocument $document, Collection $articleIds): Builder
    {
        return DocumentRelation::query()
            ->where(function ($q) use ($document, $articleIds): void {
                $q->where('target_doc_id', $document->id);

                if ($articleIds->isNotEmpty()) {
                    $q->orWhereIn('target_article_id', $articleIds);
                }
            })
            // La source n'est pas ce document (IS DISTINCT FROM gère les NULL).
            ->whereRaw('source_doc_id IS DISTINCT FROM ?', [$document->id]);
    }

    /**
     * Retire les objets physiques associés (MinIO/S3). Best-effort : on journalise
     * et on continue sur erreur, les lignes media_files étant déjà parties en base.
     *
     * @param  Collection<int, MediaFile>  $mediaFiles
     */
    private function purgePhysicalMedia(Collection $mediaFiles): void
    {
        foreach ($mediaFiles as $media) {
            $key = $media->object_key ?: $media->file_path;
            if (empty($key)) {
                continue;
            }

            try {
                Storage::disk($media->storage_provider ?: config('filesystems.default'))->delete($key);
            } catch (\Throwable $e) {
                Log::warning('Purge média physique échouée lors de la suppression du document', [
                    'object_key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
