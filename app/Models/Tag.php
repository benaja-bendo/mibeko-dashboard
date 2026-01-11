<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphByMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Tag extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
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
