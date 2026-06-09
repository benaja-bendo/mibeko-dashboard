<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PersonalAccessTokenResource;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @group Active Sessions
 *
 * Gestion des sessions actives, adossée aux jetons Sanctum
 * (`personal_access_tokens`) : un jeton = un appareil/navigateur connecté.
 */
class SessionController extends Controller
{
    use HttpResponses;

    /**
     * Liste les sessions actives, la plus récemment utilisée en premier.
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->orderByRaw('last_used_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->get();

        return $this->success(PersonalAccessTokenResource::collection($tokens), 'Sessions récupérées.');
    }

    /**
     * Révoque une session précise. Interdit de révoquer la session courante via
     * cet endpoint (utiliser /logout), pour éviter de se déconnecter par erreur.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $currentId = $this->currentTokenId($request);

        if ((string) $currentId === $id) {
            return $this->error(null, 'Impossible de révoquer la session courante depuis cette action.', 422);
        }

        $deleted = $request->user()->tokens()->where('id', $id)->delete();

        if ($deleted === 0) {
            return $this->error(null, 'Session introuvable.', 404);
        }

        return $this->success(null, 'Session révoquée.');
    }

    /**
     * Révoque toutes les sessions sauf la session courante.
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $currentId = $this->currentTokenId($request);

        $request->user()->tokens()
            ->when($currentId !== null, fn ($query) => $query->where('id', '!=', $currentId))
            ->delete();

        return $this->success(null, 'Les autres sessions ont été révoquées.');
    }

    /**
     * Identifiant du jeton courant, ou null si la session est transitoire (tests).
     */
    private function currentTokenId(Request $request): int|string|null
    {
        $token = $request->user()->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->getKey() : null;
    }
}
