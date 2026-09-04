<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EntitlementsResolver;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Entitlements
 *
 * Point unique de vérité du droit d'usage (mibeko-dashboard#63) : plan,
 * fonctionnalités, quotas et solde de l'utilisateur authentifié. Web et
 * mobile consomment cette charge unique à l'identique — aucun client ne
 * re-déduit la règle depuis un rôle ou un abonnement local.
 */
class EntitlementsController extends Controller
{
    use HttpResponses;

    public function __construct(private readonly EntitlementsResolver $resolver) {}

    public function show(Request $request): JsonResponse
    {
        return $this->success($this->resolver->resolve($request->user()));
    }
}
