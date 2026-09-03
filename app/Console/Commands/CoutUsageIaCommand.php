<?php

namespace App\Console\Commands;

use App\Models\AiUsageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Coût IA mesuré par utilisateur et par mois — mibeko-dashboard#61.
 *
 * Lit `ai_usage_logs`, jamais une estimation : c'est le socle que #76 et les
 * tickets de calibrage du quota attendent avant de trancher un chiffre.
 */
class CoutUsageIaCommand extends Command
{
    protected $signature = 'mibeko:cout-usage-ia
        {--mois= : Mois au format AAAA-MM (défaut : mois en cours)}
        {--utilisateur= : Ne montrer qu\'un seul utilisateur (UUID)}';

    protected $description = 'Coût IA mesuré par utilisateur pour un mois donné (mibeko-dashboard#61).';

    public function handle(): int
    {
        $mois = (string) ($this->option('mois') ?: now()->format('Y-m'));

        if (! preg_match('/^\d{4}-\d{2}$/', $mois)) {
            $this->error("Format de mois invalide : « {$mois} ». Attendu AAAA-MM.");

            return self::FAILURE;
        }

        $debut = "{$mois}-01";
        $fin = date('Y-m-d', strtotime("{$debut} +1 month"));

        $query = AiUsageLog::query()
            ->join('users', 'users.id', '=', 'ai_usage_logs.user_id')
            ->where('ai_usage_logs.created_at', '>=', $debut)
            ->where('ai_usage_logs.created_at', '<', $fin)
            ->when($this->option('utilisateur'), fn ($q, $id) => $q->where('ai_usage_logs.user_id', $id))
            ->groupBy('ai_usage_logs.user_id', 'users.name', 'users.email')
            ->orderByDesc(DB::raw('coalesce(sum(cost_estimated_fcfa), 0)'))
            ->get([
                'ai_usage_logs.user_id',
                'users.name',
                'users.email',
                DB::raw('count(*) as appels'),
                DB::raw("count(*) filter (where ai_usage_logs.status = 'success') as succes"),
                DB::raw("count(*) filter (where ai_usage_logs.status = 'rate_limited') as refuses_quota"),
                DB::raw("count(*) filter (where ai_usage_logs.status = 'error') as en_erreur"),
                DB::raw('coalesce(sum(tokens_input), 0) as jetons_entree'),
                DB::raw('coalesce(sum(tokens_output), 0) as jetons_sortie'),
                DB::raw('coalesce(sum(cost_estimated_fcfa), 0) as cout_fcfa'),
                DB::raw('count(*) filter (where cost_estimated_fcfa is null and ai_usage_logs.status = \'success\') as succes_sans_tarif'),
            ]);

        if ($query->isEmpty()) {
            $this->info("Aucun usage IA journalisé pour {$mois}.");

            return self::SUCCESS;
        }

        $this->table(
            ['Utilisateur', 'Appels', 'Succès', 'Refus quota', 'Erreurs', 'Jetons entrée', 'Jetons sortie', 'Coût (FCFA)', 'Succès sans tarif'],
            $query->map(fn ($r) => [
                $r->name ?: $r->email,
                $r->appels,
                $r->succes,
                $r->refuses_quota,
                $r->en_erreur,
                number_format((int) $r->jetons_entree, 0, ',', ' '),
                number_format((int) $r->jetons_sortie, 0, ',', ' '),
                number_format((float) $r->cout_fcfa, 2, ',', ' '),
                $r->succes_sans_tarif > 0 ? "⚠ {$r->succes_sans_tarif}" : '—',
            ])->all(),
        );

        $total = $query->sum('cout_fcfa');
        $this->newLine();
        $this->info(sprintf('Total %s : %s FCFA sur %d appel(s), %d utilisateur(s).', $mois, number_format($total, 2, ',', ' '), $query->sum('appels'), $query->count()));

        $sansTarif = $query->sum('succes_sans_tarif');
        if ($sansTarif > 0) {
            $this->warn("{$sansTarif} appel(s) réussi(s) sans tarif connu (config('ai.pricing')) — coût sous-estimé pour ces lignes, jetons mesurés quand même.");
        }

        return self::SUCCESS;
    }
}
