<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Retire un document publié par l'API, après mesure sur une connexion explicite. */
class DepublierDocumentCommand extends Command
{
    protected $signature = 'mibeko:depublier-document
        {id : Identifiant du document à retirer}
        {--connection=pgsql_prod_ro : Connexion de lecture pour les contrôles avant et après}
        {--expected-articles= : Nombre exact d\'articles vivants attendu}
        {--motif= : Motif éditorial transmis à l\'API et conservé dans les logs}
        {--base-url= : URL de base de l\'API, obligatoire à l\'exécution}
        {--execute : Appelle le PATCH unitaire. Sans cette option, lecture seule}';

    protected $description = 'Dépublie un document précis via l\'API, avec mesure et simulation par défaut.';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $connection = (string) $this->option('connection');
        $expected = filter_var($this->option('expected-articles'), FILTER_VALIDATE_INT);
        $motif = trim((string) $this->option('motif'));
        $execute = (bool) $this->option('execute');
        $baseUrl = rtrim((string) $this->option('base-url'), '/');

        if (! Str::isUuid($id) || $expected === false || $expected < 1 || $motif === '') {
            $this->error('UUID, --expected-articles positif et --motif sont obligatoires.');

            return self::FAILURE;
        }

        if ($execute && ($baseUrl === '' || ! str_starts_with($baseUrl, 'https://'))) {
            $this->error('À l\'exécution, --base-url doit être une URL HTTPS explicite.');

            return self::FAILURE;
        }

        $db = DB::connection($connection);
        $document = $db->table('legal_documents')->where('id', $id)->whereNull('deleted_at')
            ->first(['id', 'titre_officiel', 'slug', 'curation_status']);

        if ($document === null || $document->curation_status !== 'published') {
            $this->error('Le document est absent ou n\'est plus publié. Aucune action.');

            return self::FAILURE;
        }

        $articles = $db->table('articles')->where('document_id', $id)->whereNull('deleted_at')->count();

        if ($articles !== $expected) {
            $this->error("Articles vivants : {$articles}, attendu : {$expected}. Aucune action.");

            return self::FAILURE;
        }

        $this->line("Document : {$document->titre_officiel} ({$id})");
        $this->line("Slug : {$document->slug}");
        $this->line("Articles vivants : {$articles}");
        $this->line('Transition prévue : published → review, sans suppression des articles.');
        $this->line("Motif : {$motif}");

        if (! $execute) {
            $this->warn('Simulation : aucune écriture effectuée.');

            return self::SUCCESS;
        }

        $token = (string) env('MIBEKO_API_TOKEN', '');

        if ($token === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. Aucune action.');

            return self::FAILURE;
        }

        $response = Http::withToken($token)->acceptJson()->timeout(30)
            ->patch("{$baseUrl}/legal-documents/{$id}", [
                'curation_status' => 'review',
                'motif' => $motif,
            ]);

        if ($response->failed()) {
            $this->error("Le PATCH a échoué (HTTP {$response->status()}). Vérifier la production en lecture seule.");

            return self::FAILURE;
        }

        $apres = $db->table('legal_documents')->where('id', $id)->whereNull('deleted_at')->value('curation_status');

        if ($apres !== 'review') {
            $this->error('Le contrôle après PATCH ne retrouve pas le statut review. Traiter comme incident.');

            return self::FAILURE;
        }

        $this->info('Dépublication confirmée : published → review.');

        return self::SUCCESS;
    }
}
