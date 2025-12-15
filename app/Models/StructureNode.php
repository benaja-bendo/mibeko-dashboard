<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StructureNode extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
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
}
