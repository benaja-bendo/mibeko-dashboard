<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDossierRequest;
use App\Http\Requests\Api\V1\UpdateDossierRequest;
use App\Http\Resources\DossierResource;
use App\Models\Dossier;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD « affaire » des dossiers pour le tableau de bord web (avocat).
 *
 * Distinct de la synchronisation mobile (`DossierController`) mais opérant sur
 * la même table : chaque écriture pose `client_updated_at` (epoch ms) et toute
 * suppression est douce (tombstone), afin que la sync mobile voie les
 * changements faits côté web. Le mapping colonnes ↔ vocabulaire web se fait
 * dans la `DossierResource` (lecture) et `mapColumns()` (écriture).
 */
class DossierWebController extends Controller
{
    use HttpResponses;

    /**
     * Crée un dossier et, optionnellement, ses échéances initiales.
     */
    public function store(StoreDossierRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $now = $this->clientClock();

        $dossier = DB::transaction(function () use ($request, $validated, $now): Dossier {
            $dossier = Dossier::create([
                ...$this->mapColumns($validated),
                'user_id' => $request->user()->id,
                'client_created_at' => $now,
                'client_updated_at' => $now,
            ]);

            foreach ($validated['echeances'] ?? [] as $echeance) {
                $dossier->echeances()->create([
                    ...$echeance,
                    'client_created_at' => $now,
                    'client_updated_at' => $now,
                ]);
            }

            return $dossier;
        });

        return $this->success($this->present($dossier), 'Dossier créé.', 201);
    }

    /**
     * Détail d'un dossier de l'utilisateur, échéances comprises.
     */
    public function show(Request $request, Dossier $dossier): JsonResponse
    {
        $this->ensureOwner($request, $dossier);

        return $this->success($this->present($dossier));
    }

    /**
     * Mise à jour partielle. Seuls les champs fournis sont touchés (un `null`
     * explicite vide le champ).
     */
    public function update(UpdateDossierRequest $request, Dossier $dossier): JsonResponse
    {
        $this->ensureOwner($request, $dossier);

        $dossier->update([
            ...$this->mapColumns($request->validated()),
            'client_updated_at' => $this->clientClock(),
        ]);

        return $this->success($this->present($dossier), 'Dossier mis à jour.');
    }

    /**
     * Suppression douce (tombstone propagé à la sync mobile).
     */
    public function destroy(Request $request, Dossier $dossier): JsonResponse
    {
        $this->ensureOwner($request, $dossier);

        $dossier->delete();

        return $this->success(null, 'Dossier supprimé.');
    }

    /**
     * Recharge le dossier avec ses échéances triées (date croissante, nulles en fin).
     */
    private function present(Dossier $dossier): DossierResource
    {
        $dossier->load(['echeances' => fn ($query) => $query->orderByRaw('due_date ASC NULLS LAST')]);

        return new DossierResource($dossier);
    }

    /**
     * Refuse l'accès aux dossiers d'autrui en masquant leur existence (404).
     */
    private function ensureOwner(Request $request, Dossier $dossier): void
    {
        abort_if($dossier->user_id !== $request->user()->id, 404);
    }

    /**
     * Horloge client (epoch ms) — même format que la sync mobile.
     */
    private function clientClock(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Traduit le vocabulaire web validé en colonnes de stockage, en ne
     * conservant que les clés réellement présentes (mise à jour partielle).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapColumns(array $validated): array
    {
        $map = [
            'type' => 'type',
            'title' => 'name',
            'reference' => 'internal_reference',
            'client' => 'client_name',
            'client_role' => 'client_role',
            'adverse' => 'adverse_party',
            'jurisdiction' => 'jurisdiction',
            'nature' => 'nature',
            'matiere' => 'legal_domain',
            'status' => 'status',
            'description' => 'description',
            'color' => 'color',
        ];

        $columns = [];

        foreach ($map as $web => $column) {
            if (array_key_exists($web, $validated)) {
                $columns[$column] = $validated[$web];
            }
        }

        return $columns;
    }
}
