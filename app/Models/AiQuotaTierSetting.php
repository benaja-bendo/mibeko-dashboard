<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Réglage de quota IA par palier, éditable depuis l'admin — mibeko-dashboard#95.
 *
 * Une ligne par palier (`standard`/`user_pro`/`admin`) quand un admin l'a
 * modifié ; son absence n'est pas une anomalie, c'est le cas normal tant que
 * personne n'a touché au défaut de `config('ai.quotas')`. Seul point qui doit
 * la lire : `App\Ai\AiUserQuotaTier::resolve()`.
 */
class AiQuotaTierSetting extends Model implements Auditable
{
    use AuditableTrait, HasUuids;

    public const TIERS = ['standard', 'user_pro', 'admin'];

    protected $fillable = ['tier', 'limit'];

    protected function casts(): array
    {
        return [
            'limit' => 'integer',
        ];
    }
}
