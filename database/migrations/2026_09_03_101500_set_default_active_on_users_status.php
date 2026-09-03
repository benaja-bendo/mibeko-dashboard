<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Filet de sécurité sur `users.status`.
     *
     * Le statut est désormais renseigné explicitement par les deux chemins
     * d'inscription publics ; ce défaut couvre les chemins oubliés (seeder,
     * commande, futur parcours) pour qu'aucun compte ne renaisse à NULL —
     * un compte sans statut est invisible à tout filtre `status = 'active'`.
     *
     * Ne touche aucune ligne existante : le rattrapage des comptes déjà à NULL
     * est une écriture de production distincte (benaja-bendo/mibeko-dashboard#11,
     * classe 2, exécutée par l'humain via `mibeko:corriger-statut-comptes`).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN status SET DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN status DROP DEFAULT');
    }
};
