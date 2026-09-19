<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filet d'alias de slug (mibeko-dashboard#155) : rend toute URL publique de
 * texte (`/textes/{slug}`) réversible.
 *
 * Jusqu'ici `showBySlug()` résolvait par `where('slug')->firstOrFail()` sans
 * aucun repli, alors que `mibeko:corriger-slugs` remplace déjà des slugs en
 * base : chaque correction transformait une URL vivante en 404 définitif.
 * Conséquence mesurée le 18/09/2026 : 313 slugs tronqués sur 1 087 publiés,
 * qu'on ne pouvait pas corriger sans casser les liens entrants.
 *
 * Une ligne = un ancien slug qui pointe vers son document. Les alias visent
 * le DOCUMENT, jamais le slug suivant : une chaîne A → B → C se résout donc
 * en un seul saut, sans parcours. Append-only : un alias ne se modifie pas,
 * il se supprime seulement quand le document reprend ce slug comme canonique.
 *
 * Invariant croisé — un slug n'est jamais à la fois canonique d'un document
 * et alias d'un autre. Postgres ne sait pas l'exprimer par une contrainte
 * (elle traverserait deux tables) : il est tenu à l'écriture, dans
 * `LegalDocument` (hooks `saving`/`saved`, `generateUniqueSlug`),
 * `DocumentSlugAlias` et `mibeko:corriger-slugs`. Aucun autre chemin n'écrit
 * de slug de document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_slug_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Un ancien slug ne renvoie qu'à un seul document.
            $table->string('slug')->unique();
            $table->foreignUuid('legal_document_id')->constrained('legal_documents')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_slug_aliases');
    }
};
