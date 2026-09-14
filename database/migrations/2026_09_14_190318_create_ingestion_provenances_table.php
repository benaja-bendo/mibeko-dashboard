<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance structurée d'un fichier acquis (dépôt web ou veille) — remplace
 * le rôle de source de vérité que jouait jusqu'ici `data/manifests/*.jsonl`
 * côté Python (mibeko-python#22, § 3.7 du plan « boîte de réception »,
 * docs/pipeline/plan-boite-de-reception-2026-09.md).
 *
 * `Manifest.save()` (mibeko-python) charge tout le fichier JSONL en mémoire
 * et le réécrit en entier, sans verrou : deux écritures concurrentes (dépôt
 * web, veille, worker) perdent silencieusement l'une des deux. PostgreSQL
 * porte désormais cet état ; le JSONL redevient un export périodique, lisible
 * par les commandes CLI qui le consomment encore, jamais écrit en
 * lecture-modification-écriture concurrente.
 *
 * `manifest_id` reprend le format existant du manifeste Python
 * ("sgg-jo/congo-jo-2026-13") : une clé métier stable, pas une nouvelle
 * numérotation. `evenements` reprend la forme de `ManifestEntry.evenements`
 * (quand/quoi/par/detail) pour que l'export JSONL soit un miroir fidèle,
 * pas une réinterprétation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_provenances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('manifest_id')->unique();
            $table->string('type_source', 40);
            $table->string('source_url')->nullable();
            // SHA-256 complet (64 caractères hex) : dédoublonnage d'un même
            // fichier déposé sous deux titres (mibeko-python § 2.2, écart
            // diagnostiqué le 03/08/2026 — upload_document testait déjà
            // document_key mais jamais ce hash).
            $table->char('sha256', 64);
            $table->timestamp('fetched_at')->nullable();
            $table->jsonb('evenements')->default('[]');
            $table->timestamps();

            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_provenances');
    }
};
