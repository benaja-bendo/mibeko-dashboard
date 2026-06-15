<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convertit `audits.auditable_id` de UUID vers VARCHAR.
     *
     * owen-it stocke la clé primaire de l'objet audité dans cette colonne.
     * Tant qu'elle était typée UUID, seuls les modèles à PK UUID pouvaient être
     * audités. Les UUID existants restent des chaînes valides : la conversion
     * est sans perte et permet d'auditer aussi les modèles à clé non-UUID
     * (ex. DocumentType, dont la PK est `code`). L'index idx_audits_auditable
     * est recréé automatiquement par PostgreSQL lors du changement de type.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE audits ALTER COLUMN auditable_id TYPE varchar(255) USING auditable_id::text');
    }

    /**
     * Reconversion en UUID — possible uniquement si toutes les valeurs sont des
     * UUID valides (aucune clé string comme un code DocumentType ne doit subsister).
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE audits ALTER COLUMN auditable_id TYPE uuid USING auditable_id::uuid');
    }
};
