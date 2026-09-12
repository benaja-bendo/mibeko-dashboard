<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Services\ProfileAttributeWriter;
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

    public function __construct(private readonly ProfileAttributeWriter $profileWriter) {}

    /**
     * Retourne le compte complet : identité, profil étendu, rôles/permissions
     * (lecture seule) et préférences applicatives.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles', 'mobileProfile', 'settings', 'tags');

        return $this->success(new UserProfileResource($user), 'Profil récupéré avec succès.');
    }

    /**
     * Met à jour les informations personnelles.
     *
     * Le nom vit sur `users` ; téléphone / fonction / cadre d'usage / métier /
     * organisation sur le profil étendu (`mobile_profiles`, upsert atomique sur
     * `user_id` — mibeko-dashboard#135, corrige une race condition qui pouvait
     * dupliquer la ligne sous deux PATCH concurrents) ; intérêts via les tags
     * de l'utilisateur (taxonomie "Thèmes de vie" réutilisée telle quelle).
     *
     * PATCH strictement partiel : une clé absente de la requête ne touche pas
     * la colonne correspondante (`sometimes` côté FormRequest). `profession`
     * et `usage_context` se complètent mutuellement quand un seul des deux est
     * fourni (mapping catégorie ↔ catégorie documenté dans MobileProfile),
     * jamais quand les deux sont déjà présents.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (array_key_exists('name', $validated)) {
            $user->update(['name' => $validated['name']]);
        }

        $profileData = collect($validated)
            ->only(['phone', 'profession', 'company', 'usage_context', 'job_title'])
            ->all();

        $this->profileWriter->applyProfileFields($user, $profileData);

        if (array_key_exists('interests', $validated)) {
            $this->profileWriter->applyInterests($user, $validated['interests']);
        }

        return $this->success(
            new UserProfileResource($user->fresh()->load('roles', 'mobileProfile', 'settings', 'tags')),
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
