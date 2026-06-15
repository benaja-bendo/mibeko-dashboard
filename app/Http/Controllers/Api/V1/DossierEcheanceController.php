<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDossierEcheanceRequest;
use App\Http\Requests\Api\V1\UpdateDossierEcheanceRequest;
use App\Http\Resources\DossierEcheanceResource;
use App\Models\Dossier;
use App\Models\DossierEcheance;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD des échéances d'un dossier (web). Web-only au Palier 1 ; les colonnes
 * `client_*_at` sont posées pour une future synchronisation multi-appareils.
 */
class DossierEcheanceController extends Controller
{
    use HttpResponses;

    /**
     * Ajoute une échéance à un dossier de l'utilisateur.
     */
    public function store(StoreDossierEcheanceRequest $request, Dossier $dossier): JsonResponse
    {
        abort_if($dossier->user_id !== $request->user()->id, 404);

        $now = $this->clientClock();

        $echeance = $dossier->echeances()->create([
            ...$request->validated(),
            'client_created_at' => $now,
            'client_updated_at' => $now,
        ]);

        return $this->success(new DossierEcheanceResource($echeance), 'Échéance ajoutée.', 201);
    }

    /**
     * Mise à jour partielle d'une échéance.
     */
    public function update(UpdateDossierEcheanceRequest $request, DossierEcheance $echeance): JsonResponse
    {
        $this->ensureOwner($request, $echeance);

        $echeance->update([
            ...$request->validated(),
            'client_updated_at' => $this->clientClock(),
        ]);

        return $this->success(new DossierEcheanceResource($echeance), 'Échéance mise à jour.');
    }

    /**
     * Suppression douce d'une échéance.
     */
    public function destroy(Request $request, DossierEcheance $echeance): JsonResponse
    {
        $this->ensureOwner($request, $echeance);

        $echeance->delete();

        return $this->success(null, 'Échéance supprimée.');
    }

    /**
     * Refuse l'accès aux échéances des dossiers d'autrui (404).
     */
    private function ensureOwner(Request $request, DossierEcheance $echeance): void
    {
        abort_if($echeance->dossier->user_id !== $request->user()->id, 404);
    }

    private function clientClock(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
