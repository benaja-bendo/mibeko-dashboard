<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

class LegalDocument extends Model implements Auditable
{
    use HasFactory, HasUuids, \OwenIt\Auditing\Auditable, SoftDeletes;

    protected $auditExclude = [
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'type_code',
        'institution_id',
        'official_journal_id',
        'document_key',
        'document_role',
        'consolidation_as_of',
        'stock_code',
        'titre_officiel',
        'slug',
        'reference_nor',
        'date_signature',
        'date_publication',
        'date_entree_vigueur',
        'statut',
        'curation_status',
        'extraction_status',
        'metadata',
        'legal_scope',
    ];

    const SCOPE_NATIONAL = 'national';

    const SCOPE_OHADA = 'ohada';

    const SCOPE_COMMUNAUTAIRE = 'communautaire';

    /** @var array<int, string> Périmètres juridiques autorisés. */
    const LEGAL_SCOPES = [self::SCOPE_NATIONAL, self::SCOPE_OHADA, self::SCOPE_COMMUNAUTAIRE];

    const STATUS_DRAFT = 'draft';

    const STATUS_REVIEW = 'review';

    const STATUS_VALIDATED = 'validated';

    const STATUS_PUBLISHED = 'published';

    protected function casts(): array
    {
        return [
            'date_signature' => 'date',
            'date_publication' => 'date',
            'date_entree_vigueur' => 'date',
            'consolidation_as_of' => 'date',
            'metadata' => 'array',
        ];
    }

    /**
     * Cascade le soft-delete vers les articles.
     *
     * Les articles ne sont pas supprimés en base avec leur document : sans ce
     * relai, ils « fuitent » dans les recherches d'articles, le sélecteur de
     * relations et toute requête directe sur Article. On restaure de même.
     */
    protected static function booted(): void
    {
        // Garantit le slug à *chaque* écriture Eloquent (création comme mise à
        // jour), et pas seulement à la création : le pipeline Python insère les
        // documents directement en base (sans slug), puis la publication passe
        // par Eloquent (`update()`) — c'est ce `saving` qui répare alors le slug
        // manquant, sans quoi le texte publié resterait invisible du site
        // vitrine (filtré sur la présence d'un slug). Le backfill planifié
        // (`mibeko:backfill-document-slugs`) couvre les chemins hors-Eloquent
        // (mise à jour de masse SQL, insertions brutes).
        static::saving(function (LegalDocument $document) {
            if (empty($document->slug)) {
                $document->slug = static::generateUniqueSlug(
                    $document->titre_officiel ?: $document->id ?: 'document',
                    $document->id,
                );
            }
        });

        static::deleting(function (LegalDocument $document) {
            if ($document->isForceDeleting()) {
                return;
            }

            $document->articles()->delete();
        });

        static::restoring(function (LegalDocument $document) {
            $document->articles()->onlyTrashed()->restore();
        });
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'type_code', 'code');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Récupère le journal officiel dans lequel ce document a été publié.
     */
    public function officialJournal(): BelongsTo
    {
        return $this->belongsTo(OfficialJournal::class, 'official_journal_id');
    }

    public function structureNodes(): HasMany
    {
        return $this->hasMany(StructureNode::class, 'document_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'document_id');
    }

    public function relations(): HasMany
    {
        return $this->hasMany(DocumentRelation::class, 'source_doc_id');
    }

    /**
     * Récupère les anomalies de curation (trous/doublons de numérotation, etc.)
     * détectées à l'ingestion. Servent de garde-fou : un document conservant des
     * anomalies non résolues ne doit pas être publié au catalogue.
     */
    public function curationFlags(): HasMany
    {
        return $this->hasMany(CurationFlag::class, 'document_id');
    }

    /**
     * Récupère les fichiers médias associés au document.
     */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'document_id');
    }

    /**
     * Récupère tous les tags du document juridique.
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Scope a query to only include published documents that have articles.
     */
    public function scopePublished($query)
    {
        return $query->where('curation_status', self::STATUS_PUBLISHED)
            ->whereHas('articles');
    }

    /**
     * Génère un slug unique et stable à partir d'un titre.
     *
     * Le slug est tronqué à 80 caractères pour rester lisible dans une URL, et
     * suffixé (`-2`, `-3`, …) en cas de collision avec un document existant
     * (corbeille incluse, pour ne pas réutiliser le slug d'un texte restauré).
     */
    public static function generateUniqueSlug(string $source, ?string $ignoreId = null): string
    {
        $base = trim(Str::limit(Str::slug($source), 80, ''), '-');

        if ($base === '') {
            $base = 'document';
        }

        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
