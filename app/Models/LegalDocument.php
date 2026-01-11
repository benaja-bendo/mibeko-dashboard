<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class LegalDocument extends Model implements Auditable
{
    use HasFactory, HasUuids, SoftDeletes, \OwenIt\Auditing\Auditable;

    protected $auditExclude = [
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'type_code',
        'institution_id',
        'titre_officiel',
        'reference_nor',
        'date_signature',
        'date_publication',
        'date_entree_vigueur',
        'statut',
        'curation_status',
    ];

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
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'type_code', 'code');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
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
     * Récupère les fichiers médias associés au document.
     *
     * @return HasMany
     */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'document_id');
    }

    /**
     * Récupère tous les tags du document juridique.
     *
     * @return MorphToMany
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
