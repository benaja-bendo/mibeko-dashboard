<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Provenance structurée d'un fichier acquis par l'usine à textes (dépôt web
 * ou veille) — source de vérité opérationnelle depuis mibeko-python#22
 * (§ 3.7 du plan « boîte de réception »). `data/manifests/*.jsonl` reste
 * produit en export périodique, lisible par les commandes CLI existantes.
 */
class IngestionProvenance extends Model
{
    use HasUuids;

    protected $fillable = [
        'manifest_id',
        'type_source',
        'source_url',
        'sha256',
        'fetched_at',
        'evenements',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'evenements' => 'array',
    ];
}
