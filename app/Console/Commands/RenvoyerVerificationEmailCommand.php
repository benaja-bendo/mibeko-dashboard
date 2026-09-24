<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Renvoie l'e-mail de vérification aux comptes non vérifiés créés dans une
 * fenêtre de temps donnée.
 *
 * Né de mibeko-dashboard#185 : du 21 au 24/09/2026, le SMTP refusait tout
 * envoi. 16 personnes se sont inscrites, aucune n'a reçu l'e-mail de
 * vérification, et 12 ont vu leur inscription finir en erreur alors que leur
 * compte existait. Ce renvoi leur apprend que le compte existe et leur permet
 * de le vérifier.
 *
 * La fenêtre (`--depuis`) est obligatoire : sans elle, la commande écrirait à
 * tous les comptes jamais vérifiés depuis l'origine, ce qu'aucune décision ne
 * couvre. Simulation par défaut ; l'envoi réel exige `--execute`. Relancer la
 * commande renvoie l'e-mail : elle n'est pas idempotente, d'où l'annonce du
 * nombre exact avant tout envoi.
 *
 * En production (classe 2, terminal de l'humain) :
 *
 *   cd /opt/docker/mibeko-app
 *   docker compose exec -T app php artisan mibeko:renvoyer-verification-email --depuis=2026-09-21T07:53:00Z
 *   docker compose exec -T app php artisan mibeko:renvoyer-verification-email --depuis=2026-09-21T07:53:00Z --execute
 */
class RenvoyerVerificationEmailCommand extends Command
{
    protected $signature = 'mibeko:renvoyer-verification-email
        {--depuis= : Début de la fenêtre d\'inscription (ISO 8601, obligatoire)}
        {--jusqua= : Fin de la fenêtre (ISO 8601, défaut : maintenant)}
        {--execute : Envoyer réellement ; sans cette option, simple simulation}';

    protected $description = 'Renvoie l\'e-mail de vérification aux comptes non vérifiés inscrits dans une fenêtre donnée (simulation par défaut).';

    public function handle(): int
    {
        if (! $this->option('depuis')) {
            $this->error('--depuis est obligatoire : la commande ne vise que les comptes d\'une fenêtre d\'incident.');

            return self::FAILURE;
        }

        try {
            $depuis = Carbon::parse((string) $this->option('depuis'));
            $jusqua = $this->option('jusqua') ? Carbon::parse((string) $this->option('jusqua')) : now();
        } catch (Throwable) {
            $this->error('Date illisible : utiliser le format ISO 8601, par exemple 2026-09-21T07:53:00Z.');

            return self::FAILURE;
        }

        $comptes = User::query()
            ->whereNull('email_verified_at')
            ->where('email_verification_required', true)
            ->whereNull('suspended_at')
            ->whereBetween('created_at', [$depuis, $jusqua])
            ->withCount('tokens')
            ->orderBy('created_at')
            ->get();

        $this->line(sprintf('Fenêtre : %s → %s', $depuis->toIso8601String(), $jusqua->toIso8601String()));

        foreach ($comptes as $compte) {
            $this->line(sprintf(
                '  %s  %s  %s  %s',
                $compte->id,
                $compte->created_at->toIso8601String(),
                $this->masquer($compte->email),
                $compte->tokens_count > 0 ? 'session ouverte depuis' : 'jamais connecté',
            ));
        }

        if (! $this->option('execute')) {
            $this->info(sprintf('Simulation : %d compte(s) recevraient l\'e-mail de vérification. Relancer avec --execute pour envoyer.', $comptes->count()));

            return self::SUCCESS;
        }

        $envoyes = 0;
        $echecs = 0;

        foreach ($comptes as $compte) {
            try {
                $compte->sendEmailVerificationNotification();
                $envoyes++;
            } catch (Throwable $exception) {
                $echecs++;
                $this->error(sprintf('Échec pour %s : %s', $compte->id, $exception->getMessage()));
            }
        }

        $this->info(sprintf('Envoi demandé pour %d compte(s), %d échec(s).', $envoyes, $echecs));

        return $echecs === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Masque l'adresse dans la sortie : le terminal et son historique ne
     * doivent pas recopier les e-mails des usagers en clair.
     */
    private function masquer(string $email): string
    {
        [$local, $domaine] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domaine;
    }
}
