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
            'phone' => 'nullable|string|max:20',
            'profession' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        if ($user->mobileProfile) {
            $user->mobileProfile->update($validated);
        } else {
            $user->mobileProfile()->create($validated);
        }

        return $this->success(
            $user->load('roles', 'mobileProfile'),
            'Profil mis à jour avec succès.'
        );
    }
}
