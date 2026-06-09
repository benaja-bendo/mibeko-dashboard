<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * @group User Profile
 *
 * Gestion des informations personnelles et du mot de passe du compte.
 */
class ProfileController extends Controller
{
    use HttpResponses;

    /**
     * Retourne le compte complet : identité, profil étendu, rôles/permissions
     * (lecture seule) et préférences applicatives.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles', 'mobileProfile', 'settings');

        return $this->success(new UserProfileResource($user), 'Profil récupéré avec succès.');
    }

    /**
     * Met à jour les informations personnelles.
     *
     * Le nom vit sur `users` ; téléphone / fonction / organisation sur le profil
     * étendu (`mobile_profiles`), créé à la volée si absent.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (array_key_exists('name', $validated)) {
            $user->update(['name' => $validated['name']]);
        }

        $profileData = collect($validated)->only(['phone', 'profession', 'company'])->all();

        if ($profileData !== []) {
            $user->mobileProfile
                ? $user->mobileProfile->update($profileData)
                : $user->mobileProfile()->create($profileData);
        }

        return $this->success(
            new UserProfileResource($user->fresh()->load('roles', 'mobileProfile', 'settings')),
            'Profil mis à jour avec succès.'
        );
    }

    /**
     * Change le mot de passe après vérification du mot de passe courant, puis
     * révoque toutes les autres sessions (le jeton courant reste valide).
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        $user->update(['password' => Hash::make($validated['password'])]);

        // Hygiène de sécurité : invalider les autres sessions après reset.
        $currentTokenId = $user->currentAccessToken()?->getKey();
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return $this->success(null, 'Mot de passe mis à jour avec succès.');
    }
}
