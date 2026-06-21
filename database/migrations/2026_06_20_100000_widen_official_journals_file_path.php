<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Élargit `official_journals.file_path` de VARCHAR(255) à VARCHAR(512).
     *
     * Le chemin S3 du PDF d'un Journal Officiel est dérivé du nom de fichier,
     * lui-même souvent issu du titre (long) de l'acte — il dépassait 255 et
     * faisait échouer l'insertion (StringDataRightTruncation). On l'aligne sur
     * `media_files.file_path`, déjà en VARCHAR(512). Élargissement sans perte.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE official_journals ALTER COLUMN file_path TYPE varchar(512)');
    }

    /**
     * Retour à VARCHAR(255). Échoue si des chemins de plus de 255 caractères
     * existent déjà (rollback à effectuer avant qu'un tel chemin ne soit stocké).
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE official_journals ALTER COLUMN file_path TYPE varchar(255)');
    }
};
