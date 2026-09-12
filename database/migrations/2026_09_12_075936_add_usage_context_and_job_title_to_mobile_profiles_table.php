<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute deux colonnes au profil étendu — mibeko-dashboard#135.
 *
 * `usage_context` reprend l'idée de catégorie déjà portée par `profession`
 * (#98 : Citoyen/Étudiant/Professionnel du droit/Autre), mais avec des CODES
 * STABLES non traduits (`personal|studies|professional|other`) au lieu de
 * libellés français stockés en base. #98 avait gardé les libellés faute
 * d'enjeu de synchronisation ; ici le catalogue doit être partagé à l'identique
 * par le web et le mobile, donc les codes redeviennent la bonne base.
 * `profession` n'est pas renommée ni retirée : les anciennes apps mobiles déjà
 * distribuées continuent de l'écrire/la lire sans changement.
 *
 * `job_title` est un métier facultatif en texte libre, volontairement
 * DISTINCT de `profession` : `profession` reste une liste fermée à 4 valeurs
 * (contrainte CHECK #98) qui rejetterait tout texte libre. Aucun mapping
 * `profession → job_title` n'existe ni n'existera : « Professionnel du droit »
 * ne prouve pas « Avocat », deviner le métier depuis le cadre d'usage
 * inventerait une réponse que l'utilisateur n'a jamais donnée.
 *
 * Additive uniquement, aucun backfill de masse : une ligne existante garde
 * `usage_context IS NULL` tant que le compte ne resoumet rien — même doctrine
 * que la migration de normalisation du 06/09/2026 (`NULL` reste `NULL`).
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const USAGE_CONTEXTS = ['personal', 'studies', 'professional', 'other'];

    public function up(): void
    {
        Schema::table('mobile_profiles', function (Blueprint $table) {
            $table->string('usage_context')->nullable()->after('profession');
            $table->string('job_title', 255)->nullable()->after('usage_context');
        });

        DB::statement(
            'ALTER TABLE mobile_profiles ADD CONSTRAINT mobile_profiles_usage_context_check '.
            "CHECK (usage_context IS NULL OR usage_context IN ({$this->codes()}))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mobile_profiles DROP CONSTRAINT IF EXISTS mobile_profiles_usage_context_check');

        Schema::table('mobile_profiles', function (Blueprint $table) {
            $table->dropColumn(['usage_context', 'job_title']);
        });
    }

    private function codes(): string
    {
        return collect(self::USAGE_CONTEXTS)->map(fn (string $v) => "'".addslashes($v)."'")->implode(', ');
    }
};
