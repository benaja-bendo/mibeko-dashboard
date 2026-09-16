<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\Tag;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Abonnement d'un utilisateur à un texte ou à un thème suivi
 * (mibeko-dashboard#125) — distinct du réglage global de veille légale
 * (`notification_preferences`), qui reste inchangé.
 */
class LegalWatchSubscriptionController extends Controller
{
    use HttpResponses;

    /** Alias courts exposés par l'API, jamais les noms de classe complets. */
    private const TYPE_ALIASES = [
        'document' => LegalDocument::class,
        'theme' => Tag::class,
    ];

    /**
     * Liste les abonnements de l'utilisateur courant.
     */
    public function index()
    {
        $subscriptions = Auth::user()->legalWatchSubscriptions()
            ->with('watchable')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($subscriptions);
    }

    /**
     * Abonne l'utilisateur courant à un texte ou un thème.
     *
     * Idempotent : suivre deux fois la même cible renvoie l'abonnement
     * existant plutôt que d'échouer — le bouton « suivre » du front n'a pas à
     * connaître l'état préalable.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'watchable_type' => ['required', Rule::in(array_keys(self::TYPE_ALIASES))],
            'watchable_id' => ['required', 'string'],
        ]);

        $modelClass = self::TYPE_ALIASES[$validated['watchable_type']];
        $model = new $modelClass;

        // `Rule::exists` interroge la table directement, sans le scope global
        // SoftDeletingScope d'Eloquent : un document supprimé y échapperait.
        $existsRule = Rule::exists($model->getTable(), $model->getKeyName());
        if (method_exists($model, 'trashed')) {
            $existsRule->withoutTrashed();
        }

        $request->validate(['watchable_id' => [$existsRule]]);

        $subscription = Auth::user()->legalWatchSubscriptions()->firstOrCreate([
            'watchable_type' => $modelClass,
            'watchable_id' => $validated['watchable_id'],
        ]);

        return $this->success($subscription->load('watchable'), 'Abonnement enregistré.', 201);
    }

    /**
     * Retire un abonnement de l'utilisateur courant.
     */
    public function destroy(string $id)
    {
        $subscription = Auth::user()->legalWatchSubscriptions()->findOrFail($id);
        $subscription->delete();

        return $this->success(null, 'Abonnement retiré.');
    }
}
