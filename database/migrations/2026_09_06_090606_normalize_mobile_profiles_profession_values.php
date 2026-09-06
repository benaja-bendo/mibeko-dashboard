<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ferme la liste des valeurs de `mobile_profiles.profession` — mibeko-dashboard#98.
 *
 * Mesuré le 05/09/2026 (lecture seule prod) : le champ est un texte libre
 * saisi via deux chemins mobiles différents — le sélecteur Citoyen/Professionnel
 * de l'onboarding (`ProfileSetupScreen`, deux valeurs fixes) et le champ libre
 * des Réglages (`SettingsScreen`, sans contrainte). Résultat observé : cinq
 * orthographes d'« étudiant », « Professionnel du droit » et « Juriste »
 * (même intention, deux mots), et des réponses hors catégorie (informaticien,
 * opérateur, poète). Impossible à agréger sans ce nettoyage — c'était
 * justement le but de ce champ : mesurer qui utilise réellement Mibeko.
 *
 * Un compte sans réponse (`NULL`) le RESTE : lui attribuer « Autre » de force
 * affirmerait une réponse qu'il n'a jamais donnée — même principe que le
 * refus de backfiller `email_verified_at` (16/08/2026, `docs/decisions.md`).
 */
return new class extends Migration
{
    /**
     * Liste fermée. Les valeurs restent les libellés déjà écrits par le
     * mobile (« Citoyen », « Professionnel du droit ») plutôt que des codes
     * machine inédits : aucun code ne dépend aujourd'hui d'une valeur
     * particulière, changer la casse n'aurait fait que déplacer le risque
     * de désaccord entre client et serveur.
     */
    private const VALEURS = ['Citoyen', 'Étudiant', 'Professionnel du droit', 'Autre'];

    public function up(): void
    {
        // Cinq orthographes d'« étudiant » vues en production, sans prétendre
        // à l'exhaustivité future : la contrainte ci-dessous est le vrai
        // garde-fou, ce mapping ne couvre que le passif connu.
        DB::table('mobile_profiles')
            ->whereIn('profession', ['étudiant', 'Étudiant', 'étudiante', 'Étudiants', 'étudiants'])
            ->update(['profession' => 'Étudiant']);

        DB::table('mobile_profiles')
            ->whereIn('profession', ['Professionnel du droit', 'Juriste', 'juriste', 'avocat', 'Avocat'])
            ->update(['profession' => 'Professionnel du droit']);

        // Tout le reste de non vide/non nul et non déjà canonique (informaticien,
        // opérateur, poète…) devient « Autre » — une vraie réponse, hors catégorie.
        DB::table('mobile_profiles')
            ->whereNotNull('profession')
            ->where('profession', '!=', '')
            ->whereNotIn('profession', self::VALEURS)
            ->update(['profession' => 'Autre']);

        DB::statement(
            'ALTER TABLE mobile_profiles ADD CONSTRAINT mobile_profiles_profession_check '.
            "CHECK (profession IS NULL OR profession IN ({$this->valeurs()}))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mobile_profiles DROP CONSTRAINT IF EXISTS mobile_profiles_profession_check');
    }

    private function valeurs(): string
    {
        return collect(self::VALEURS)->map(fn (string $v) => "'".addslashes($v)."'")->implode(', ');
    }
};
