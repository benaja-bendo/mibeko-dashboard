<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    use HasFactory;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'nom', 'niveau_hierarchique'];

    public function legalDocuments(): HasMany
    {
        return $this->hasMany(LegalDocument::class, 'type_code', 'code');
    }
}
