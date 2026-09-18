<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SearchLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Écran « requêtes fréquentes / sans résultat » — mibeko-dashboard#111.
 *
 * Agrégats en lecture seule sur `search_logs` : ce que le marché demande
 * réellement (top()), et le trou d'ingestion à combler en priorité
 * (noResults()), classé par demande.
 */
class SearchLogController extends Controller
{
    public function top(Request $request): JsonResponse
    {
        return $this->paginatedList($request);
    }

    public function noResults(Request $request): JsonResponse
    {
        return $this->paginatedList($request, onlyEmpty: true);
    }

    private function paginatedList(Request $request, bool $onlyEmpty = false): JsonResponse
    {
        $days = (int) $request->input('days', 30);

        $query = SearchLog::query()
            ->selectRaw('query, count(*) as volume, max(created_at) as last_searched_at')
            ->where('created_at', '>=', now()->subDays(max(1, $days)))
            ->when($onlyEmpty, fn ($q) => $q->where('results_count', 0))
            ->groupBy('query')
            ->orderByDesc('volume');

        $page = $query->paginate(20)->through(fn ($row) => [
            'query' => $row->query,
            'volume' => (int) $row->volume,
            'last_searched_at' => $row->last_searched_at,
        ]);

        return $this->paginatedSuccess($page);
    }
}
