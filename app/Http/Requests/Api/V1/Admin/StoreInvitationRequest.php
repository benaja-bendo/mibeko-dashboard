<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invitation d'un membre d'équipe : adresse + rôles à attribuer à l'acceptation.
 */
class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'string', 'email', 'max:255',
                'unique:users,email',
                // Refuse une seconde invitation tant qu'une est encore en attente.
                Rule::unique('user_invitations', 'email')->where(
                    fn ($query) => $query->whereNull('accepted_at')->where('expires_at', '>', now()),
                ),
            ],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Cette adresse a déjà un compte ou une invitation en attente.',
            'roles.required' => 'Au moins un rôle doit être attribué.',
        ];
    }
}
