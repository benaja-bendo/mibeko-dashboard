<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class StructureNode extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Un renommage/déplacement de section change le PDF Mibeko exporté du
     * document (voir DocumentExportPdfService) : sans ce touch, ce cache ne
     * s'invaliderait qu'aux changements d'article, pas de structure.
     */
    protected $touches = ['document'];

    protected $fillable = [
        'id',
        'document_id',
        'type_unite',
        'numero',
        'titre',
        'tree_path',
        'validation_status',
        'sort_order',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'parent_node_id');
    }

    /**
     * Anomalies de curation ciblant cette division (titre vide, division vide…).
     */
    public function curationFlags(): HasMany
    {
        return $this->hasMany(CurationFlag::class, 'node_id');
    }

    /**
     * Id du nœud que désigne un label du `tree_path`. Deux écritures coexistent
     * en base : `n_<uuid>` (ingestion Python, `src/services/ingestion.py`) et
     * `<uuid>` (éditeur, {@see StructureNodeController}) ; dans les deux cas
     * les tirets de l'uuid sont devenus des `_`, un label ltree n'en admettant
     * pas. Oublier le préfixe `n_` servait des `parent_id` introuvables : les
     * clients rangeaient alors toute la structure à la racine (dashboard#217).
     */
    public static function idFromLabel(string $label): string
    {
        return str_replace('_', '-', (string) preg_replace('/^n_/', '', $label));
    }

    /**
     * Id du parent d'après le `tree_path`, null pour un nœud racine.
     */
    public static function parentIdFromPath(string $treePath): ?string
    {
        $labels = explode('.', $treePath);

        return count($labels) > 1 ? self::idFromLabel($labels[count($labels) - 2]) : null;
    }

    /**
     * Nœuds dans l'ordre de lecture : parcours en profondeur, frères triés par
     * `sort_order`. Ce rang n'est comparable qu'entre frères : selon l'ingestion,
     * il numérote tout le document ou repart à 0 sous chaque parent (Acte
     * uniforme droit commercial général), et un tri global mélangeait alors
     * les Livres. Un nœud dont le parent manque à la collection passe racine.
     *
     * @param  Collection<int, StructureNode>  $nodes
     * @return Collection<int, StructureNode>
     */
    public static function inReadingOrder(Collection $nodes): Collection
    {
        $ids = $nodes->pluck('id')->flip();
        $childrenByParent = $nodes->groupBy(function (StructureNode $node) use ($ids): string {
            $parentId = self::parentIdFromPath((string) $node->tree_path);

            return $parentId !== null && $ids->has($parentId) ? $parentId : '';
        });

        $ordered = collect();
        $walk = function (string $parentKey) use (&$walk, $childrenByParent, $ordered): void {
            $siblings = ($childrenByParent[$parentKey] ?? collect())
                ->sortBy(fn (StructureNode $node): array => [$node->sort_order ?? 0, (string) $node->tree_path]);

            foreach ($siblings as $node) {
                $ordered->push($node);
                $walk($node->id);
            }
        };
        $walk('');

        return $ordered;
    }
}
