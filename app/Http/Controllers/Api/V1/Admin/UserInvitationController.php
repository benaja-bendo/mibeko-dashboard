<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AcceptInvitationRequest;
use App\Http\Requests\Api\V1\Admin\StoreInvitationRequest;
use App\Http\Resources\V1\Admin\UserInvitationResource;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invitations d'équipe : un admin pré-enregistre une adresse + des rôles,
 * l'invité finalise son compte via un lien tokenisé.
 *
 * @group Admin / Utilisateurs
 */
class UserInvitationController extends Controller
{
    private const EXPIRY_DAYS = 7;

    /**
     * Liste des invitations (les plus récentes d'abord).
     */
    public function index(): JsonResponse
    {
        $invitations = UserInvitation::with('inviter')->latest()->get();

        return $this->success(
            UserInvitationResource::collection($invitations),
            'Invitations récupérées avec succès',
        );
    }

    /**
     * Crée et envoie une invitation par email.
     */
    public function store(StoreInvitationRequest $request): JsonResponse
    {
        $plainToken = Str::random(40);

        $invitation = UserInvitation::create([
            'email' => $request->validated('email'),
            'token' => Hash::make($plainToken),
            'roles' => $request->validated('roles'),
            'invited_by' => $request->user()->getKey(),
            'expires_at' => now()->addDays(self::EXPIRY_DAYS),
        ]);

        $this->sendInvitation($invitation, $plainToken, $request->user()->name);
        $invitation->load('inviter');

        return $this->success(
            new UserInvitationResource($invitation),
            'Invitation envoyée avec succès',
            201,
        );
    }

    /**
     * Régénère le token, repousse l'expiration et renvoie l'email.
     */
    public function resend(Request $request, UserInvitation $invitation): JsonResponse
    {
        if ($invitation->accepted_at !== null) {
            return $this->error(null, 'Cette invitation a déjà été acceptée.', 409);
        }

        $plainToken = Str::random(40);

        $invitation->update([
            'token' => Hash::make($plainToken),
            'expires_at' => now()->addDays(self::EXPIRY_DAYS),
        ]);

        $this->sendInvitation($invitation, $plainToken, $request->user()->name);
        $invitation->load('inviter');

        return $this->success(
            new UserInvitationResource($invitation),
            'Invitation renvoyée avec succès',
        );
    }

    /**
     * Annule une invitation.
     */
    public function destroy(UserInvitation $invitation): JsonResponse
    {
        $invitation->delete();

        return $this->success(null, 'Invitation annulée avec succès');
    }

    /**
     * Acceptation publique : crée le compte et connecte l'invité (auto-login).
     */
    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        $invitation = UserInvitation::where('email', $request->validated('email'))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($invitation === null || ! Hash::check($request->validated('token'), $invitation->token)) {
            return $this->error(null, 'Invitation invalide ou expirée.', 422);
        }

        if (User::where('email', $invitation->email)->exists()) {
            return $this->error(null, 'Un compte existe déjà pour cette adresse.', 409);
        }

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $invitation->email,
            'password' => Hash::make($request->validated('password')),
            'status' => 'active',
        ]);

        // L'accès au lien d'invitation prouve la possession de l'adresse
        // (email_verified_at n'est pas mass-assignable).
        $user->forceFill(['email_verified_at' => now()])->save();

        $user->syncRoles($invitation->roles);
        $invitation->update(['accepted_at' => now()]);

        $deviceName = $request->validated('device_name') ?? 'web';
        $user->load('roles');

        return $this->success([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => array_merge($user->toArray(), [
                'roles' => $user->getRoleNames()->values(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            ]),
        ], 'Compte créé avec succès. Bienvenue sur Mibeko.', 201);
    }

    /**
     * Envoie l'email d'invitation en « on-demand » (l'invité n'a pas de compte).
     */
    private function sendInvitation(UserInvitation $invitation, string $plainToken, ?string $inviterName): void
    {
        Notification::route('mail', $invitation->email)
            ->notify(new UserInvitationNotification($invitation, $plainToken, $inviterName));
    }
}
