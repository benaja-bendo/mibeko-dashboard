<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * Impersonation encadrée (« mode support »).
 *
 * Un admin peut obtenir un jeton agissant au nom d'un utilisateur pour
 * diagnostiquer un compte. Garde-fous : jamais sur soi-même, jamais sur un
 * autre admin, uniquement sur un compte actif. L'action est tracée dans la
 * table `audits`. Le jeton émis est nommé `impersonation:{adminId}` pour rester
 * identifiable et révocable indépendamment du jeton réel de l'utilisateur.
 *
 * @group Admin / Utilisateurs
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        if ($admin->getKey() === $user->getKey()) {
            return $this->error(null, 'Vous ne pouvez pas vous incarner vous-même.', 422);
        }

        if ($user->hasRole('admin')) {
            return $this->error(null, 'L\'impersonation d\'un administrateur est interdite.', 403);
        }

        if (($user->status ?? 'active') !== 'active') {
            return $this->error(null, 'Impossible d\'incarner un compte non actif.', 422);
        }

        $token = $user->createToken('impersonation:'.$admin->getKey())->plainTextToken;

        $this->recordAudit($user, $admin);

        return $this->success([
            'token' => $token,
            'user' => $this->formatUser($user),
        ], 'Mode support activé.');
    }

    /**
     * Trace l'impersonation comme évènement d'audit personnalisé sur la cible.
     */
    private function recordAudit(User $target, User $admin): void
    {
        $target->auditEvent = 'impersonation_started';
        $target->isCustomEvent = true;
        $target->auditCustomOld = [];
        $target->auditCustomNew = [
            'impersonated_by_id' => $admin->getKey(),
            'impersonated_by_name' => $admin->name,
        ];

        Event::dispatch(new AuditCustom($target));
    }

    /**
     * Normalise l'utilisateur au même format que le login (roles + permissions).
     *
     * @return array<string, mixed>
     */
    private function formatUser(User $user): array
    {
        return array_merge($user->toArray(), [
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]);
    }
}
