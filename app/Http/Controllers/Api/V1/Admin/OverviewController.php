<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\Article;
use App\Models\ContactMessage;
use App\Models\CurationFlag;
use App\Models\DocumentType;
use App\Models\ExtractionRun;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Models\PlanGrant;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * KPIs de l'accueil de l'espace administration.
 *
 * Volontairement en lecture seule et agrégé : sert de tableau de bord de
 * pilotage et de points d'entrée vers les rubriques détaillées.
 *
 * Deux natures de mesure cohabitent ici, et il ne faut pas les confondre
 * (mibeko-dashboard#106) :
 *
 * - `content`, `referentiels`, `people` comptent un **inventaire**. Un
 *   inventaire ne demande jamais d'action : il grossit, c'est tout.
 * - `attention` signale ce qui **demande une action** aujourd'hui, et
 *   `trends` dit si un chiffre monte ou descend. C'est ce qui manquait :
 *   avant #106, les deux seuls indicateurs d'alerte de l'écran valaient 1 et
 *   0 le 06/09/2026 — tableau tout vert — pendant que 18 des 28 appels IA du
 *   mois échouaient et que 10 messages de contact attendaient sans réponse.
 *
 * @group Admin / Vue d'ensemble
 */
class OverviewController extends Controller
{
    /**
     * Fenêtre des tendances, en jours. Chaque métrique se compare à la
     * fenêtre immédiatement précédente de même longueur.
     */
    private const TREND_WINDOW_DAYS = 7;

    /**
     * Fenêtre d'activité, en jours — même convention que `mibeko:kpis`.
     */
    private const ACTIVITY_WINDOW_DAYS = 30;

    /**
     * Échéance à partir de laquelle un abonnement Pro vendu à la main doit
     * être remonté : passé ce délai, le renouvellement se prépare.
     */
    private const EXPIRY_HORIZON_DAYS = 7;

    /**
     * Durée de mise en cache de la charge complète.
     *
     * Une quinzaine de comptages, dont plusieurs balayages complets sur
     * `legal_documents` et `article_versions` : mesuré à 24 s à froid sur une
     * base de développement chargée (53 952 documents, 209 569 versions), ce
     * qui suffisait à faire abandonner le navigateur. Un tableau de bord se
     * relit plusieurs fois par session sans que les chiffres bougent — une
     * minute de cache rend les visites suivantes immédiates.
     *
     * Le choix d'une minute, et pas dix comme `library:home`, tient au
     * bandeau d'alerte : il doit rester crédible comme signalement du jour,
     * pas afficher une panne éteinte depuis un quart d'heure.
     */
    private const CACHE_TTL_SECONDS = 60;

    public function index(): JsonResponse
    {
        $payload = Cache::remember(
            'admin:overview',
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array => $this->build(),
        );

        return $this->success($payload, 'Vue d\'ensemble admin récupérée avec succès');
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $now = now();

        // Compté UNE fois : `legal_documents` porte des SoftDeletes et la
        // table est large, ce comptage est le plus cher de la charge. Il
        // servait à la fois à l'inventaire et à la santé du corpus, et le
        // faire deux fois doublait à lui seul le temps de réponse.
        $documents = LegalDocument::count();

        return [
            'content' => [
                'documents' => $documents,
                'articles' => Article::count(),
                'official_journals' => OfficialJournal::count(),
            ],
            'referentiels' => [
                'document_types' => DocumentType::count(),
                'institutions' => Institution::count(),
                'tags' => Tag::count(),
            ],
            'people' => [
                'users' => User::count(),
            ],
            'attention' => $this->attention($now),
            'trend_window_days' => self::TREND_WINDOW_DAYS,
            'trends' => $this->trends($now),
            'adoption' => $this->adoption($now),
            'corpus' => $this->corpus($documents),
        ];
    }

    /**
     * Ce qui demande une action, par opposition à ce qui se contente de
     * grossir. Chaque entrée vaut 0 quand tout va bien : le bandeau de
     * l'écran est vide la plupart du temps, et c'est le comportement voulu.
     *
     * @return array<string, int>
     */
    private function attention(Carbon $now): array
    {
        $openFlags = CurationFlag::where('resolved', false);

        return [
            'open_flags' => (clone $openFlags)->count(),
            // Répartition par sévérité : seul `blocking` empêche la publication ;
            // sert de métrique de pilotage qualité de la curation.
            'open_flags_blocking' => (clone $openFlags)->where('severity', 'blocking')->count(),
            'open_flags_warning' => (clone $openFlags)->where('severity', 'warning')->count(),
            'failed_extractions' => ExtractionRun::where('status', 'failed')->count(),
            // Une panne fournisseur se lit ici le matin même : le 05/09/2026,
            // neuf HTTP 401 d'affilée n'avaient laissé aucune trace visible.
            'ai_errors_24h' => AiUsageLog::where('status', 'error')
                ->where('created_at', '>=', $now->copy()->subDay())
                ->count(),
            'unhandled_contacts' => ContactMessage::where('handled', false)->count(),
            // Sans cette ligne, un abonné payé une fois reste Pro jusqu'à
            // l'expiration sans que personne ne prépare le renouvellement.
            'plan_grants_expiring_soon' => PlanGrant::query()
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>', $now)
                ->where('ends_at', '<=', $now->copy()->addDays(self::EXPIRY_HORIZON_DAYS))
                ->count(),
        ];
    }

    /**
     * Trois séries datées, chacune comparée à la fenêtre précédente de même
     * longueur. Un total sans son antécédent ne dit pas s'il monte.
     *
     * L'activité (comptes actifs) n'est délibérément PAS une tendance :
     * `personal_access_tokens.last_used_at` ne retient que le dernier usage
     * d'un jeton, donc une fenêtre passée n'est pas reconstructible. Elle est
     * exposée telle quelle sous `adoption`, sans variation inventée.
     *
     * @return array<string, array{value: int|float, previous: int|float}>
     */
    private function trends(Carbon $now): array
    {
        $windowStart = $now->copy()->subDays(self::TREND_WINDOW_DAYS);
        $previousStart = $now->copy()->subDays(self::TREND_WINDOW_DAYS * 2);

        $successfulCalls = fn () => AiUsageLog::where('status', 'success');

        return [
            'new_users' => [
                'value' => User::where('created_at', '>=', $windowStart)->count(),
                'previous' => User::where('created_at', '>=', $previousStart)
                    ->where('created_at', '<', $windowStart)
                    ->count(),
            ],
            // « Questions » = appels réussis, même définition que
            // `mibeko:cout-usage-ia` et `mibeko:kpis` : un refus de quota ou
            // une erreur fournisseur n'est pas une question rendue.
            'ai_questions' => [
                'value' => $successfulCalls()->where('created_at', '>=', $windowStart)->count(),
                'previous' => $successfulCalls()->where('created_at', '>=', $previousStart)
                    ->where('created_at', '<', $windowStart)
                    ->count(),
            ],
            // Coût MESURÉ (`cost_estimated_fcfa`), jamais estimé à la volée.
            'ai_cost_fcfa' => [
                'value' => (float) $successfulCalls()->where('created_at', '>=', $windowStart)
                    ->sum('cost_estimated_fcfa'),
                'previous' => (float) $successfulCalls()->where('created_at', '>=', $previousStart)
                    ->where('created_at', '<', $windowStart)
                    ->sum('cost_estimated_fcfa'),
            ],
        ];
    }

    /**
     * Adoption par surface. Au 06/09/2026 : 41 comptes actifs sur mobile
     * contre 4 sur le web — un déséquilibre qui pilote la feuille de route et
     * qui n'apparaissait sur aucun écran.
     *
     * Les noms de jeton sont ceux de `KpisCommand::comptes()` : les deux
     * doivent compter la même chose, sinon l'écran et l'envoi hebdomadaire se
     * contrediront.
     *
     * @return array<string, int>
     */
    private function adoption(Carbon $now): array
    {
        $since = $now->copy()->subDays(self::ACTIVITY_WINDOW_DAYS);

        $activeSince = fn (?string $tokenName) => DB::table('personal_access_tokens')
            ->when($tokenName !== null, fn ($query) => $query->where('name', $tokenName))
            ->where('last_used_at', '>=', $since)
            ->distinct()
            ->count('tokenable_id');

        return [
            'mobile_active' => $activeSince('Mobile Device'),
            'web_active' => $activeSince('mibeko-saas-web'),
            // Compté à part et non additionné : un même compte peut porter un
            // jeton mobile ET un jeton web, la somme le compterait deux fois.
            'total_active' => $activeSince(null),
            'window_days' => self::ACTIVITY_WINDOW_DAYS,
        ];
    }

    /**
     * Santé du corpus. `versions_without_embedding` reprend exactement la
     * définition de `mibeko:prod-preflight` (table brute, aucun scope de
     * modèle) pour que les deux chiffres restent comparables.
     *
     * @param  int  $total  Total des documents, déjà compté par `build()`.
     * @return array<string, int>
     */
    private function corpus(int $total): array
    {
        $published = LegalDocument::where('curation_status', 'published')->count();

        return [
            'published' => $published,
            // Dérivé plutôt que compté par `!= 'published'` : une valeur nulle
            // sortirait silencieusement d'une comparaison SQL d'inégalité.
            'pending' => $total - $published,
            'versions_without_embedding' => DB::table('article_versions')
                ->whereNull('embedding')
                ->whereNull('deleted_at')
                ->count(),
        ];
    }
}
