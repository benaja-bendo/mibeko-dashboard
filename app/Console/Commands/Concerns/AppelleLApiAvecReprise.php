<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Appels de l'API Laravel depuis une commande qui écrit en production, avec le
 * jeton exporté à la main dans le shell (docs/infra/production.md § 6).
 *
 * Reprend sur les refus temporaires (429 du quota de 60 requêtes par minute,
 * 5xx, coupure réseau) ; les refus définitifs (403, 404, 422 d'un garde-fou)
 * sont rendus tels quels : ils ne se réessaient pas, ils se corrigent.
 */
trait AppelleLApiAvecReprise
{
    /** Nombre de reprises après un refus temporaire. */
    private const TENTATIVES_MAX = 4;

    /** Plafond du backoff, pour qu'un `Retry-After` farfelu ne fige pas l'opération. */
    private const ATTENTE_MAX_SECONDES = 60;

    /**
     * @param  array<string, mixed>  $charge
     * @return Response|null null quand le réseau n'a jamais répondu
     */
    private function appelerApi(string $jeton, string $methode, string $url, array $charge = []): ?Response
    {
        for ($tentative = 1; ; $tentative++) {
            try {
                $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)->send($methode, $url, $charge === [] ? [] : ['json' => $charge]);
            } catch (ConnectionException $e) {
                if ($tentative > self::TENTATIVES_MAX) {
                    $this->line('  <fg=red>réseau injoignable</> : '.Str::limit($e->getMessage(), 60));

                    return null;
                }

                $this->attendre($this->backoff($tentative), 'réseau injoignable', $tentative);

                continue;
            }

            if (! $this->estTemporaire($reponse) || $tentative > self::TENTATIVES_MAX) {
                return $reponse;
            }

            $this->attendre(
                $this->delaiDemande($reponse) ?? $this->backoff($tentative),
                (string) $reponse->status(),
                $tentative,
            );
        }
    }

    private function estTemporaire(Response $reponse): bool
    {
        return $reponse->status() === 429 || $reponse->serverError();
    }

    /** Le serveur sait mieux que nous quand revenir : `Retry-After` prime sur le backoff. */
    private function delaiDemande(Response $reponse): ?int
    {
        $entete = $reponse->header('Retry-After');

        return is_numeric($entete) ? min((int) $entete, self::ATTENTE_MAX_SECONDES) : null;
    }

    private function backoff(int $tentative): int
    {
        return (int) min(2 ** $tentative, self::ATTENTE_MAX_SECONDES);
    }

    private function attendre(int $secondes, string $motif, int $tentative): void
    {
        $this->line(sprintf(
            '  <fg=yellow>⟳</> %s : nouvelle tentative dans %d s (%d/%d)',
            $motif, $secondes, $tentative, self::TENTATIVES_MAX,
        ));

        Sleep::for($secondes)->seconds();
    }

    /**
     * Tient la cadence en déduisant le temps déjà passé sur l'appel : sans
     * cette compensation, l'opération avancerait bien plus lentement
     * qu'annoncé.
     */
    private function tenirLaCadence(float $intervalle, float $debut): void
    {
        if ($intervalle <= 0) {
            return;
        }

        $reste = $intervalle - (microtime(true) - $debut);

        if ($reste > 0) {
            Sleep::for((int) round($reste * 1000))->milliseconds();
        }
    }

    private function motifDeRefus(?Response $reponse): string
    {
        if ($reponse === null) {
            return 'réseau injoignable après '.self::TENTATIVES_MAX.' reprises';
        }

        $corps = $reponse->json();
        $erreurs = data_get($corps, 'errors', []);
        $premiere = is_array($erreurs) ? (data_get($erreurs, '*.0')[0] ?? null) : null;

        return Str::limit((string) ($premiere ?: data_get($corps, 'message') ?: $reponse->status()), 60);
    }
}
