<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * `Auditable` (dashboard#123) : une relation candidate détectée par
 * heuristique n'engage rien tant qu'un humain ne l'a pas validée — la trace
 * de qui a validé/rejeté et quand ne doit pas reposer sur les seules colonnes
 * `reviewed_by`/`reviewed_at` (état courant, écrasé par un aller-retour).
 * Même mécanisme que `CurationFlag`, table `audits` déjà générique.
 */
class DocumentRelation extends Model implements Auditable
{
    use HasFactory, HasUuids, \OwenIt\Auditing\Auditable;

    /** Statut d'une relation détectée par heuristique, en attente de relecture. */
    const STATUS_CANDIDATE = 'candidate';

    /** Statut par défaut : une relation saisie à la main, ou validée au triage. */
    const STATUS_CONFIRMED = 'confirmed';

    /** Candidat écarté par un relecteur — conservé, jamais supprimé, pour ne pas le re-proposer. */
    const STATUS_REJECTED = 'rejected';

    const STATUSES = [self::STATUS_CANDIDATE, self::STATUS_CONFIRMED, self::STATUS_REJECTED];

    /** Origine d'une relation. Jamais `llm` dans ce ticket : pas d'abrogation déduite uniquement par IA. */
    const SOURCE_HEURISTIC = 'heuristic';

    const SOURCE_HUMAN = 'human';

    const SOURCES = [self::SOURCE_HEURISTIC, self::SOURCE_HUMAN];

    const TYPE_CREE = 'CREE';

    const TYPE_MODIFIE = 'MODIFIE';

    const TYPE_ABROGE = 'ABROGE';

    const TYPE_CITE = 'CITE';

    const TYPE_COMPLETE = 'COMPLETE';

    const TYPE_RENUMEROTE = 'RENUMEROTE';

    protected $fillable = [
        'source_doc_id',
        'target_doc_id',
        'source_article_id',
        'target_article_id',
        'relation_type',
        'status',
        'source',
        'commentaire',
        'effective_date',
        'confidence',
        'meta',
        'created_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'confidence' => 'float',
            'meta' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'source_doc_id');
    }

    public function targetDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'target_doc_id');
    }

    public function sourceArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'source_article_id');
    }

    public function targetArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'target_article_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
