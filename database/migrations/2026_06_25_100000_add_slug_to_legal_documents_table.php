<?php

use App\Models\LegalDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute un slug stable et lisible aux documents juridiques.
     *
     * Ce slug est la clé d'URL publique du site (`/codes/{slug}`) : il doit
     * rester stable même si le titre change, d'où une colonne dédiée plutôt
     * qu'une génération à la volée. Les lignes existantes sont rétro-remplies
     * à partir du titre officiel, avec déduplication.
     */
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('titre_officiel');
        });

        // Rétro-remplissage : chaque slug est rendu unique en interrogeant la
        // base, et les lignes déjà traitées sont visibles des suivantes
        // (saveQuietly écrit avant l'itération suivante). On laisse chunkById
        // ordonner par clé primaire (ne PAS ajouter d'orderBy : le curseur par
        // id sauterait des lignes au-delà du premier lot).
        LegalDocument::withTrashed()
            ->whereNull('slug')
            ->chunkById(200, function ($documents) {
                foreach ($documents as $document) {
                    $document->slug = LegalDocument::generateUniqueSlug(
                        $document->titre_officiel ?: $document->id,
                        $document->id,
                    );
                    $document->saveQuietly();
                }
            });

        Schema::table('legal_documents', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
