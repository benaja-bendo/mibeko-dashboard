<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validation minimale et permissive — mibeko-dashboard#135. Aucune lib de
 * téléphonie internationale (`giggsey/libphonenumber-for-php`,
 * `propaganistas/laravel-phone`) n'est présente dans `composer.json` : plutôt
 * que d'en introduire une pour un besoin encore mesuré grossièrement, on
 * accepte toute forme internationale plausible sans jamais bloquer une valeur
 * déjà stockée (`''`/`null` = effacement explicite, toujours accepté).
 */
class InternationalPhoneNumber implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! preg_match('/^\+?[0-9 .()-]{6,30}$/', (string) $value)) {
            $fail('Le numéro de téléphone doit être dans un format international valide, par exemple +242 06 800 00 00.');
        }
    }
}
