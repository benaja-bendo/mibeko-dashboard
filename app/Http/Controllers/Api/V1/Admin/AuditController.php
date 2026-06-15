<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Admin\AuditResource;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Models\Audit;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Journal d'activité : exploitation de la table `audits` (owen-it) pour l'admin.
 *
 * Réimplémente l'ancien AuditController Inertia en API. Les entrées sont
 * rendues lisibles par {@see AuditResource}. Lecture seule, plus une purge.
 *
 * @group Admin / Journal d'activité
 */
class AuditController extends Controller
{
    /** Événements considérés « sensibles » pour le preset dédié. */
    private const SENSITIVE_EVENTS = ['deleted', 'restored', 'impersonation_started', 'roles_updated'];

    /**
     * Fil paginé et filtrable (par défaut : 7 derniers jours).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Audit::query()->with(['user', 'auditable']);
        $this->applyFilters($query, $request);

        $perPage = min((int) $request->integer('per_page', 25) ?: 25, 100);
        $audits = $query->latest()->paginate($perPage);

        return $this->paginatedSuccess($audits, AuditResource::class, 'Journal d\'activité récupéré avec succès');
    }

    /**
     * Indicateurs de pilotage de l'activité.
     */
    public function stats(): JsonResponse
    {
        $since = now()->subDays(30);

        $byEvent = Audit::where('created_at', '>=', $since)
            ->selectRaw('event, count(*) as total')
            ->groupBy('event')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['event' => $row->event, 'count' => (int) $row->total]);

        $topActorsRaw = Audit::whereNotNull('user_id')
            ->where('created_at', '>=', $since)
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $names = User::whereIn('id', $topActorsRaw->pluck('user_id'))->pluck('name', 'id');

        return $this->success([
            'today' => Audit::where('created_at', '>=', now()->startOfDay())->count(),
            'last_7_days' => Audit::where('created_at', '>=', now()->subDays(7))->count(),
            'last_30_days' => Audit::where('created_at', '>=', $since)->count(),
            'by_event' => $byEvent,
            'top_actors' => $topActorsRaw->map(fn ($row) => [
                'id' => $row->user_id,
                'name' => $names[$row->user_id] ?? '—',
                'count' => (int) $row->total,
            ])->values(),
        ], 'Statistiques d\'activité récupérées avec succès');
    }

    /**
     * Valeurs distinctes pour peupler les filtres côté front.
     */
    public function filters(): JsonResponse
    {
        $types = Audit::query()->distinct()->pluck('auditable_type')
            ->map(fn ($type) => ['value' => $type, 'label' => class_basename($type)])
            ->values();

        $actorIds = Audit::whereNotNull('user_id')->distinct()->pluck('user_id');
        $actors = User::whereIn('id', $actorIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name]);

        $events = Audit::query()->distinct()->orderBy('event')->pluck('event');

        return $this->success([
            'types' => $types,
            'actors' => $actors,
            'events' => $events,
        ], 'Filtres récupérés avec succès');
    }

    /**
     * Détail d'une entrée (diff complet).
     */
    public function show(Audit $audit): JsonResponse
    {
        $audit->load(['user', 'auditable']);

        return $this->success(new AuditResource($audit), 'Entrée d\'audit récupérée avec succès');
    }

    /**
     * Export CSV des entrées correspondant aux filtres courants (sans pagination).
     */
    public function export(Request $request): StreamedResponse
    {
        $query = Audit::query()->with('user');
        $this->applyFilters($query, $request);

        $filename = 'journal-activite-'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 pour Excel.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Date', 'Acteur', 'Événement', 'Type', 'Objet (id)', 'Champs modifiés', 'IP', 'URL']);

            $query->latest()->chunk(500, function ($audits) use ($handle) {
                foreach ($audits as $audit) {
                    $changed = array_unique(array_merge(
                        array_keys((array) $audit->old_values),
                        array_keys((array) $audit->new_values),
                    ));

                    fputcsv($handle, [
                        optional($audit->created_at)->toDateTimeString(),
                        $audit->user?->name ?? 'Système',
                        $audit->event,
                        class_basename($audit->auditable_type),
                        $audit->auditable_id,
                        implode(', ', $changed),
                        $audit->ip_address,
                        $audit->url,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Purge manuelle : supprime les entrées plus vieilles qu'un seuil.
     */
    public function purge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'older_than_days' => ['required_without:before', 'integer', 'min:1'],
            'before' => ['required_without:older_than_days', 'date'],
        ]);

        $threshold = isset($validated['before'])
            ? Carbon::parse($validated['before'])
            : now()->subDays((int) $validated['older_than_days']);

        $deleted = Audit::where('created_at', '<', $threshold)->delete();

        return $this->success(['deleted' => $deleted], "{$deleted} entrée(s) purgée(s).");
    }

    /**
     * Recherche, période et filtres communs (index + export).
     *
     * @param  Builder<Audit>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        // Période — défaut 7 jours.
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to'));
        }
        if (! $request->filled('from') && ! $request->filled('to')) {
            $period = $request->string('period', '7d')->toString();
            $days = match ($period) {
                'today' => 0,
                '30d' => 30,
                'all' => null,
                default => 7,
            };
            if ($days !== null) {
                $query->where('created_at', '>=', $days === 0 ? now()->startOfDay() : now()->subDays($days));
            }
        }

        if ($request->filled('event')) {
            $query->where('event', $request->string('event')->toString());
        }

        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', $request->string('auditable_type')->toString());
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->string('user_id')->toString());
        }

        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->where(function (Builder $sub) use ($search) {
                $sub->where('old_values', 'ilike', "%{$search}%")
                    ->orWhere('new_values', 'ilike', "%{$search}%")
                    ->orWhere('auditable_id', 'ilike', "%{$search}%");
            });
        }

        $preset = $request->string('preset')->toString();
        if ($preset === 'sensitive') {
            $query->where(function (Builder $sub) {
                $sub->whereIn('event', self::SENSITIVE_EVENTS)
                    ->orWhereIn('auditable_type', [User::class, UserSetting::class]);
            });
        } elseif ($preset === 'mine') {
            $query->where('user_id', $request->user()->getKey());
        }
    }
}
