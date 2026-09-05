<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Ai\AiUserQuotaTier;
use App\Http\Controllers\Controller;
use App\Models\AiQuotaTierSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réglage des quotas IA par palier depuis l'espace admin — mibeko-dashboard#95.
 *
 * Les trois paliers (`standard`/`user_pro`/`admin`) existent toujours,
 * réglage posé en base ou non : `index` renvoie la limite EFFECTIVE
 * (`ai_quota_tier_settings` si posée, sinon `config('ai.quotas')`) pour que
 * l'admin voie et édite toujours la vraie valeur appliquée, jamais une
 * ligne vide. Ne porte jamais la portée (jour/mois) — fixée par le rôle,
 * `App\Ai\AiUserQuotaTier::tierDefinition()` reste la seule source.
 *
 * @group Admin / Quotas IA
 */
class AiQuotaTierController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = AiQuotaTierSetting::query()->pluck('limit', 'tier');

        $tiers = collect(AiQuotaTierSetting::TIERS)
            ->map(fn (string $tier) => $this->present($tier, $settings->get($tier)))
            ->values();

        return $this->success($tiers, 'Paliers de quota IA récupérés avec succès');
    }

    public function update(Request $request, string $tier): JsonResponse
    {
        abort_unless(in_array($tier, AiQuotaTierSetting::TIERS, true), 404);

        $validated = $request->validate([
            'limit' => ['required', 'integer', 'min:1'],
        ]);

        $setting = AiQuotaTierSetting::query()->updateOrCreate(
            ['tier' => $tier],
            ['limit' => $validated['limit']],
        );

        return $this->success($this->present($tier, $setting->limit), 'Palier de quota IA mis à jour avec succès');
    }

    /**
     * Retire le réglage posé en base : le palier retombe sur `config/ai.php`.
     */
    public function destroy(string $tier): JsonResponse
    {
        abort_unless(in_array($tier, AiQuotaTierSetting::TIERS, true), 404);

        AiQuotaTierSetting::query()->where('tier', $tier)->delete();

        return $this->success($this->present($tier, null), 'Palier de quota IA réinitialisé sur la valeur par défaut');
    }

    /**
     * @return array{tier: string, scope: string, limit: int, source: 'database'|'config'}
     */
    private function present(string $tier, ?int $dbLimit): array
    {
        ['scope' => $scope, 'configKey' => $configKey] = AiUserQuotaTier::tierDefinition($tier);

        return [
            'tier' => $tier,
            'scope' => $scope,
            'limit' => $dbLimit ?? (int) config($configKey),
            'source' => $dbLimit !== null ? 'database' : 'config',
        ];
    }
}
