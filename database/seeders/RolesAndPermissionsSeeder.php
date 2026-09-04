<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * mibeko-dashboard#64 : `assistant.use`, `export.use` et `library.access`
 * ont été retirées le 04/09/2026 — aucune n'était jamais vérifiée (aucun
 * `permission:`/`can:` dans `routes/api.php`), une permission qui n'est
 * vérifiée nulle part est pire qu'absente, elle donne l'illusion d'un
 * contrôle. Le sort réel de chacune :
 *   - `assistant.use` / `library.access` : n'ont plus lieu d'être — l'assistant
 *     et la bibliothèque sont gratuits pour tout compte authentifié, seul le
 *     quota (`EntitlementsResolver`, #63) les différencie, jamais un rôle.
 *   - `export.use` : le seul export réellement Pro-exclusif au sens de
 *     `EntitlementsResolver::resolvePlan()` (`legal-documents/{id}/export`,
 *     `articles/{id}/export`) reste aujourd'hui public, consommé par des
 *     liens `<a href>` directs sur le web ET par l'app mobile — le verrouiller
 *     casserait ces deux clients sans réécriture coordonnée. Traité par un
 *     ticket séparé plutôt que dans ce mouvement-ci.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Permissions granulaires
        $permissions = [
            // Documents
            'documents.view',
            'documents.create',
            'documents.update',
            'documents.delete',
            'documents.publish',
            // Articles
            'articles.view',
            'articles.create',
            'articles.update',
            'articles.delete',
            // Structure
            'structure.manage',
            // Ingestion (Python pipeline)
            'ingestion.upload',
            'ingestion.parse',
            'ingestion.delete',
            // Admin
            'users.view',
            'users.manage',
            'roles.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Nettoyage des permissions retirées le 04/09/2026 (#64) : sans ça,
        // un environnement déjà semé garde des lignes orphelines — plus
        // rattachées à aucun rôle, mais qui laissent croire qu'un contrôle
        // existe encore quelque part.
        Permission::whereIn('name', ['assistant.use', 'export.use', 'library.access'])->delete();

        // Rôles & affectation des permissions
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($permissions); // tout

        $editor = Role::firstOrCreate(['name' => 'editor']);
        $editor->syncPermissions([
            'documents.view', 'documents.create', 'documents.update', 'documents.publish',
            'articles.view', 'articles.create', 'articles.update', 'articles.delete',
            'structure.manage',
            'ingestion.upload', 'ingestion.parse', 'ingestion.delete',
        ]);

        $userPro = Role::firstOrCreate(['name' => 'user_pro']);
        $userPro->syncPermissions([
            'documents.view',
            'articles.view',
        ]);

        $mobileUser = Role::firstOrCreate(['name' => 'mobile_user']);
        $mobileUser->syncPermissions([
            'documents.view',
            'articles.view',
        ]);
    }
}
