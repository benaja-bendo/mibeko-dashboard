<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Institution extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids;

    protected $fillable = ['nom', 'sigle'];

    public function legalDocuments(): HasMany
    {
        return $this->hasMany(LegalDocument::class);
    }
}
