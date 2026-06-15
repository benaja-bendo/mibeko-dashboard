<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Tag extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'description',
        'display_order',
    ];

    /**
     * Récupère tous les articles qui ont ce tag.
     */
    public function articles()
    {
        return $this->morphedByMany(Article::class, 'taggable');
    }

    /**
     * Récupère tous les documents juridiques qui ont ce tag.
     */
    public function legalDocuments()
    {
        return $this->morphedByMany(LegalDocument::class, 'taggable');
    }

    /**
     * Récupère tous les utilisateurs qui ont ce tag.
     */
    public function users()
    {
        return $this->morphedByMany(User::class, 'taggable');
    }
}
