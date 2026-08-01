<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglage clé-valeur modifiable à l'exécution, sans redéploiement.
 *
 * Complète config/*.php (statique, figé au déploiement) pour les valeurs
 * qu'une automatisation externe doit pouvoir écrire — ex. `mobile.latest_version`,
 * mis à jour par la CI de mibeko-app-kmp après une publication Play Store.
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key): ?string
    {
        return static::query()->find($key)?->value;
    }

    public static function set(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
