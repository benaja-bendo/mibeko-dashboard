<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\ExtractionRun;
use App\Models\LegalDocument;
use App\Models\LegalWatchDispatch;
use App\Services\MailQueueHealthChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Console de santé du système (mibeko-dashboard#110).
 *
 * Le pipeline d'ingestion, la file de mail, la veille push et le parc mobile
 * échouaient chacun dans leur coin, sans témoin commun : mesuré en production
 * le 06/09/2026, 3 appareils Android enregistrés dont 0 vus sur 30 jours pour
 * 806 dispatches tentés, et 1 381 versions d'articles sans embedding, sans
 * qu'aucun écran ne le dise. Cet endpoint agrège les quatre sur un seul écran.
 *
 * @group Admin / Santé du système
 */
class SanteController extends Controller
{
    /** Nombre d'extractions en échec détaillées (motif + document concerné). */
    private const EXTRACTIONS_LIMIT = 20;

    /**
     * Un motif d'échec peut être une exception brute avec son dump de
     * paramètres SQL (constaté en dev : plusieurs milliers de caractères) —
     * tronqué pour rester lisible sur un écran qui doit tenir en un coup d'œil.
     */
    private const MOTIF_MAX_LENGTH = 240;

    /** Un document non publié depuis plus longtemps que ça est en retard de publication. */
    private const RETARD_PUBLICATION_JOURS = 7;

    /**
     * Même durée que `admin:overview` (mibeko-dashboard#106) : un tableau de
     * bord se relit plusieurs fois par session sans que les chiffres bougent.
     */
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly MailQueueHealthChecker $mailHealthChecker) {}

    public function index(): JsonResponse
    {
        $payload = Cache::remember(
            'admin:sante',
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array => $this->build(),
        );

        return $this->success($payload, 'Santé du système récupérée avec succès');
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        return [
            'extractions' => $this->extractions(),
            'veille' => $this->veille(),
            'mail' => $this->mail(),
            'parc_mobile' => $this->parcMobile(),
            'corpus' => $this->corpus(),
        ];
    }

    /**
     * Extractions en échec : jusqu'ici un simple total sur la vue d'ensemble,
     * sans jamais dire ni le motif ni le document concerné.
     *
     * @return array<string, mixed>
     */
    private function extractions(): array
    {
        $echouees = ExtractionRun::where('status', 'failed')
            ->with('document:id,titre_officiel,slug')
            ->latest('finished_at')
            ->limit(self::EXTRACTIONS_LIMIT)
            ->get(['id', 'document_id', 'finished_at', 'meta']);

        return [
            'total_echecs' => ExtractionRun::where('status', 'failed')->count(),
            'echecs' => $echouees->map(fn (ExtractionRun $run) => [
                'id' => $run->id,
                'document_id' => $run->document_id,
                'document_titre' => $run->document?->titre_officiel,
                'document_slug' => $run->document?->slug,
                'motif' => isset($run->meta['error'])
                    ? Str::limit((string) $run->meta['error'], self::MOTIF_MAX_LENGTH)
                    : null,
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Veille et push : dispatches envoyés, en échec, et appareils réellement
     * joignables — distinct des appareils simplement enregistrés.
     *
     * @return array<string, int>
     */
    private function veille(): array
    {
        return [
            'dispatches_delivres' => LegalWatchDispatch::where('status', LegalWatchDispatch::STATUS_DELIVERED)->count(),
            // `failed` seulement : un lot `pending` récent est en cours de
            // traitement normal, pas en échec. `mibeko:retry-legal-watch`
            // rejoue déjà les lots `undelivered()` trop anciens ; ici on
            // affiche ce qui a définitivement épuisé ses tentatives.
            'dispatches_en_echec' => LegalWatchDispatch::where('status', LegalWatchDispatch::STATUS_FAILED)->count(),
            'appareils_joignables' => Device::query()->pushable()->count(),
        ];
    }

    /**
     * File de traitement et mail : réutilise `MailQueueHealthChecker`, la même
     * mesure que `mibeko:surveiller-file-mail`, pour ne pas se désynchroniser
     * de l'alerte qui tourne déjà en tâche planifiée.
     *
     * @return array<string, mixed>
     */
    private function mail(): array
    {
        $echecs = $this->mailHealthChecker->echecs();
        $bloques = $this->mailHealthChecker->bloques();

        return [
            'echecs' => $echecs,
            'bloques' => $bloques,
            'total_echecs' => count($echecs),
            'total_bloques' => count($bloques),
        ];
    }

    /**
     * Versions de l'app mobile installées, pour savoir qui un changement d'API
     * peut casser (mibeko-dashboard#18) — et combien d'appareils actifs n'ont
     * jamais annoncé de version (format hérité, antérieur à la v1.2).
     *
     * @return array<string, mixed>
     */
    private function parcMobile(): array
    {
        $versions = DB::table('devices')
            ->where('status', 'active')
            ->whereNotNull('app_version')
            ->select('app_version', DB::raw('count(*) as total'))
            ->groupBy('app_version')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($ligne) => ['version' => $ligne->app_version, 'total' => (int) $ligne->total])
            ->all();

        return [
            'total_actifs' => Device::query()->active()->count(),
            'versions' => $versions,
            'version_inconnue' => Device::query()->active()->whereNull('app_version')->count(),
        ];
    }

    /**
     * Santé du corpus : publiés / en attente / signalés, versions sans
     * embedding, et retard de publication chiffré — pas seulement constaté.
     *
     * @return array<string, mixed>
     */
    private function corpus(): array
    {
        $total = LegalDocument::count();
        $published = LegalDocument::where('curation_status', LegalDocument::STATUS_PUBLISHED)->count();

        $enRetard = LegalDocument::where('curation_status', '!=', LegalDocument::STATUS_PUBLISHED)
            ->where('curation_status_changed_at', '<=', now()->subDays(self::RETARD_PUBLICATION_JOURS));

        return [
            'published' => $published,
            'pending' => $total - $published,
            'signales' => LegalDocument::whereHas(
                'curationFlags',
                fn ($query) => $query->where('resolved', false),
            )->count(),
            'versions_without_embedding' => DB::table('article_versions')->whereNull('embedding')->whereNull('deleted_at')->count(),
            'retard_publication' => [
                'seuil_jours' => self::RETARD_PUBLICATION_JOURS,
                'documents' => (clone $enRetard)->count(),
            ],
        ];
    }
}
