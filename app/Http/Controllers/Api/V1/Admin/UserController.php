<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\V1\Admin\UserDetailResource;
use App\Http\Resources\V1\Admin\UserResource;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * Gestion fine des utilisateurs depuis l'espace administration.
 *
 * Remplace l'ancien UserController Inertia (legacy) : annuaire filtrable, fiche
 * détaillée, et actions de cycle de vie (statut, rôles, permissions directes,
 * reset, déconnexion forcée, suppression douce). Toutes les écritures sur un
 * `User` sont tracées dans la table `audits` (modèle auditable).
 *
 * @group Admin / Utilisateurs
 */
class UserController extends Controller
{
    private const ONLINE_WINDOW_MINUTES = 5;

    /**
     * Annuaire paginé : recherche (nom/email) + filtres (rôle, statut, présence,
     * vérification, segment équipe/client, corbeille) + tri.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['roles', 'subscriptions']);

        $this->applyTrashedScope($query, $request->query('trashed'));
        $this->applyFilters($query, $request);

        $perPage = min((int) $request->integer('per_page', 20) ?: 20, 100);
        $users = $query->latest()->paginate($perPage);

        return $this->paginatedSuccess($users, UserResource::class, 'Utilisateurs récupérés avec succès');
    }

    /**
     * Indicateurs de pilotage de la population d'utilisateurs.
     */
    public function stats(): JsonResponse
    {
        $onlineThreshold = now()->subMinutes(self::ONLINE_WINDOW_MINUTES);

        return $this->success([
            'total' => User::count(),
            'active' => User::where('status', 'active')->count(),
            'suspended' => User::where('status', 'suspended')->count(),
            'pending' => User::where('status', 'pending')->count(),
            'online' => User::where('last_seen_at', '>=', $onlineThreshold)->count(),
            'new_last_7_days' => User::where('created_at', '>=', now()->subDays(7))->count(),
            'new_last_30_days' => User::where('created_at', '>=', now()->subDays(30))->count(),
        ], 'Statistiques utilisateurs récupérées avec succès');
    }

    /**
     * Fiche détaillée d'un utilisateur (avec journal d'audit ciblé).
     */
    public function show(User $user): JsonResponse
    {
        $user->load([
            'roles',
            'settings',
            'audits' => fn ($query) => $query->with('user')->latest()->limit(15),
        ]);

        return $this->success(new UserDetailResource($user), 'Utilisateur récupéré avec succès');
    }

    /**
     * Création directe d'un compte par un admin (immédiatement utilisable).
     *
     * Si aucun mot de passe n'est fourni, un mot de passe aléatoire est généré
     * et renvoyé une seule fois (à transmettre à l'utilisateur).
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $generatedPassword = $request->filled('password') ? null : Str::password(16);
        $plainPassword = $request->validated('password') ?? $generatedPassword;

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($plainPassword),
            'status' => 'active',
        ]);

        // email_verified_at n'est pas mass-assignable : forcé explicitement.
        if ($request->boolean('mark_verified')) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->syncRoles($request->validated('roles'));
        $user->load('roles', 'subscriptions');

        return $this->success([
            'user' => new UserResource($user),
            // Renvoyé seulement quand généré côté serveur (jamais le MDP fourni).
            'generated_password' => $generatedPassword,
        ], 'Utilisateur créé avec succès', 201);
    }

    /**
     * Met à jour profil, statut, rôles et permissions directes.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        if ($request->has('name')) {
            $user->name = $request->validated('name');
        }

        if ($request->has('status')) {
            $error = $this->guardStatusChange($request, $user, $request->validated('status'));
            if ($error !== null) {
                return $error;
            }

            $this->applyStatus($user, $request->validated('status'), $request->validated('suspension_reason'));
        }

        $user->save();

        if ($request->has('roles')) {
            $newRoles = $request->validated('roles');
            $error = $this->guardRoleChange($user, $newRoles);
            if ($error !== null) {
                return $error;
            }

            $oldRoles = $user->getRoleNames()->sort()->values()->all();
            $user->syncRoles($newRoles);
            $this->auditRoleChange($user, $oldRoles, $newRoles);
        }

        if ($request->has('permissions')) {
            $user->syncPermissions($request->validated('permissions'));
        }

        $user->load('roles', 'subscriptions');

        return $this->success(new UserResource($user), 'Utilisateur mis à jour avec succès');
    }

    /**
     * Suppression douce (soft delete) — le compte peut être restauré.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($this->isSelf($request, $user)) {
            return $this->error(null, 'Vous ne pouvez pas supprimer votre propre compte.', 422);
        }

        if ($this->isLastAdmin($user)) {
            return $this->error(null, 'Impossible de supprimer le dernier administrateur.', 409);
        }

        $user->tokens()->delete();
        $user->delete();

        return $this->success(null, 'Utilisateur supprimé avec succès');
    }

    /**
     * Restaure un compte précédemment supprimé.
     */
    public function restore(string $id): JsonResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();
        $user->load('roles', 'subscriptions');

        return $this->success(new UserResource($user), 'Utilisateur restauré avec succès');
    }

    /**
     * Envoie à l'utilisateur un code de réinitialisation de mot de passe (OTP).
     */
    public function sendPasswordReset(User $user): JsonResponse
    {
        $code = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($code), 'created_at' => now()],
        );

        $user->notify(new PasswordResetCodeNotification($code));

        return $this->success(null, 'Un code de réinitialisation a été envoyé à l\'utilisateur.');
    }

    /**
     * Révoque tous les jetons d'accès : déconnecte l'utilisateur de partout.
     */
    public function revokeTokens(User $user): JsonResponse
    {
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        return $this->success(['revoked' => $count], 'Sessions révoquées : l\'utilisateur est déconnecté.');
    }

    /**
     * Marque manuellement l'adresse email comme vérifiée (support).
     */
    public function verifyEmail(User $user): JsonResponse
    {
        $user->forceFill(['email_verified_at' => now()])->save();

        return $this->success(null, 'Adresse email marquée comme vérifiée.');
    }

    /**
     * Désactive la double authentification (assistance, perte d'accès).
     */
    public function disableTwoFactor(User $user): JsonResponse
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $this->success(null, 'Double authentification désactivée.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Recherche et filtres communs à l'annuaire.
     *
     * @param  Builder<User>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function (Builder $sub) use ($search) {
                $sub->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('role')) {
            $role = $request->string('role')->toString();
            $query->whereHas('roles', fn (Builder $sub) => $sub->where('name', $role));
        }

        if ($request->filled('segment')) {
            $roles = $request->string('segment')->toString() === 'team'
                ? ['admin', 'editor']
                : ['user_pro', 'mobile_user'];
            $query->whereHas('roles', fn (Builder $sub) => $sub->whereIn('name', $roles));
        }

        if ($request->boolean('online')) {
            $query->where('last_seen_at', '>=', now()->subMinutes(self::ONLINE_WINDOW_MINUTES));
        }

        if ($request->has('verified')) {
            $request->boolean('verified')
                ? $query->whereNotNull('email_verified_at')
                : $query->whereNull('email_verified_at');
        }
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyTrashedScope(Builder $query, ?string $trashed): void
    {
        if ($trashed === 'only') {
            $query->onlyTrashed();
        } elseif ($trashed === 'with') {
            $query->withTrashed();
        }
    }

    /**
     * Applique un statut et synchronise les colonnes de suspension associées.
     */
    private function applyStatus(User $user, string $status, ?string $reason): void
    {
        $user->status = $status;

        if ($status === 'suspended') {
            $user->suspended_at = now();
            $user->suspension_reason = $reason;
            // Couper l'accès immédiatement.
            $user->tokens()->delete();
        } else {
            $user->suspended_at = null;
            $user->suspension_reason = null;
        }
    }

    /**
     * Empêche un admin de se suspendre/mettre en attente lui-même.
     */
    private function guardStatusChange(Request $request, User $user, string $status): ?JsonResponse
    {
        if ($status !== 'active' && $this->isSelf($request, $user)) {
            return $this->error(null, 'Vous ne pouvez pas modifier votre propre statut.', 422);
        }

        return null;
    }

    /**
     * Empêche de retirer le rôle admin au dernier administrateur.
     *
     * @param  array<int, string>  $roles
     */
    private function guardRoleChange(User $user, array $roles): ?JsonResponse
    {
        $removesAdmin = $user->hasRole('admin') && ! in_array('admin', $roles, true);

        if ($removesAdmin && $this->isLastAdmin($user)) {
            return $this->error(null, 'Impossible de retirer le rôle du dernier administrateur.', 409);
        }

        return null;
    }

    /**
     * Trace un changement de rôles via un audit personnalisé.
     *
     * Les rôles Spatie vivent dans une table pivot : owen-it n'audite pas leurs
     * changements automatiquement, on émet donc un évènement custom.
     *
     * @param  array<int, string>  $old
     * @param  array<int, string>  $new
     */
    private function auditRoleChange(User $user, array $old, array $new): void
    {
        sort($new);
        sort($old);

        if ($old === $new) {
            return;
        }

        $user->auditEvent = 'roles_updated';
        $user->isCustomEvent = true;
        $user->auditCustomOld = ['roles' => $old];
        $user->auditCustomNew = ['roles' => $new];

        Event::dispatch(new AuditCustom($user));
    }

    private function isSelf(Request $request, User $user): bool
    {
        return $request->user()?->getKey() === $user->getKey();
    }

    /**
     * Vrai si l'utilisateur est admin et qu'aucun autre admin (non supprimé) n'existe.
     */
    private function isLastAdmin(User $user): bool
    {
        if (! $user->hasRole('admin')) {
            return false;
        }

        $otherAdmins = User::role('admin')->whereKeyNot($user->getKey())->count();

        return $otherAdmins === 0;
    }
}
