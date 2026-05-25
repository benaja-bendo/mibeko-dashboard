<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaFile extends Model
{
    use HasFactory, HasUuids;

    /**
     * Les attributs qui sont assignables en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'document_id',
        'file_path',
        'storage_provider',
        'bucket_name',
        'object_key',
        'original_filename',
        'mime_type',
        'file_category',
        'file_size',
        'checksum_sha256',
        'description',
    ];

    /**
     * Récupère le document juridique associé au fichier média.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }
}
