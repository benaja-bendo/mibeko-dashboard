<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentMessageFeedback;
use App\Models\Article;
use App\Models\Dossier;
use App\Models\DossierEcheance;
use App\Models\DossierGeneratedDocument;
use App\Models\DossierPiece;
use App\Models\DossierReference;
use App\Models\User;
use App\Traits\HttpResponses;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Privacy & GDPR
 *
 * Droit d'accès (export des données personnelles) et droit à l'effacement
 * (suppression de compte). Les opérations sont tracées via l'audit applicatif.
 */
class PrivacyController extends Controller
{
    use HttpResponses;

    /**
     * Exporte les données personnelles de l'utilisateur au format JSON (RGPD art. 20).
     *
     * Regroupe identité, profil étendu, préférences, consentements, notifications,
     * dossiers, favoris et conversations avec l'assistant en un fichier
     * téléchargeable. Aucune donnée d'un autre utilisateur n'est incluse.
     *
     * La section 6 de la politique de confidentialité
     * (mibeko-site/src/pages/confidentialite.astro) énumère ce contenu : toute
     * rubrique ajoutée ou retirée ici doit y être répercutée.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user()->load(
            'mobileProfile', 'settings', 'notifications', 'roles', 'tags',
            'onboardingEnrollments.stepsProgress', 'onboardingEnrollments.journey',
            'productActivationEvents', 'searchLogs'
        );
        $dossiers = $this->dossiers($user);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $user->getRoleNames()->values(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            // mibeko-dashboard#135 : cadre d'usage/métier/intérêts inclus au
            // même titre que le reste du profil étendu.
            'profile' => array_merge(
                $user->mobileProfile?->only(['phone', 'profession', 'usage_context', 'job_title', 'company', 'dob', 'gender']) ?? [],
                ['interests' => $user->tags->pluck('slug')->all()]
            ),
            'settings' => $user->settings?->only([
                'locale', 'timezone', 'date_format', 'notification_preferences',
                'marketing_consent', 'marketing_consent_at', 'analytics_consent', 'analytics_consent_at',
            ]),
            'notifications' => $user->notifications->map->only(['title', 'message', 'type', 'read_at', 'created_at']),
            // mibeko-dashboard#136 : progression d'onboarding. `value` est
            // déjà NULL en base pour un binding sensible (OnboardingStepWriter)
            // — rien à filtrer ici en plus, l'export ne fait que refléter l'état stocké.
            'onboarding' => $user->onboardingEnrollments->map(fn ($enrollment) => [
                'journey_key' => $enrollment->journey_key,
                'journey_version' => $enrollment->journey?->version,
                'status' => $enrollment->status,
                'started_at' => $enrollment->started_at?->toIso8601String(),
                'completed_at' => $enrollment->completed_at?->toIso8601String(),
                'replay_count' => $enrollment->replay_count,
                'steps' => $enrollment->stepsProgress->map(fn ($progress) => [
                    'step_key' => $progress->step_key,
                    'viewed_at' => $progress->viewed_at?->toIso8601String(),
                    'skipped_at' => $progress->skipped_at?->toIso8601String(),
                    'completed_at' => $progress->completed_at?->toIso8601String(),
                    'value' => $progress->value,
                ]),
            ]),
            // mibeko-dashboard#137 : détail nominatif d'activation produit.
            // Schéma déjà borné à des identifiants opaques — rien à filtrer,
            // l'export reflète l'état stocké.
            'product_activation' => $user->productActivationEvents->map(fn ($event) => [
                'event_type' => $event->event_type,
                'surface' => $event->surface,
                'reference_type' => $event->reference_type,
                'reference_id' => $event->reference_id,
                'created_at' => $event->created_at->toIso8601String(),
            ]),
            // mibeko-dashboard#111 : requêtes journalisées, retenues 90 jours
            // glissants (voir `mibeko:purge-search-logs`).
            'search_logs' => $user->searchLogs->map(fn ($log) => [
                'query' => $log->query,
                'results_count' => $log->results_count,
                'surface' => $log->surface,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
            'dossiers' => $dossiers->map(fn (Dossier $dossier) => $this->dossierPayload($dossier)),
            // Les dossiers étiquetés FAVORIS restent dans `dossiers` ; leurs
            // articles sont repris ici pour que l'usager trouve ses favoris
            // sous ce nom, sans connaître ce détail de stockage.
            'favorites' => $dossiers->where('tag', Dossier::TAG_FAVORIS)
                ->flatMap(fn (Dossier $dossier) => $dossier->articles)
                ->unique('id')
                ->map(fn (Article $article) => $this->dossierArticlePayload($article))
                ->values(),
            'assistant_conversations' => $this->assistantConversations($user),
        ];

        $filename = 'mibeko-donnees-'.$user->id.'-'.now()->format('Ymd').'.json';

        // StreamedResponse plutôt que success() : c'est un téléchargement, pas une réponse API.
        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * Supprime le compte (droit à l'effacement, RGPD art. 17).
     *
     * Exige le mot de passe courant, révoque tous les jetons puis applique un
     * soft-delete (conservation temporaire pour obligations légales avant purge).
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $user->tokens()->delete();

        // mibeko-dashboard#111 : `delete()` sur `User` est un SOFT delete, la
        // FK `nullOnDelete()` de `search_logs.user_id` ne se déclenche donc
        // jamais ici — anonymiser explicitement plutôt que laisser le journal
        // pointer vers un compte supprimé.
        $user->searchLogs()->update(['user_id' => null]);

        $user->delete();

        return $this->success(null, 'Votre compte a été supprimé.');
    }

    /**
     * Dossiers de l'utilisateur avec tous leurs éléments.
     *
     * Les dossiers supprimés (tombstones de synchronisation) sont exclus, comme
     * dans l'app. Un article retiré du corpus depuis son ajout reste exporté :
     * la note personnelle qui l'accompagne appartient à l'usager.
     *
     * @return EloquentCollection<int, Dossier>
     */
    private function dossiers(User $user): EloquentCollection
    {
        return $user->dossiers()
            ->with([
                'articles' => fn ($query) => $query->withTrashed()
                    ->select('articles.id', 'articles.document_id', 'articles.numero_article')
                    ->orderBy('dossier_articles.added_at'),
                'articles.document' => fn ($query) => $query->withTrashed()->select('id', 'titre_officiel'),
                'references' => fn ($query) => $query->orderBy('created_at'),
                'echeances' => fn ($query) => $query->orderByRaw('due_date ASC NULLS LAST'),
                'pieces' => fn ($query) => $query->orderBy('added_at'),
                'generatedDocuments' => fn ($query) => $query->orderBy('created_at'),
            ])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function dossierPayload(Dossier $dossier): array
    {
        return [
            ...$dossier->only([
                'id', 'name', 'type', 'status', 'tag', 'legal_domain', 'description',
                'internal_reference', 'client_name', 'client_role', 'adverse_party',
                'jurisdiction', 'nature', 'color',
            ]),
            'created_at' => $dossier->created_at?->toIso8601String(),
            'updated_at' => $dossier->updated_at?->toIso8601String(),
            'articles' => $dossier->articles->map(fn (Article $article) => $this->dossierArticlePayload($article)),
            'references' => $dossier->references->map(fn (DossierReference $reference) => [
                ...$reference->only(['type', 'target_id', 'title', 'breadcrumb', 'number', 'note']),
                'created_at' => $reference->created_at?->toIso8601String(),
            ]),
            'echeances' => $dossier->echeances->map(fn (DossierEcheance $echeance) => [
                ...$echeance->only(['type', 'title', 'status', 'trigger_event', 'is_confirmed', 'reminders', 'note']),
                'due_date' => $echeance->due_date?->format('Y-m-d'),
                'trigger_date' => $echeance->trigger_date?->format('Y-m-d'),
                'created_at' => $echeance->created_at?->toIso8601String(),
            ]),
            // Métadonnées seules : aucun fichier de pièce n'est stocké côté serveur.
            'pieces' => $dossier->pieces->map(fn (DossierPiece $piece) => [
                ...$piece->only(['name', 'mime', 'size', 'note']),
                'added_at' => $piece->added_at?->toIso8601String(),
            ]),
            'generated_documents' => $dossier->generatedDocuments->map(fn (DossierGeneratedDocument $document) => [
                ...$document->only(['title', 'template_name', 'html']),
                'created_at' => $document->created_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * Article rangé dans un dossier, avec de quoi le reconnaître sans l'API.
     *
     * @return array<string, mixed>
     */
    private function dossierArticlePayload(Article $article): array
    {
        $addedAt = (int) $article->pivot->added_at;

        return [
            'article_id' => $article->id,
            'article_number' => $article->numero_article,
            'document_title' => $article->document?->titre_officiel,
            'personal_note' => $article->pivot->personal_note,
            // Horloge de l'appareil en millisecondes ; 0 quand le client ne l'a pas transmise.
            'added_at' => $addedAt > 0 ? Carbon::createFromTimestampMs($addedAt)->toIso8601String() : null,
        ];
    }

    /**
     * Conversations avec l'assistant : titre, dates, messages et avis donnés.
     *
     * Seules les colonnes exportées sont lues. `tool_results` (texte intégral
     * des articles retrouvés, jusqu'à des centaines de Ko par conversation),
     * `tool_calls`, `usage` et `meta` restent en base : extraits du corpus
     * public ou télémétrie, pas des données de l'usager. La taille de l'export
     * suit ainsi ce que l'usager a écrit et reçu, non ce que l'assistant a lu.
     * Même sélection que le fil affiché (AiAssistantController::show) : ni
     * tour d'appel d'outil, ni contexte RAG legacy.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function assistantConversations(User $user): Collection
    {
        $conversations = $user->agentConversations()
            ->with(['messages' => fn ($query) => $query
                ->whereIn('role', ['user', 'assistant'])
                ->select('id', 'conversation_id', 'role', 'content', 'created_at')])
            ->orderBy('created_at')
            ->get(['id', 'title', 'created_at', 'updated_at']);

        $feedback = AgentMessageFeedback::query()
            ->where('user_id', $user->id)
            ->get(['message_id', 'rating', 'comment'])
            ->keyBy('message_id');

        return $conversations->map(fn (AgentConversation $conversation) => [
            'title' => $conversation->title,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
            'messages' => $conversation->messages
                ->map(fn (AgentConversationMessage $message) => [
                    'role' => $message->role,
                    'content' => $message->displayContent(),
                    'created_at' => $message->created_at?->toIso8601String(),
                    'feedback' => $feedback->get($message->id)?->only(['rating', 'comment']),
                ])
                ->filter(fn (array $message) => trim($message['content']) !== '')
                ->values(),
        ]);
    }
}
