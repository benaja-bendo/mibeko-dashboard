<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkCurationFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * Action groupée sur une sélection de signalements depuis l'écran de triage :
     * résoudre, ré-ouvrir ou supprimer plusieurs signalements en une requête.
     *
     * Les identifiants inconnus (déjà supprimés entre-temps) sont ignorés
     * silencieusement : l'action porte sur ceux qui existent encore.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:resolve,reopen,delete'],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['uuid'],
        ];
    }
}
