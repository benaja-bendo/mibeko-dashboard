<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CurationFlagResource;
use App\Models\CurationFlag;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CurationFlagController extends Controller
{
    use HttpResponses;

    /**
     * Enregistrer un nouveau signalement (erreur, doublon, problème structure).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_id' => ['nullable', 'uuid', 'exists:legal_documents,id'],
            'article_id' => ['nullable', 'uuid', 'exists:articles,id'],
            'type_probleme' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), 'Données invalides', 422);
        }

        // On exige au moins l'un des deux (document_id ou article_id)
        if (! $request->document_id && ! $request->article_id) {
            return $this->error(
                ['target' => ['Le signalement doit concerner soit un document, soit un article.']],
                'Cible manquante',
                422
            );
        }

        // Endpoint public (pas d'authentification) : on force source et
        // sévérité côté serveur au lieu d'hériter des défauts DB
        // ('human'/'blocking'), sinon un appelant anonyme pourrait poser un
        // flag bloquant la publication et jamais purgé par les détecteurs.
        // Un signalement public reste informatif tant qu'un admin ne l'a pas
        // requalifié au triage.
        $flag = CurationFlag::create([
            'document_id' => $request->document_id,
            'article_id' => $request->article_id,
            'source' => CurationFlag::SOURCE_REPORT,
            'severity' => CurationFlag::SEVERITY_INFO,
            'type_probleme' => $request->type_probleme,
            'description' => $request->description,
            'resolved' => false,
        ]);

        return $this->success(
            $flag,
            'Signalement enregistré avec succès.',
            201
        );
    }

    /**
     * Demander un texte absent du catalogue (mibeko-front#34).
     *
     * Distinct de `store()` : authentifié (pas d'endpoint public équivalent
     * pour ce cas), jamais de cible (`document_id`/`article_id` toujours
     * nuls — la recherche n'a par définition rien trouvé), et `created_by`
     * posé pour que l'utilisateur retrouve sa demande via `mine()`.
     */
    public function storeMissingText(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:5000'],
        ]);

        $flag = CurationFlag::create([
            'source' => CurationFlag::SOURCE_REPORT,
            'severity' => CurationFlag::SEVERITY_INFO,
            'type_probleme' => CurationFlag::TYPE_TEXTE_MANQUANT,
            'description' => $validated['description'],
            'created_by' => $request->user()->id,
            'resolved' => false,
        ]);

        return $this->success(
            new CurationFlagResource($flag),
            'Votre demande a été enregistrée.',
            201
        );
    }

    /**
     * Les demandes de texte manquant de l'utilisateur courant (mibeko-front#34)
     * — c'est ce qui lui permet de retrouver sa demande plus tard.
     */
    public function mine(Request $request): JsonResponse
    {
        $flags = CurationFlag::query()
            ->where('created_by', $request->user()->id)
            ->where('type_probleme', CurationFlag::TYPE_TEXTE_MANQUANT)
            ->latest()
            ->paginate(20);

        return $this->paginatedSuccess($flags, CurationFlagResource::class);
    }
}
