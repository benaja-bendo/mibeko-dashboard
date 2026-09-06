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
}
