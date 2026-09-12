<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs produit hebdomadaires — mibeko-dashboard#97.
 *
 * Reproduit en une commande les mesures faites à la main le 05/09/2026 pour la
 * revue stratégique (comptes actifs par surface, usage réel de l'Assistant,
 * remplissage des dossiers, veille joignable, corpus publié) : jusqu'ici, ces
 * chiffres exigeaient une session manuelle de requêtes SQL en lecture seule.
 *
 * Comme `mibeko:prod-preflight`, aucun modèle Eloquent n'est utilisé : les
 * modèles sont liés à la connexion par défaut et interrogeraient le
 * développement sans que cela se voie sur une commande censée lire la prod.
 */
class KpisCommand extends Command
{
    protected $signature = 'mibeko:kpis
        {--connection=pgsql_prod_ro : Connexion à interroger (pgsql_prod_ro par défaut ; passer la connexion locale pour un contrôle en dev)}';

    protected $description = 'Indicateurs produit hebdomadaires (comptes actifs, usage IA, dossiers, veille, corpus) — revue stratégique du 05/09/2026.';

    public function handle(): int
    {
        $nom = (string) $this->option('connection');

        if (! is_array(config("database.connections.{$nom}"))) {
            $this->error("La connexion « {$nom} » n'est pas déclarée dans config/database.php.");

            return self::FAILURE;
        }

        $connexion = DB::connection($nom);

        try {
            $connexion->getPdo();
        } catch (\Throwable $e) {
            $this->error('Connexion impossible : '.$e->getMessage());

            if ($nom === 'pgsql_prod_ro') {
                $this->line('Le tunnel SSH est-il bien ouvert ? ssh -N -L 5434:127.0.0.1:5432 ubuntu@<IP_VPS>');
                $this->line('Variables requises : PROD_RO_DB_* (cf. .env.example).');
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Connexion interrogée : <fg=yellow>{$nom}</> — ".now()->toDateTimeString());

        $this->comptes($connexion);
        $this->usageIa($connexion);
        $this->dossiers($connexion);
        $this->veille($connexion);
        $this->corpus($connexion);
        $this->activation($connexion);

        return self::SUCCESS;
    }

    private function comptes(Connection $connexion): void
    {
        $this->newLine();
        $this->info('## Comptes');

        $global = $connexion->selectOne("
            select
                count(*) filter (where deleted_at is null) as vivants,
                count(*) filter (where deleted_at is null and created_at >= now() - interval '7 days') as j7,
                count(*) filter (where deleted_at is null and created_at >= now() - interval '30 days') as j30,
                count(*) filter (where deleted_at is null and created_at >= now() - interval '90 days') as j90
            from users
        ");

        $this->table(
            ['Vivants', 'Créés 7j', 'Créés 30j', 'Créés 90j'],
            [[$global->vivants, $global->j7, $global->j30, $global->j90]],
        );

        $origines = $connexion->select("
            select
                case
                    when name = 'Mobile Device' then 'mobile'
                    when name = 'mibeko-saas-web' then 'web'
                    else 'autre'
                end as origine,
                count(distinct tokenable_id) as comptes_lies,
                count(distinct tokenable_id) filter (where last_used_at >= now() - interval '30 days') as actifs_30j
            from personal_access_tokens
            group by 1
            order by 2 desc
        ");

        $this->table(
            ['Origine (jeton)', 'Comptes liés', 'Actifs 30j'],
            collect($origines)->map(fn ($r) => [$r->origine, $r->comptes_lies, $r->actifs_30j])->all(),
        );

        $mobile = collect($origines)->firstWhere('origine', 'mobile');
        $web = collect($origines)->firstWhere('origine', 'web');
        $this->line(sprintf(
            'Comptes vivants : %d — actifs 30j : %d mobile, %d web.',
            $global->vivants,
            $mobile->actifs_30j ?? 0,
            $web->actifs_30j ?? 0,
        ));
    }

    private function usageIa(Connection $connexion): void
    {
        $this->newLine();
        $this->info('## Assistant IA');

        $parMois = $connexion->select("
            select
                to_char(created_at, 'YYYY-MM') as mois,
                count(*) filter (where status = 'success') as questions,
                count(distinct user_id) as utilisateurs,
                coalesce(round(sum(cost_estimated_fcfa) filter (where status = 'success')::numeric, 1), 0) as cout_fcfa
            from ai_usage_logs
            where created_at >= now() - interval '6 months'
            group by 1
            order by 1
        ");

        if (empty($parMois)) {
            $this->line('Aucun usage IA journalisé sur les 6 derniers mois.');

            return;
        }

        $this->table(
            ['Mois', 'Questions réussies', 'Utilisateurs', 'Coût mesuré (FCFA)'],
            collect($parMois)->map(fn ($r) => [
                $r->mois,
                $r->questions,
                $r->utilisateurs,
                number_format((float) $r->cout_fcfa, 1, ',', ' '),
            ])->all(),
        );

        $dernier = collect($parMois)->last();
        if ($dernier && (int) $dernier->utilisateurs > 0) {
            $moyenne = round($dernier->questions / $dernier->utilisateurs, 1);
            $this->line("Moyenne du dernier mois plein : {$moyenne} question(s) par utilisateur actif.");
        }
    }

    private function dossiers(Connection $connexion): void
    {
        $this->newLine();
        $this->info('## Dossiers');

        $d = $connexion->selectOne('
            select
                count(*) filter (where deleted_at is null) as vivants,
                count(*) filter (
                    where deleted_at is null
                    and (client_name is not null or adverse_party is not null
                         or jurisdiction is not null or internal_reference is not null)
                ) as avec_champs_affaire,
                count(distinct user_id) filter (where deleted_at is null) as utilisateurs
            from dossiers
        ');

        $e = $connexion->selectOne('
            select
                count(*) as total,
                count(*) filter (where due_date >= current_date) as a_venir
            from dossier_echeances
            where deleted_at is null
        ');

        $this->table(
            ['Vivants', 'Avec champs d\'affaire', 'Utilisateurs', 'Échéances (total)', 'Échéances à venir'],
            [[$d->vivants, $d->avec_champs_affaire, $d->utilisateurs, $e->total, $e->a_venir]],
        );

        $this->line(sprintf(
            'Dossiers vivants : %d, dont %d avec au moins un champ d\'affaire renseigné.',
            $d->vivants,
            $d->avec_champs_affaire,
        ));
    }

    private function veille(Connection $connexion): void
    {
        $this->newLine();
        $this->info('## Veille (appareils push)');

        $appareils = $connexion->select("
            select
                platform,
                count(*) as total,
                count(*) filter (where updated_at >= now() - interval '30 days') as vus_30j
            from devices
            group by 1
            order by 1
        ");

        if (empty($appareils)) {
            $this->line('Aucun appareil enregistré.');

            return;
        }

        $this->table(
            ['Plateforme', 'Appareils', 'Vus 30j'],
            collect($appareils)->map(fn ($r) => [$r->platform, $r->total, $r->vus_30j])->all(),
        );

        $this->line(sprintf(
            'Appareils enregistrés : %d, dont %d vus sur 30 jours.',
            collect($appareils)->sum('total'),
            collect($appareils)->sum('vus_30j'),
        ));
    }

    private function corpus(Connection $connexion): void
    {
        $this->newLine();
        $this->info('## Corpus');

        $c = $connexion->selectOne("
            select
                count(*) filter (where curation_status = 'published') as publies,
                count(*) filter (where curation_status <> 'published') as non_publies
            from legal_documents
            where deleted_at is null
        ");

        $this->table(
            ['Publiés', 'Non publiés'],
            [[$c->publies, $c->non_publies]],
        );

        $this->line(sprintf('Corpus : %d publié(s), %d non publié(s).', $c->publies, $c->non_publies));
    }

    /**
     * mibeko-dashboard#137 : jalons d'usage réel (pas l'onboarding lui-même,
     * cf. `onboarding_enrollments`/`onboarding_step_progress` #136, exclus
     * ici — « un guide passé ne constitue pas une activation »). Cohortes
     * hebdomadaires sur 12 semaines glissantes ; « réponse réussie » lue sur
     * `ai_usage_logs` (#61), « recherche utile »/« activation candidate » sur
     * `product_activation_events` (#137, seuls jalons sans autre source de
     * vérité serveur). Retour J+7 calculé UNIQUEMENT pour les comptes dont
     * la fenêtre est déjà entièrement passée — « non mesurable » sinon,
     * jamais un 0 % qui mélangerait « pas revenu » et « pas encore mesurable ».
     *
     * Limite assumée : la ventilation par surface réutilise l'approximation
     * déjà en place dans `comptes()` (origine par nom de jeton) — aucune des
     * tables lues ici (`ai_usage_logs`, `onboarding_*`) ne porte de colonne
     * de plateforme par ligne, seuls les 2 nouveaux événements de #137 en
     * portent une.
     */
    private function activation(Connection $connexion): void
    {
        $this->newLine();
        $this->info('## Activation produit');

        $returnStart = (int) config('product_activation.return_window.start_days');
        $returnEnd = (int) config('product_activation.return_window.end_days');

        $cohortes = $connexion->select("
            with cohorte as (
                select id, created_at, date_trunc('week', created_at)::date as semaine
                from users
                where deleted_at is null and created_at >= now() - interval '12 weeks'
            ),
            recherche as (
                select distinct user_id from product_activation_events where event_type = 'search_useful'
            ),
            reponse as (
                select distinct user_id from ai_usage_logs where route = 'assistant/chat' and status = 'success'
            ),
            activation as (
                select distinct user_id from product_activation_events where event_type = 'source_opened_after_answer'
            )
            select
                c.semaine,
                count(*) as taille,
                count(*) filter (where r.user_id is not null) as recherche_utile,
                count(*) filter (where rp.user_id is not null) as reponse_reussie,
                count(*) filter (where a.user_id is not null) as activation_candidate
            from cohorte c
            left join recherche r on r.user_id = c.id
            left join reponse rp on rp.user_id = c.id
            left join activation a on a.user_id = c.id
            group by c.semaine
            order by c.semaine
        ");

        if (empty($cohortes)) {
            $this->line('Aucune cohorte sur les 12 dernières semaines.');

            return;
        }

        $this->table(
            ['Semaine', 'Taille', 'Recherche utile', 'Réponse réussie', 'Activation candidate'],
            collect($cohortes)->map(fn ($r) => [
                $r->semaine,
                $r->taille,
                $this->pourcentage((int) $r->recherche_utile, (int) $r->taille),
                $this->pourcentage((int) $r->reponse_reussie, (int) $r->taille),
                $this->pourcentage((int) $r->activation_candidate, (int) $r->taille),
            ])->all(),
        );

        $delai = $connexion->selectOne("
            with premiere_activation as (
                select user_id, min(created_at) as activee_le
                from product_activation_events
                where event_type = 'source_opened_after_answer'
                group by user_id
            )
            select percentile_cont(0.5) within group (
                order by extract(epoch from (pa.activee_le - u.created_at)) / 86400.0
            ) as delai_median_jours
            from users u
            join premiere_activation pa on pa.user_id = u.id
            where u.created_at >= now() - interval '12 weeks'
        ");

        $report = $connexion->selectOne("
            select
                count(*) filter (where oe.status = 'postponed') as reportes,
                count(*) as inscrits
            from onboarding_enrollments oe
            join users u on u.id = oe.user_id
            where u.created_at >= now() - interval '12 weeks'
        ");

        // Cohorte "mature" = fenêtre de retour J+7 déjà entièrement passée
        // pour CE compte (pas un cutoff par semaine) — un compte créé avant-hier
        // n'est jamais compté, ni comme retourné ni comme non-retourné.
        $retour = $connexion->selectOne('
            with mature as (
                select id, created_at
                from users
                where deleted_at is null
                  and created_at >= now() - interval \'12 weeks\'
                  and now() >= created_at + (? * interval \'1 day\')
            )
            select
                count(*) as eligibles,
                count(*) filter (where exists (
                    select 1 from personal_access_tokens t
                    where t.tokenable_id = mature.id
                      and t.last_used_at between mature.created_at + (? * interval \'1 day\') and mature.created_at + (? * interval \'1 day\')
                ) or exists (
                    select 1 from ai_usage_logs a
                    where a.user_id = mature.id
                      and a.created_at between mature.created_at + (? * interval \'1 day\') and mature.created_at + (? * interval \'1 day\')
                ) or exists (
                    select 1 from product_activation_events p
                    where p.user_id = mature.id
                      and p.created_at between mature.created_at + (? * interval \'1 day\') and mature.created_at + (? * interval \'1 day\')
                )) as retournes
            from mature
        ', [$returnEnd, $returnStart, $returnEnd, $returnStart, $returnEnd, $returnStart, $returnEnd]);

        $derniere = collect($cohortes)->last();

        $retourTexte = $retour->eligibles > 0
            ? sprintf('%d%% (sur %d compte(s) mature(s))', round($retour->retournes / $retour->eligibles * 100), $retour->eligibles)
            : 'non mesurable (aucune cohorte mature)';

        $this->line(sprintf(
            'Dernière cohorte (semaine du %s, %d compte(s)) : %s recherche utile, %s réponse réussie, %s activation candidate. Retour J+7 : %s.',
            $derniere->semaine,
            $derniere->taille,
            $this->pourcentage((int) $derniere->recherche_utile, (int) $derniere->taille),
            $this->pourcentage((int) $derniere->reponse_reussie, (int) $derniere->taille),
            $this->pourcentage((int) $derniere->activation_candidate, (int) $derniere->taille),
            $retourTexte,
        ));

        if ($delai !== null && $delai->delai_median_jours !== null) {
            $this->line(sprintf("Délai médian jusqu'à l'activation candidate : %.1f jour(s).", $delai->delai_median_jours));
        }

        if ($report !== null && (int) $report->inscrits > 0) {
            $this->line(sprintf(
                'Report onboarding : %d/%d (%s) des inscriptions de la période.',
                $report->reportes,
                $report->inscrits,
                $this->pourcentage((int) $report->reportes, (int) $report->inscrits),
            ));
        }
    }

    private function pourcentage(int $numerateur, int $denominateur): string
    {
        return $denominateur > 0 ? round($numerateur / $denominateur * 100).'%' : 'non mesurable';
    }
}
