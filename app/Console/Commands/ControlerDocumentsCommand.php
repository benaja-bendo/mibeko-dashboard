<?php

namespace App\Console\Commands;

use App\Models\DocumentControleRun;
use App\Models\LegalDocument;
use App\Services\Curation\JeuDeDetecteurs;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Étage 4, moitié Laravel (mibeko-dashboard#141, § 3.5 du plan « boîte de
 * réception ») : exécute le jeu de détecteurs de contenu v3 sur les
 * documents vivants dont le dernier passage est absent, périmé (version
 * antérieure), ou antérieur à la dernière modification du document.
 */
class ControlerDocumentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mibeko:controler-documents
                            {--limit=100 : Nombre de documents à contrôler}
                            {--version-jeu= : Étiquette de version à écrire (défaut : celle de JeuDeDetecteurs — jamais --version, réservé par Artisan)}
                            {--document= : Ne contrôler qu\'un seul document par id (UUID), ignore --limit}
                            {--dry-run : N\'écrit rien (ni curation_flags, ni document_controle_runs) — affiche seulement le compte rendu}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Jeu de détecteurs de contenu v3 : pose des curation_flags (source=conformite) et journalise le résultat dans document_controle_runs.';

    public function handle(JeuDeDetecteurs $jeu): int
    {
        $version = $this->option('version-jeu') ?: JeuDeDetecteurs::VERSION;
        $dryRun = (bool) $this->option('dry-run');

        if ($documentId = $this->option('document')) {
            $document = LegalDocument::find($documentId);
            if ($document === null) {
                $this->error("Document introuvable : {$documentId}");

                return self::FAILURE;
            }
            $documents = collect([$document]);
        } else {
            $documents = $this->documentsAControler($version, (int) $this->option('limit'));
        }

        if ($documents->isEmpty()) {
            $this->info('✅ Aucun document à contrôler.');

            return self::SUCCESS;
        }

        $prefixe = $dryRun ? '🧪 [dry-run] ' : '🔎 ';
        $this->info("{$prefixe}Contrôle de {$documents->count()} document(s) (jeu {$version})...");
        $bar = $this->output->createProgressBar($documents->count());
        $bar->start();

        $compteurs = [
            DocumentControleRun::RESULTAT_OK => 0,
            DocumentControleRun::RESULTAT_ECHEC => 0,
            DocumentControleRun::RESULTAT_INCOMPLET => 0,
        ];
        foreach ($documents as $document) {
            $run = $jeu->controler($document, $version, $dryRun);
            $compteurs[$run->resultat]++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $total = $documents->count();
        $conformes = $compteurs[DocumentControleRun::RESULTAT_OK];
        $pourcentage = $total > 0 ? round(100 * $conformes / $total, 1) : 0.0;
        $this->info(
            "Terminé : {$compteurs['ok']} ok, {$compteurs['echec']} échec, {$compteurs['incomplet']} incomplet ".
            "({$conformes}/{$total} conformes, {$pourcentage} %)."
        );
        if ($dryRun) {
            $this->warn('Dry-run : rien n\'a été écrit en base.');
        }

        return self::SUCCESS;
    }

    /**
     * Documents vivants dont le dernier run pour `$version` est absent, ou
     * antérieur à la dernière modification du document (§ 3.5 du plan).
     *
     * @return Collection<int, LegalDocument>
     */
    private function documentsAControler(string $version, int $limit)
    {
        return LegalDocument::query()
            ->whereDoesntHave('controleRuns', function ($requete) use ($version) {
                $requete->where('version_jeu', $version)
                    ->whereColumn('date', '>=', 'legal_documents.updated_at');
            })
            // Les plus anciens d'abord (même logique que
            // src/worker/runner.py::reserve_job côté Python) : un document
            // jamais contrôlé n'attend jamais indéfiniment derrière des
            // documents constamment retouchés.
            ->oldest('updated_at')
            ->limit($limit)
            ->get();
    }
}
