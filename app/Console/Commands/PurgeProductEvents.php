<?php

namespace App\Console\Commands;

use App\Models\ProductActivationEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rétention bornée du détail nominatif d'activation produit — mibeko-dashboard#137.
 *
 * Calquée sur `mibeko:prune-audits` (`--days=N`, `delete()`), avec une étape
 * en plus AVANT la suppression : agréger durablement (`product_activation_cohort_stats`,
 * sans `user_id`) toute cohorte hebdomadaire dont la fenêtre d'activation
 * (`config('product_activation.activation_window_days')`) est déjà entièrement
 * passée et pas encore agrégée. La rétention par défaut (`retention_days`,
 * 180j) est bien supérieure à cette fenêtre (90j) + celle du retour J+7 :
 * le détail est encore intégralement présent au moment du calcul, l'agrégat
 * est donc exact — jamais calculé sur des données déjà partiellement purgées.
 */
class PurgeProductEvents extends Command
{
    protected $signature = 'mibeko:purge-product-events
        {--days= : Conserver le détail nominatif des N derniers jours (config product_activation.retention_days par défaut)}';

    protected $description = "Agrège durablement puis purge le détail nominatif d'activation produit plus vieux que N jours.";

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('product_activation.retention_days')));
        $threshold = now()->subDays($days);

        $agregees = $this->rollUpMatureCohorts();

        $deleted = ProductActivationEvent::where('created_at', '<', $threshold)->delete();

        $this->info("Cohortes agrégées durablement : {$agregees}. Événements purgés (> {$days} j) : {$deleted}.");

        return self::SUCCESS;
    }

    private function rollUpMatureCohorts(): int
    {
        $activationWindow = (int) config('product_activation.activation_window_days');
        $returnStart = (int) config('product_activation.return_window.start_days');
        $returnEnd = (int) config('product_activation.return_window.end_days');

        // "Mature pour l'agrégat" : le compte le plus TARDIF possible de la
        // semaine (fin de semaine = début de semaine + 7j) a déjà dépassé la
        // fenêtre d'activation — jamais un cutoff optimiste qui agrégerait
        // une cohorte encore en cours de mesure.
        $semaines = DB::select('
            select date_trunc(\'week\', u.created_at)::date as semaine
            from users u
            where u.deleted_at is null
            group by date_trunc(\'week\', u.created_at)
            having now() >= date_trunc(\'week\', u.created_at) + interval \'7 days\' + (? * interval \'1 day\')
               and not exists (
                   select 1 from product_activation_cohort_stats s
                   where s.cohort_week = min(date_trunc(\'week\', u.created_at))::date
               )
            order by 1
        ', [$activationWindow]);

        foreach ($semaines as $ligne) {
            $this->upsertCohortStats($ligne->semaine, $returnStart, $returnEnd);
        }

        return count($semaines);
    }

    private function upsertCohortStats(string $semaine, int $returnStart, int $returnEnd): void
    {
        $stats = DB::selectOne('
            with cohorte as (
                select id, created_at
                from users
                where deleted_at is null and date_trunc(\'week\', created_at)::date = ?
            ),
            recherche as (
                select distinct user_id from product_activation_events where event_type = \'search_useful\'
            ),
            reponse as (
                select distinct user_id from ai_usage_logs where route = \'assistant/chat\' and status = \'success\'
            ),
            activation as (
                select distinct user_id from product_activation_events where event_type = \'source_opened_after_answer\'
            ),
            premiere_activation as (
                select user_id, min(created_at) as activee_le
                from product_activation_events
                where event_type = \'source_opened_after_answer\'
                group by user_id
            ),
            mature as (
                select id, created_at from cohorte where now() >= created_at + (? * interval \'1 day\')
            )
            select
                (select count(*) from cohorte) as cohort_size,
                (select count(*) from cohorte c join recherche r on r.user_id = c.id) as reached_search_useful,
                (select count(*) from cohorte c join reponse rp on rp.user_id = c.id) as reached_success_reply,
                (select count(*) from cohorte c join activation a on a.user_id = c.id) as reached_activation_candidate,
                (select percentile_cont(0.5) within group (order by extract(epoch from (pa.activee_le - c.created_at)) / 86400.0)
                 from cohorte c join premiere_activation pa on pa.user_id = c.id) as median_days_to_activation,
                (select count(*) from mature) as d7_eligible,
                (select count(*) from mature m where exists (
                    select 1 from personal_access_tokens t
                    where t.tokenable_id = m.id
                      and t.last_used_at between m.created_at + (? * interval \'1 day\') and m.created_at + (? * interval \'1 day\')
                ) or exists (
                    select 1 from ai_usage_logs a
                    where a.user_id = m.id
                      and a.created_at between m.created_at + (? * interval \'1 day\') and m.created_at + (? * interval \'1 day\')
                ) or exists (
                    select 1 from product_activation_events p
                    where p.user_id = m.id
                      and p.created_at between m.created_at + (? * interval \'1 day\') and m.created_at + (? * interval \'1 day\')
                )) as d7_returned
        ', [$semaine, $returnEnd, $returnStart, $returnEnd, $returnStart, $returnEnd, $returnStart, $returnEnd]);

        DB::table('product_activation_cohort_stats')->updateOrInsert(
            ['cohort_week' => $semaine],
            [
                'cohort_size' => $stats->cohort_size,
                'reached_search_useful' => $stats->reached_search_useful,
                'reached_success_reply' => $stats->reached_success_reply,
                'reached_activation_candidate' => $stats->reached_activation_candidate,
                'median_days_to_activation' => $stats->median_days_to_activation,
                'd7_eligible' => $stats->d7_eligible,
                'd7_returned' => $stats->d7_returned,
                'computed_at' => now(),
            ]
        );
    }
}
