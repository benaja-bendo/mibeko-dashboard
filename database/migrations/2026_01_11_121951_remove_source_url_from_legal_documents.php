<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Migrate existing source_url to media_files
        DB::statement("
            INSERT INTO media_files (id, document_id, file_path, mime_type, created_at, updated_at)
            SELECT 
                gen_random_uuid(), 
                id, 
                source_url, 
                'application/pdf', 
                NOW(), 
                NOW()
            FROM legal_documents
            WHERE source_url IS NOT NULL
        ");

        // 2. Drop source_url column
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->text('source_url')->nullable();
        });

        // Try to restore source_url from media_files (taking the first one)
        DB::statement("
            UPDATE legal_documents ld
            SET source_url = (
                SELECT file_path 
                FROM media_files mf 
                WHERE mf.document_id = ld.id 
                ORDER BY created_at ASC 
                LIMIT 1
            )
        ");
    }
};
