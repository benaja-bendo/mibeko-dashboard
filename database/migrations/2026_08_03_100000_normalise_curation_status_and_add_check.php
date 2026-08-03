<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalise `curation_status` et le verrouille par une contrainte CHECK.
 *
 * La colonne était un `varchar` libre, sans contrainte — contrairement à
 * `statut`, `document_role` et `legal_scope` qui en ont une. Une valeur héritée
 * (`parsed`, écrite par une ancienne version du service Python) a ainsi pu s'y
 * installer. Or `parsed` n'est clé d'aucune des deux tables de transition de
 * `LegalDocument` : `guardCurationStatusTransition` refuse alors TOUTE cible, et
 * l'API n'accepte pas non plus `parsed` en entrée. Un document dans cet état est
 * définitivement figé — en production, le « code du travail » et ses 246
 * articles, invisibles du public sans aucun recours par l'interface.
 *
 * `parsed` correspond sémantiquement à « extraction terminée, curation à faire »,
 * c'est-à-dire `draft` : on le ramène là, puis on ferme la porte.
 */
return new class extends Migration
{
    /**
     * Les quatre seuls états de la machine à états (cf. LegalDocument).
     */
    private const STATUTS = ['draft', 'review', 'validated', 'published'];

    public function up(): void
    {
        DB::statement(
            "UPDATE legal_documents SET curation_status = 'draft' ".
            'WHERE curation_status IS NULL OR curation_status NOT IN ('.$this->liste().')'
        );

        DB::statement(
            'ALTER TABLE legal_documents ADD CONSTRAINT legal_documents_curation_status_check '.
            'CHECK (curation_status IN ('.$this->liste().'))'
        );
    }

    public function down(): void
    {
        // Seule la contrainte est réversible : la valeur `parsed` d'origine n'est
        // pas restaurée (elle était un état sans issue, la remettre re-figerait
        // les documents concernés).
        DB::statement('ALTER TABLE legal_documents DROP CONSTRAINT IF EXISTS legal_documents_curation_status_check');
    }

    private function liste(): string
    {
        return collect(self::STATUTS)->map(fn (string $s) => "'{$s}'")->implode(', ');
    }
};
