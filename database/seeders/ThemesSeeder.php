<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

/**
 * Taxonomie canonique des « thèmes de vie » — socle éditorial permettant de
 * naviguer le droit congolais par situation concrète plutôt que par référence
 * juridique. Idempotent (updateOrCreate par slug) : peut être rejoué pour
 * mettre à jour libellés / icônes / ordre sans dupliquer.
 *
 * Les icônes sont des noms d'icônes lucide (rendues côté front).
 */
class ThemesSeeder extends Seeder
{
    /**
     * @var array<int, array{slug: string, name: string, icon: string, description: string}>
     */
    private const THEMES = [
        ['slug' => 'famille', 'name' => 'Famille & personnes', 'icon' => 'users', 'description' => 'Mariage, divorce, filiation, succession, état civil.'],
        ['slug' => 'travail', 'name' => 'Travail & emploi', 'icon' => 'briefcase', 'description' => 'Contrat, licenciement, salaire, conflits du travail.'],
        ['slug' => 'logement', 'name' => 'Logement & foncier', 'icon' => 'home', 'description' => 'Bail, propriété, expropriation, voisinage.'],
        ['slug' => 'justice', 'name' => 'Justice & droits', 'icon' => 'gavel', 'description' => 'Procédure, recours, droits fondamentaux.'],
        ['slug' => 'penal', 'name' => 'Pénal & sécurité', 'icon' => 'shield', 'description' => 'Infractions, peines, plaintes, garde à vue.'],
        ['slug' => 'entreprise', 'name' => 'Entreprise & OHADA', 'icon' => 'building-2', 'description' => 'Sociétés, commerce, contrats d\'affaires.'],
        ['slug' => 'fiscalite', 'name' => 'Fiscalité & impôts', 'icon' => 'receipt', 'description' => 'Impôts, taxes, déclarations, douanes.'],
        ['slug' => 'social', 'name' => 'Protection sociale & santé', 'icon' => 'heart-pulse', 'description' => 'Sécurité sociale, santé, retraite.'],
        ['slug' => 'administratif', 'name' => 'Administration & services publics', 'icon' => 'landmark', 'description' => 'Démarches, fonction publique, marchés publics.'],
        ['slug' => 'environnement', 'name' => 'Environnement & ressources', 'icon' => 'leaf', 'description' => 'Mines, eau, forêts, hydrocarbures, environnement.'],
    ];

    public function run(): void
    {
        foreach (self::THEMES as $index => $theme) {
            Tag::updateOrCreate(
                ['slug' => $theme['slug']],
                [
                    'name' => $theme['name'],
                    'icon' => $theme['icon'],
                    'description' => $theme['description'],
                    'display_order' => $index + 1,
                ],
            );
        }
    }
}
