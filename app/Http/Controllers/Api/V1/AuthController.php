<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @group Authentication
 */
class AuthController extends Controller
{
    /**
     * Login.
     * 
     * Returns a Sanctum plain-text token.
     */
    public function login(Request $request): array
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

        return [
            'token' => $user->createToken($request->device_name)->plainTextToken,
        ];
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
    public function logout(Request $request): array
    {
        $request->user()->currentAccessToken()->delete();

        return ['message' => 'Déconnecté avec succès.'];
    }
}
