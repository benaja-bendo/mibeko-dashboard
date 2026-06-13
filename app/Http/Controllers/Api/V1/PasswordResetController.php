<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @group Authentication
 *
 * Réinitialisation de mot de passe par code OTP (clients API mobile).
 *
 * Flux : `forgot` envoie un code à 6 chiffres par email (validité 15 min),
 * `reset` vérifie le code et définit le nouveau mot de passe. La réponse de
 * `forgot` est identique que le compte existe ou non (anti-énumération).
 */
class PasswordResetController extends Controller
{
    use HttpResponses;

    private const CODE_TTL_MINUTES = 15;

    /**
     * Request a password reset code.
     *
     * @response 200 {"success": true, "message": "Si un compte existe pour cette adresse, un code de réinitialisation a été envoyé.", "data": null}
     */
    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user !== null) {
            $code = (string) random_int(100000, 999999);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($code), 'created_at' => now()],
            );

            $user->notify(new PasswordResetCodeNotification($code));
        }

        return $this->success(
            null,
            'Si un compte existe pour cette adresse, un code de réinitialisation a été envoyé.',
        );
    }

    /**
     * Reset the password with the emailed code.
     *
     * Révoque tous les jetons Sanctum existants après réinitialisation.
     *
     * @response 200 {"success": true, "message": "Mot de passe réinitialisé. Vous pouvez vous connecter.", "data": null}
     */
    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->first();

        $isExpired = $record === null
            || now()->diffInMinutes($record->created_at, true) > self::CODE_TTL_MINUTES;

        if ($isExpired || ! Hash::check($validated['code'], $record->token)) {
            throw ValidationException::withMessages([
                'code' => ['Le code est invalide ou a expiré.'],
            ]);
        }

        $user = User::where('email', $validated['email'])->firstOrFail();
        $user->update(['password' => Hash::make($validated['password'])]);
        $user->tokens()->delete();

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        return $this->success(null, 'Mot de passe réinitialisé. Vous pouvez vous connecter.');
    }
}
