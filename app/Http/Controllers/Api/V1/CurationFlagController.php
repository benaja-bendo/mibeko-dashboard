<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CurationFlag;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Traits\HttpResponses;
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
            'description' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), 'Données invalides', 422);
        }

        // On exige au moins l'un des deux (document_id ou article_id)
        if (!$request->document_id && !$request->article_id) {
            return $this->error(
                ['target' => ['Le signalement doit concerner soit un document, soit un article.']],
                'Cible manquante',
                422
            );
        }

        $flag = CurationFlag::create([
            'document_id' => $request->document_id,
            'article_id' => $request->article_id,
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
}
