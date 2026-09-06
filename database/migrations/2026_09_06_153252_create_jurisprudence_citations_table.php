<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jurisprudence CCJA — mibeko-python#19.
     *
     * Une décision de justice est un `legal_documents` FLUX comme un autre
     * (type `JURIS`, ajouté par `SystemRequirementsSeeder` comme les onze
     * autres — jamais par une migration, qui polluerait le compte de types
     * qu'attendent des tests comme `DocumentTypeTest`). Un seul article porte
     * le texte intégral : une décision n'a pas de numérotation d'articles
     * propre, la forcer dans une arborescence Livre/Titre/Chapitre
     * n'apporterait rien. Ce que ce type-là ajoute, c'est le lien structurel
     * qu'aucun autre document ne porte : quel article de quel acte uniforme
     * la décision cite.
     *
     * `cited_article_id` reste nullable à dessein : une décision CCJA cite
     * aussi bien le droit national d'un État membre (Code civil, Code de
     * procédure civile ivoirien…) que des actes uniformes absents du corpus
     * Mibeko — un texte hors périmètre n'est pas une erreur d'extraction,
     * `reference_brute` garde la citation lisible même sans correspondance.
     */
    public function up(): void
    {
        Schema::create('jurisprudence_citations', function (Blueprint $table) {
            // Défaut en base, comme `legal_documents.id`/`articles.id` : le
            // pipeline Python écrit directement dans cette table sans passer
            // par Eloquent, il ne doit pas avoir à générer l'id lui-même.
            $table->uuid('id')->default(DB::raw('uuid_generate_v4()'))->primary();
            $table->foreignUuid('decision_id')->constrained('legal_documents')->cascadeOnDelete();
            $table->foreignUuid('cited_article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->text('reference_brute');
            $table->timestamps();

            // Rejouer l'extraction sur la même décision ne doit jamais
            // dupliquer une citation déjà posée (règle n°3, idempotence).
            $table->unique(['decision_id', 'reference_brute']);
            $table->index('cited_article_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurisprudence_citations');
    }
};
