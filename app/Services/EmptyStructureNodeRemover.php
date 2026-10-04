<?php

namespace App\Services;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Retire d'un texte les divisions qui ne sont qu'une ligne de sommaire, par
 * suppression logique (dashboard#221).
 *
 * Origine du besoin : quand le sommaire d'un texte n'est pas introduit par une
 * rubrique « SOMMAIRE », le parseur en prend chaque ligne (« LIVRE I : … . . . 4 »)
 * pour une division. Deux cas en résultent.
 *
 * 1. Le cas courant : des sous-arbres entiers sans aucun article. La sélection est
 *    « aucun article vivant dans tout le sous-arbre ». Elle ne repose pas sur les
 *    points de conduite (le titre d'une ligne coupée sur deux lignes n'en a pas) :
 *    on ne retire jamais une division à cause de la typographie de son titre. Un
 *    nœud vide n'a que des descendants vides, donc le retrait enlève des sous-arbres
 *    entiers, sans orpheliner un seul article.
 *
 * 2. Le cas piégé : la dernière ligne du sommaire, que le parseur laisse « ouverte »,
 *    reçoit comme fils la vraie première division du texte (JO 1-2011 : le chapitre
 *    préliminaire sous « LIVRE IX . . . . »). Elle porte donc un article dans son
 *    sous-arbre, et le critère ne la voit pas. Elle ne se détecte pas, elle se
 *    désigne : `dissolve` la retire en remontant ses descendants d'un niveau.
 *
 * Rien ne s'efface physiquement (D-045). Les nœuds ne sont pas audités ; la trace
 * est l'instantané de retour arrière, que {@see restore()} sait rejouer. Le document
 * est touché, comme le fait `StructureNode::$touches` : son `updated_at` entre dans
 * `version_hash`, donc le mobile resynchronise ce texte, et le PDF exporté, mis en
 * cache sur la structure, est invalidé.
 */
class EmptyStructureNodeRemover
{
    public const NOTHING_TO_REMOVE = 'Aucun nœud vide : rien à retirer.';

    /**
     * Lit, sans rien modifier : l'état courant et ce que ferait l'opération.
     *
     * @param  list<string>  $dissolveIds  nœuds à dissoudre (cas 2), désignés un à un
     * @return array{
     *     document: object|null,
     *     candidates: Collection<int, object>,
     *     dissolved: Collection<int, object>,
     *     moved: list<array{id: string, old_tree_path: string, new_tree_path: string}>,
     *     fingerprint: string,
     *     counts: array{live_nodes: int, to_remove: int, empty: int, dissolved: int, moved: int, remaining: int, live_articles: int, dangling: int},
     *     blockers: list<string>
     * }
     */
    public function plan(ConnectionInterface $db, string $documentId, array $dissolveIds = []): array
    {
        $document = $db->table('legal_documents')
            ->where('id', $documentId)
            ->whereNull('deleted_at')
            ->first(['id', 'titre_officiel', 'curation_status', 'document_role', 'updated_at']);

        $candidates = $document === null ? collect() : $this->candidates($db, $documentId);
        [$dissolved, $moved, $dissolveBlockers] = $document === null
            ? [collect(), [], []]
            : $this->dissolutions($db, $documentId, $candidates, array_values(array_unique($dissolveIds)));

        $liveNodes = $document === null ? 0 : $db->table('structure_nodes')
            ->where('document_id', $documentId)->whereNull('deleted_at')->count();
        $liveArticles = $document === null ? 0 : $db->table('articles')
            ->where('document_id', $documentId)->whereNull('deleted_at')->count();
        $toRemove = $candidates->count() + $dissolved->count();

        $counts = [
            'live_nodes' => $liveNodes,
            'to_remove' => $toRemove,
            'empty' => $candidates->count(),
            'dissolved' => $dissolved->count(),
            'moved' => count($moved),
            'remaining' => $liveNodes - $toRemove,
            'live_articles' => $liveArticles,
            'dangling' => $document === null ? 0 : $this->danglingPaths($db, $documentId),
        ];

        return [
            'document' => $document,
            'candidates' => $candidates,
            'dissolved' => $dissolved,
            'moved' => $moved,
            'fingerprint' => $this->fingerprint($candidates, $dissolved, $moved),
            'counts' => $counts,
            'blockers' => array_merge($this->blockers($db, $document, $candidates, $counts), $dissolveBlockers),
        ];
    }

    /**
     * Retire les nœuds, en tout ou rien. L'état est relu dans la transaction : si
     * le nombre ou l'empreinte ne sont plus ceux qu'a annoncés la simulation, rien
     * n'est écrit.
     *
     * @param  list<string>  $dissolveIds
     * @return array{removed: int, moved: int, live_articles: int, live_nodes_after: int}
     */
    public function remove(ConnectionInterface $db, string $documentId, int $expectedCount, string $expectedFingerprint, array $dissolveIds = []): array
    {
        return $db->transaction(function () use ($db, $documentId, $expectedCount, $expectedFingerprint, $dissolveIds): array {
            $db->table('legal_documents')->where('id', $documentId)->whereNull('deleted_at')->lockForUpdate()->first(['id']);

            $plan = $this->plan($db, $documentId, $dissolveIds);

            if ($plan['blockers'] !== []) {
                throw new DomainException(implode(' ', $plan['blockers']));
            }
            if ($plan['counts']['to_remove'] !== $expectedCount) {
                throw new DomainException("Écart : {$expectedCount} nœud(s) annoncé(s), {$plan['counts']['to_remove']} aujourd'hui. Rien n'est écrit.");
            }
            if (! hash_equals($plan['fingerprint'], $expectedFingerprint)) {
                throw new DomainException("L'empreinte a changé depuis la simulation : ce ne sont plus les mêmes nœuds. Rien n'est écrit.");
            }

            $now = now();
            $ids = $plan['candidates']->pluck('id')->merge($plan['dissolved']->pluck('id'))->all();

            // Les descendants remontent d'abord, puis le nœud est retiré : à aucun moment un
            // nœud vivant n'a un parent supprimé.
            foreach ($plan['moved'] as $move) {
                $changed = $db->update(
                    'update structure_nodes set tree_path = ?::ltree, updated_at = ? where id = ? and document_id = ? and deleted_at is null',
                    [$this->ltree($move['new_tree_path']), $now, $move['id'], $documentId],
                );
                if ($changed !== 1) {
                    throw new DomainException("Le nœud {$move['id']} n'a pas pu être remonté. Retour arrière.");
                }
            }

            $touched = $db->table('structure_nodes')
                ->whereIn('id', $ids)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now, 'updated_at' => $now]);
            if ($touched !== count($ids)) {
                throw new DomainException("{$touched} ligne(s) touchée(s) pour {$expectedCount} annoncée(s). Retour arrière.");
            }

            $orphans = $db->table('articles as a')
                ->join('structure_nodes as n', 'n.id', '=', 'a.parent_node_id')
                ->where('a.document_id', $documentId)
                ->whereNull('a.deleted_at')
                ->whereNotNull('n.deleted_at')
                ->count();
            $articlesAfter = $db->table('articles')->where('document_id', $documentId)->whereNull('deleted_at')->count();
            if ($orphans > 0 || $articlesAfter !== $plan['counts']['live_articles']) {
                throw new DomainException('Un article a perdu son parent ou a disparu. Retour arrière.');
            }
            if ($this->danglingPaths($db, $documentId) > $plan['counts']['dangling']) {
                throw new DomainException("Un nœud vivant a perdu son parent dans l'arbre. Retour arrière.");
            }

            $db->table('legal_documents')->where('id', $documentId)->update(['updated_at' => $now]);

            Log::warning('Retrait de nœuds de structure (sommaire).', [
                'document_id' => $documentId,
                'removed' => count($ids),
                'moved' => count($plan['moved']),
                'fingerprint' => $plan['fingerprint'],
            ]);

            return [
                'removed' => $touched,
                'moved' => count($plan['moved']),
                'live_articles' => $articlesAfter,
                'live_nodes_after' => $plan['counts']['remaining'],
            ];
        });
    }

    /**
     * Instantané qui permet de défaire {@see remove()} : l'état complet des nœuds retirés
     * et l'ancien chemin des nœuds remontés.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function snapshot(array $plan, string $documentId): array
    {
        return [
            'document_id' => $documentId,
            'fingerprint' => $plan['fingerprint'],
            'counts' => $plan['counts'],
            'taken_at' => now()->toIso8601String(),
            'nodes' => $plan['candidates']->merge($plan['dissolved'])
                ->map(fn (object $node): array => (array) $node)->values()->all(),
            'moved' => $plan['moved'],
        ];
    }

    /**
     * Rétablit les nœuds d'un instantané. Tout ou rien : un nœud qui n'est plus
     * supprimé logiquement, qui appartient à un autre document, ou un nœud remonté
     * dont le chemin a changé depuis, arrête tout.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restore(ConnectionInterface $db, array $snapshot): int
    {
        $documentId = (string) ($snapshot['document_id'] ?? '');
        $ids = collect($snapshot['nodes'] ?? [])->pluck('id')->filter()->values()->all();
        $moved = $snapshot['moved'] ?? [];

        if ($documentId === '' || $ids === []) {
            throw new DomainException("Instantané illisible : il n'a ni document ni nœuds.");
        }

        return $db->transaction(function () use ($db, $documentId, $ids, $moved): int {
            $deleted = $db->table('structure_nodes')
                ->whereIn('id', $ids)
                ->where('document_id', $documentId)
                ->whereNotNull('deleted_at')
                ->count();
            if ($deleted !== count($ids)) {
                throw new DomainException("Seuls {$deleted} nœud(s) sur ".count($ids).' sont supprimés logiquement dans ce document. Rien n\'est écrit.');
            }

            $now = now();

            foreach ($moved as $move) {
                $current = $db->selectOne(
                    'select tree_path::text as chemin from structure_nodes where id = ? and document_id = ? and deleted_at is null',
                    [$move['id'], $documentId],
                )?->chemin;
                if ($current !== $move['new_tree_path']) {
                    throw new DomainException("Le nœud {$move['id']} n'a plus le chemin posé par l'opération : retour arrière refusé, à instruire à la main.");
                }
                $db->update(
                    'update structure_nodes set tree_path = ?::ltree, updated_at = ? where id = ?',
                    [$this->ltree($move['old_tree_path']), $now, $move['id']],
                );
            }

            $restored = $db->table('structure_nodes')
                ->whereIn('id', $ids)
                ->where('document_id', $documentId)
                ->update(['deleted_at' => null, 'updated_at' => $now]);

            $db->table('legal_documents')->where('id', $documentId)->update(['updated_at' => $now]);

            return $restored;
        });
    }

    /**
     * Nœuds vivants sans aucun article vivant dans leur sous-arbre (lui-même compris).
     *
     * @return Collection<int, object>
     */
    private function candidates(ConnectionInterface $db, string $documentId): Collection
    {
        return collect($db->select(<<<'SQL'
            select n.id, n.document_id, n.type_unite, n.numero, n.titre, n.tree_path::text as tree_path,
                   n.validation_status, n.sort_order, n.created_at, n.updated_at
            from structure_nodes n
            where n.document_id = ? and n.deleted_at is null
              and not exists (
                  select 1
                  from structure_nodes c
                  join articles a on a.parent_node_id = c.id and a.deleted_at is null
                  where c.document_id = n.document_id and c.deleted_at is null and c.tree_path <@ n.tree_path
              )
            order by n.sort_order, n.tree_path
            SQL, [$documentId]));
    }

    /**
     * Les nœuds à dissoudre et le chemin de chaque descendant qui remonte d'un niveau.
     *
     * @param  Collection<int, object>  $candidates
     * @param  list<string>  $dissolveIds
     * @return array{0: Collection<int, object>, 1: list<array{id: string, old_tree_path: string, new_tree_path: string}>, 2: list<string>}
     */
    private function dissolutions(ConnectionInterface $db, string $documentId, Collection $candidates, array $dissolveIds): array
    {
        $dissolved = collect();
        $moved = [];
        $blockers = [];
        $removedByEmptiness = $candidates->pluck('id')->flip();

        foreach ($dissolveIds as $id) {
            $node = $db->selectOne(<<<'SQL'
                select n.id, n.document_id, n.type_unite, n.numero, n.titre, n.tree_path::text as tree_path,
                       n.validation_status, n.sort_order, n.created_at, n.updated_at
                from structure_nodes n
                where n.id = ? and n.document_id = ? and n.deleted_at is null
                SQL, [$id, $documentId]);

            if ($node === null) {
                $blockers[] = "Nœud à dissoudre introuvable dans ce document : {$id}.";

                continue;
            }
            if ($removedByEmptiness->has($id)) {
                $blockers[] = "Le nœud {$id} est déjà retiré comme nœud vide : inutile de le dissoudre.";

                continue;
            }
            if ($db->table('articles')->where('parent_node_id', $id)->whereNull('deleted_at')->exists()) {
                $blockers[] = "Le nœud {$id} porte un article : on ne dissout qu'une division sans article direct.";

                continue;
            }
            if ($db->table('curation_flags')->where('node_id', $id)->where('resolved', false)->exists()) {
                $blockers[] = "Un signalement non résolu est attaché au nœud {$id} : à traiter d'abord.";

                continue;
            }

            $dissolved->push($node);

            $parts = explode('.', $node->tree_path);
            $depth = count($parts);
            $parentParts = array_slice($parts, 0, $depth - 1);

            $descendants = collect($db->select(<<<'SQL'
                select n.id, n.tree_path::text as tree_path
                from structure_nodes n
                where n.document_id = ? and n.deleted_at is null and n.id <> ? and n.tree_path <@ ?::ltree
                order by n.tree_path
                SQL, [$documentId, $id, $node->tree_path]));

            foreach ($descendants as $descendant) {
                if (in_array($descendant->id, $dissolveIds, true)) {
                    $blockers[] = "Les nœuds {$id} et {$descendant->id} sont imbriqués : on dissout un niveau à la fois.";

                    continue;
                }
                if ($removedByEmptiness->has($descendant->id)) {
                    continue; // retiré comme vide : il ne remonte pas
                }
                $rest = array_slice(explode('.', $descendant->tree_path), $depth);
                $moved[] = [
                    'id' => $descendant->id,
                    'old_tree_path' => $descendant->tree_path,
                    'new_tree_path' => implode('.', array_merge($parentParts, $rest)),
                ];
            }
        }

        return [$dissolved, $moved, $blockers];
    }

    /**
     * @param  Collection<int, object>  $candidates
     * @param  Collection<int, object>  $dissolved
     * @param  list<array{id: string, old_tree_path: string, new_tree_path: string}>  $moved
     */
    private function fingerprint(Collection $candidates, Collection $dissolved, array $moved): string
    {
        $removed = $candidates->pluck('id')->merge($dissolved->pluck('id'))->sort()->implode("\n");
        $moves = collect($moved)->map(fn (array $move): string => $move['id'].'>'.$move['new_tree_path'])->sort()->implode("\n");

        return hash('sha256', $removed."\n--moves--\n".$moves);
    }

    /**
     * Nœuds vivants dont le parent (chemin amputé de son dernier label) n'existe pas parmi
     * les nœuds vivants du document.
     */
    private function danglingPaths(ConnectionInterface $db, string $documentId): int
    {
        return (int) $db->selectOne(<<<'SQL'
            select count(*) as n
            from structure_nodes n
            where n.document_id = ? and n.deleted_at is null and nlevel(n.tree_path) > 1
              and not exists (
                  select 1 from structure_nodes p
                  where p.document_id = n.document_id and p.deleted_at is null and p.tree_path = subpath(n.tree_path, 0, -1)
              )
            SQL, [$documentId])->n;
    }

    /**
     * Un chemin ltree ne contient que des labels `[A-Za-z0-9_]` séparés par des points :
     * on refuse tout le reste avant de l'écrire en littéral.
     */
    private function ltree(string $path): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $path)) {
            throw new DomainException("Chemin ltree invalide : {$path}");
        }

        return $path;
    }

    /**
     * Ce qui interdit l'opération, dit en clair.
     *
     * @param  Collection<int, object>  $candidates
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    private function blockers(ConnectionInterface $db, ?object $document, Collection $candidates, array $counts): array
    {
        if ($document === null) {
            return ['Document introuvable (ou supprimé).'];
        }

        $blockers = [];

        if ($counts['to_remove'] === 0) {
            $blockers[] = self::NOTHING_TO_REMOVE;
        }

        if ($candidates->isNotEmpty() && $counts['remaining'] === 0) {
            $blockers[] = "Tous les nœuds du document sont vides : ce n'est plus un nettoyage mais une reconstruction (D-045).";
        }

        if ($candidates->isNotEmpty()) {
            $openFlags = $db->table('curation_flags')
                ->whereIn('node_id', $candidates->pluck('id')->all())
                ->where('resolved', false)
                ->count();
            if ($openFlags > 0) {
                $blockers[] = "{$openFlags} signalement(s) non résolu(s) sont attachés à ces nœuds : à traiter d'abord.";
            }
        }

        return $blockers;
    }
}
