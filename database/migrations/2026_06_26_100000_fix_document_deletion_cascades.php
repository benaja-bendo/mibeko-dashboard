<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Aligne les ON DELETE des dépendances d'un document pour rendre la
     * suppression complète cohérente et non bloquante.
     *
     * Plusieurs clés étrangères enfants étaient en NO ACTION : la purge d'un
     * document échouait par violation de contrainte — surtout `curation_flags`,
     * fréquent justement sur les mauvaises extractions qu'on veut jeter — ou
     * laissait des lignes bloquantes. On distingue :
     *  - enfants « possédés » par le document/article → CASCADE ;
     *  - référence croisée inter-documents (`modifie_par_document_id`) → SET NULL
     *    (on conserve la version, on perd seulement le lien « modifié par »).
     *
     * Corrige aussi le ré-import : `clear_document_structure` (Python) supprime
     * les articles en direct ; sans CASCADE, les relations/flags les référençant
     * faisaient échouer le nettoyage.
     *
     * @var array<int, array{0:string,1:string,2:string,3:string,4:string}>
     *                                                                      [table, contrainte, colonne, table_référencée, on_delete]
     */
    private array $foreignKeys = [
        ['curation_flags', 'curation_flags_document_id_fkey', 'document_id', 'legal_documents', 'CASCADE'],
        ['curation_flags', 'curation_flags_article_id_fkey', 'article_id', 'articles', 'CASCADE'],
        ['document_relations', 'document_relations_source_doc_id_fkey', 'source_doc_id', 'legal_documents', 'CASCADE'],
        ['document_relations', 'document_relations_target_doc_id_fkey', 'target_doc_id', 'legal_documents', 'CASCADE'],
        ['document_relations', 'document_relations_source_article_id_fkey', 'source_article_id', 'articles', 'CASCADE'],
        ['document_relations', 'document_relations_target_article_id_fkey', 'target_article_id', 'articles', 'CASCADE'],
        ['article_versions', 'article_versions_modifie_par_document_id_fkey', 'modifie_par_document_id', 'legal_documents', 'SET NULL'],
    ];

    public function up(): void
    {
        foreach ($this->foreignKeys as [$table, $constraint, $column, $references, $onDelete]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} "
                ."FOREIGN KEY ({$column}) REFERENCES {$references}(id) ON DELETE {$onDelete}"
            );
        }
    }

    public function down(): void
    {
        // Restaure l'état antérieur (NO ACTION : aucune clause ON DELETE).
        foreach ($this->foreignKeys as [$table, $constraint, $column, $references]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} "
                ."FOREIGN KEY ({$column}) REFERENCES {$references}(id)"
            );
        }
    }
};
