<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * dashboard#123 : `document_relations` était strictement plate — une ligne
 * insérée valait confirmation immédiate, sans distinguer une relation saisie
 * à la main (`CreerRelationsDocumentsCommand`, remédiation #31) d'une relation
 * détectée par heuristique et pas encore relue. Ces colonnes, ADDITIVES,
 * portent cette distinction sans rien casser des lignes existantes :
 * `status` par défaut `confirmed` reclasse rétroactivement toutes les
 * relations déjà en base comme déjà validées (ce qu'elles sont, ayant été
 * saisies par un humain), et `source` par défaut `human` fait de même.
 *
 * Toujours pas de contrainte UNIQUE sur (source, target, type) : la table
 * n'en a jamais eu (cf. commentaire de `CreerRelationsDocumentsCommand`,
 * qui compose déjà avec son absence) — l'idempotence du détecteur heuristique
 * se fait au niveau applicatif, pas en base.
 */
return new class extends Migration
{
    private const STATUSES = ['candidate', 'confirmed', 'rejected'];

    private const SOURCES = ['heuristic', 'human'];

    public function up(): void
    {
        Schema::table('document_relations', function (Blueprint $table) {
            $table->string('status', 20)->default('confirmed')->after('relation_type');
            $table->string('source', 20)->default('human')->after('status');
            $table->foreignUuid('created_by')->nullable()->after('meta')
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });

        DB::statement(
            'ALTER TABLE document_relations ADD CONSTRAINT document_relations_status_check '.
            'CHECK (status IN ('.$this->quotedList(self::STATUSES).'))'
        );

        DB::statement(
            'ALTER TABLE document_relations ADD CONSTRAINT document_relations_source_check '.
            'CHECK (source IN ('.$this->quotedList(self::SOURCES).'))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE document_relations DROP CONSTRAINT IF EXISTS document_relations_source_check');
        DB::statement('ALTER TABLE document_relations DROP CONSTRAINT IF EXISTS document_relations_status_check');

        Schema::table('document_relations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['status', 'source', 'reviewed_at']);
        });
    }

    private function quotedList(array $values): string
    {
        return collect($values)->map(fn (string $value) => "'{$value}'")->implode(', ');
    }
};
