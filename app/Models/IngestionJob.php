<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Travail de la file durable de l'usine à textes (mibeko-python#22, § 3.3 du
 * plan « boîte de réception »). Modèle minimal : la logique de réservation
 * (`SELECT … FOR UPDATE SKIP LOCKED`, bail, `fencing_token`) et d'exécution
 * vit côté worker Python (mibeko-python#23), pas ici.
 */
class IngestionJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'kind',
        'manifest_id',
        'step',
        'status',
        'attempts',
        'max_attempts',
        'locked_at',
        'locked_by',
        'fencing_token',
        'last_error',
        'error_class',
        'result',
        'requested_by',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'locked_at' => 'datetime',
        'fencing_token' => 'integer',
        'result' => 'array',
    ];

    // Mêmes valeurs que les défauts DB de la migration : un `create()` côté
    // Eloquent doit refléter ces défauts sans round-trip supplémentaire
    // (`fresh()`), un défaut DB seul ne remonte pas sur l'instance en mémoire.
    protected $attributes = [
        'step' => self::STEP_RECU,
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
        'max_attempts' => 3,
        'fencing_token' => 0,
        'result' => '{}',
    ];

    const KIND_DEPOT = 'depot';

    const KIND_VEILLE = 'veille';

    const KIND_REPRISE = 'reprise';

    const STEP_RECU = 'recu';

    const STEP_PARSE = 'parse';

    const STEP_STRUCTURE = 'structure';

    const STEP_CONTROLE = 'controle';

    const STEP_TERMINE = 'termine';

    const STATUS_PENDING = 'pending';

    const STATUS_RUNNING = 'running';

    const STATUS_FAILED = 'failed';

    const STATUS_DONE = 'done';

    const ERROR_TRANSITOIRE = 'transitoire';

    const ERROR_DEFINITIVE = 'definitive';

    const ERROR_INFORMATION_MANQUANTE = 'information_manquante';
}
