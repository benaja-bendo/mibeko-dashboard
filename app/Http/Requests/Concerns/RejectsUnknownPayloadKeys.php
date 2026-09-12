<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * Rejette toute clé de payload hors d'une liste blanche — mibeko-dashboard#137.
 *
 * Premier patron de ce genre dans ce dépôt : Laravel ne rejette jamais
 * nativement une clé non déclarée dans `rules()`, il l'ignore silencieusement.
 * Le seul précédent trouvé (`array_diff(array_keys(...), ...)` dans
 * `App\Services\Operations\OperationsClasseUne`) vit dans un service de
 * traitement de lot, pas une couche de validation HTTP — ce trait généralise
 * la même idée pour n'importe quel FormRequest qui en a besoin.
 */
trait RejectsUnknownPayloadKeys
{
    /**
     * @return list<string>
     */
    abstract protected function allowedKeys(): array;

    protected function rejectUnknownPayloadKeys(Validator $validator): void
    {
        $unknown = array_diff(array_keys($this->all()), $this->allowedKeys());

        if ($unknown !== []) {
            $validator->errors()->add(
                '_unknown',
                'Propriété(s) non autorisée(s) : '.implode(', ', $unknown).'.'
            );
        }
    }
}
