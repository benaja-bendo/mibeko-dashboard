<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @group Newsletter
 *
 * Inscription à la newsletter depuis le site vitrine (route publique).
 */
class NewsletterSubscriptionController extends Controller
{
    /**
     * Inscrit une adresse à la newsletter.
     *
     * Idempotent : réinscrire la même adresse ne crée pas de doublon (contrainte
     * d'unicité + `firstOrCreate` sur l'e-mail normalisé) et répond quand même
     * 204. Aucun e-mail n'est envoyé ici — c'est un simple registre opt-in.
     *
     * @bodyParam email string required Adresse e-mail à inscrire.
     * @bodyParam source string Origine de l'inscription (page, campagne).
     *
     * @response 204
     */
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        NewsletterSubscription::firstOrCreate(
            ['email' => mb_strtolower(trim($validated['email']))],
            ['source' => $validated['source'] ?? null],
        );

        return response()->noContent();
    }
}
