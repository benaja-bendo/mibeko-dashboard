<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Dossier;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Synchronisation des dossiers utilisateur entre appareils.
 *
 * Stratégie : fusion last-write-wins par dossier sur `client_updated_at`
 * (horloge client, epoch ms). Le client pousse son état complet (dossiers
 * modifiés + suppressions) et reçoit l'état autoritaire du serveur, qu'il
 * applique localement. Les suppressions sont conservées en tombstones
 * (soft delete) afin de se propager aux autres appareils.
 */
class DossierController extends Controller
{
    use HttpResponses;

    /**
     * Liste l'état autoritaire des dossiers de l'utilisateur.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->success($this->authoritativeState($request->user()->id));
    }

    /**
     * Fusionne l'état client avec l'état serveur et renvoie l'état résultant.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dossiers' => ['sometimes', 'array'],
            'dossiers.*.id' => ['required', 'uuid'],
            'dossiers.*.name' => ['required', 'string', 'max:255'],
            'dossiers.*.legal_domain' => ['sometimes', 'string', 'max:255'],
            'dossiers.*.tag' => ['sometimes', 'string', 'in:EN_COURS,URGENT,ARCHIVE,FAVORIS'],
            'dossiers.*.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'dossiers.*.color' => ['sometimes', 'string', 'max:9'],
            'dossiers.*.created_at' => ['sometimes', 'integer', 'min:0'],
            'dossiers.*.updated_at' => ['required', 'integer', 'min:0'],
            'dossiers.*.articles' => ['sometimes', 'array', 'max:500'],
            'dossiers.*.articles.*.article_id' => ['required', 'uuid'],
            'dossiers.*.articles.*.personal_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'dossiers.*.articles.*.added_at' => ['sometimes', 'integer', 'min:0'],
            'deleted_ids' => ['sometimes', 'array'],
            'deleted_ids.*' => ['uuid'],
        ]);

        $userId = $request->user()->id;
        $clientDossiers = $validated['dossiers'] ?? [];
        $deletedIds = $validated['deleted_ids'] ?? [];

        DB::transaction(function () use ($userId, $clientDossiers, $deletedIds) {
            if ($deletedIds !== []) {
                Dossier::where('user_id', $userId)->whereIn('id', $deletedIds)->delete();
            }

            foreach ($clientDossiers as $payload) {
                if (in_array($payload['id'], $deletedIds, true)) {
                    continue;
                }

                $this->mergeDossier($userId, $payload);
            }
        });

        return $this->success($this->authoritativeState($userId), 'Dossiers synchronisés.');
    }

    /**
     * Applique la règle last-write-wins pour un dossier poussé par le client.
     *
     * @param  array{id: string, name: string, legal_domain?: string, tag?: string, description?: string|null, color?: string, created_at?: int, updated_at: int, articles?: array<int, array{article_id: string, personal_note?: string|null, added_at?: int}>}  $payload
     */
    private function mergeDossier(string $userId, array $payload): void
    {
        $existing = Dossier::withTrashed()->find($payload['id']);

        // L'UUID appartient à un autre utilisateur : on ignore (collision ou requête forgée).
        if ($existing !== null && $existing->user_id !== $userId) {
            return;
        }

        if ($existing !== null && $payload['updated_at'] <= $existing->client_updated_at) {
            // Tombstone ou version serveur plus récente : le serveur fait foi.
            return;
        }

        $attributes = [
            'user_id' => $userId,
            'name' => $payload['name'],
            'legal_domain' => $payload['legal_domain'] ?? 'Général',
            'tag' => $payload['tag'] ?? 'EN_COURS',
            'description' => $payload['description'] ?? null,
            'color' => $payload['color'] ?? '#1B3D2F',
            'client_created_at' => $payload['created_at'] ?? $payload['updated_at'],
            'client_updated_at' => $payload['updated_at'],
        ];

        if ($existing === null) {
            $dossier = Dossier::create(['id' => $payload['id'], ...$attributes]);
        } else {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update($attributes);
            $dossier = $existing;
        }

        $this->syncArticles($dossier, $payload['articles'] ?? []);
    }

    /**
     * Remplace la liste d'articles du dossier par celle du client gagnant,
     * en écartant silencieusement les articles inconnus côté serveur.
     *
     * @param  array<int, array{article_id: string, personal_note?: string|null, added_at?: int}>  $articles
     */
    private function syncArticles(Dossier $dossier, array $articles): void
    {
        $knownIds = Article::whereIn('id', array_column($articles, 'article_id'))
            ->pluck('id')
            ->all();
        $known = array_flip($knownIds);

        $pivot = [];
        foreach ($articles as $article) {
            if (isset($known[$article['article_id']])) {
                $pivot[$article['article_id']] = [
                    'personal_note' => $article['personal_note'] ?? null,
                    'added_at' => $article['added_at'] ?? 0,
                ];
            }
        }

        $dossier->articles()->sync($pivot);
    }

    /**
     * État complet des dossiers vivants de l'utilisateur, au format client.
     *
     * @return array{dossiers: array<int, array<string, mixed>>, deleted_ids: array<int, string>, synced_at: int}
     */
    private function authoritativeState(string $userId): array
    {
        $dossiers = Dossier::where('user_id', $userId)
            ->with('articles:id')
            ->orderByDesc('client_updated_at')
            ->get()
            ->map(fn (Dossier $dossier): array => [
                'id' => $dossier->id,
                'name' => $dossier->name,
                'legal_domain' => $dossier->legal_domain,
                'tag' => $dossier->tag,
                'description' => $dossier->description,
                'color' => $dossier->color,
                'created_at' => $dossier->client_created_at,
                'updated_at' => $dossier->client_updated_at,
                'articles' => $dossier->articles->map(fn (Article $article): array => [
                    'article_id' => $article->id,
                    'personal_note' => $article->pivot->personal_note,
                    'added_at' => (int) $article->pivot->added_at,
                ])->all(),
            ])
            ->all();

        $deletedIds = Dossier::onlyTrashed()
            ->where('user_id', $userId)
            ->pluck('id')
            ->all();

        return [
            'dossiers' => $dossiers,
            'deleted_ids' => $deletedIds,
            'synced_at' => (int) (microtime(true) * 1000),
        ];
    }
}
