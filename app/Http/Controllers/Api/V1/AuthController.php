<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kreait\Laravel\Firebase\Facades\Firebase;

/**
 * @group Authentication
 */
class AuthController extends Controller
{
    use \App\Traits\HttpResponses;
    /**
     * Login.
     *
     * Returns a Sanctum plain-text token.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        return $this->success([
            'token' => $user->createToken($request->device_name)->plainTextToken,
            'user' => $user->load('roles', 'mobileProfile'),
        ], 'Connexion réussie.');
    }

    /**
     * Firebase Login.
     *
     * Authenticate or register a user via Firebase ID Token, returning a Sanctum token.
     */
    public function firebaseLogin(Request $request): JsonResponse
    {
        $request->validate([
            'id_token' => 'required|string',
            'device_name' => 'required|string',
        ]);

        $auth = Firebase::auth();

        try {
            $verifiedIdToken = $auth->verifyIdToken($request->id_token);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'id_token' => ['Le token Firebase est invalide ou expiré.'],
            ]);
        }

        $uid = $verifiedIdToken->claims()->get('sub');
        $userRecord = $auth->getUser($uid);

        $email = $userRecord->email;
        $name = $userRecord->displayName ?? 'Mobile User';

        if (! $email) {
            throw ValidationException::withMessages([
                'id_token' => ['L\'adresse email est requise depuis le fournisseur d\'identité.'],
            ]);
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(Str::random(16)),
            ]
        );

        if (! $user->hasRole('mobile_user')) {
            $user->assignRole('mobile_user');
        }

        if (! $user->mobileProfile) {
            $user->mobileProfile()->create();
        }

        return $this->success([
            'token' => $user->createToken($request->device_name)->plainTextToken,
            'user' => $user->load('roles', 'mobileProfile'),
        ], 'Connexion Firebase réussie.');
    }

    /**
     * Get authenticated user.
     */
    public function me(Request $request): User
    {
        return $request->user();
    }

    /**
     * Logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Déconnecté avec succès.');
    }
}
