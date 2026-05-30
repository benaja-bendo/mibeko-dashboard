<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Traits\HttpResponses;

/**
 * @group User Profile
 */
class ProfileController extends Controller
{
    use HttpResponses;

    /**
     * Get user profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles', 'mobileProfile');
        return $this->success($user, 'Profil récupéré avec succès.');
    }

    /**
     * Update user profile.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'profession' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        if (array_key_exists('name', $validated) && filled($validated['name'])) {
            $user->update(['name' => $validated['name']]);
        }

        $mobileProfileData = collect($validated)->except('name')->all();

        if ($user->mobileProfile) {
            $user->mobileProfile->update($mobileProfileData);
        } else {
            $user->mobileProfile()->create($mobileProfileData);
        }

        return $this->success(
            $user->load('roles', 'mobileProfile'),
            'Profil mis à jour avec succès.'
        );
    }

    /**
     * Update user password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $request->user()->update([
            'password' => \Illuminate\Support\Facades\Hash::make($validated['password']),
        ]);

        return $this->success(null, 'Mot de passe mis à jour avec succès.');
    }
}
