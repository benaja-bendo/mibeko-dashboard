<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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
            // Pro features
            'assistant.use',
            'export.use',
            'library.access',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Rôles & affectation des permissions
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($permissions); // tout

        $editor = Role::firstOrCreate(['name' => 'editor']);
        $editor->syncPermissions([
            'documents.view', 'documents.create', 'documents.update', 'documents.publish',
            'articles.view', 'articles.create', 'articles.update', 'articles.delete',
            'structure.manage',
            'ingestion.upload', 'ingestion.parse', 'ingestion.delete',
            'assistant.use', 'export.use', 'library.access',
        ]);

        $userPro = Role::firstOrCreate(['name' => 'user_pro']);
        $userPro->syncPermissions([
            'documents.view',
            'articles.view',
            'assistant.use',
            'export.use',
            'library.access',
        ]);

        $mobileUser = Role::firstOrCreate(['name' => 'mobile_user']);
        $mobileUser->syncPermissions([
            'documents.view',
            'articles.view',
        ]);
    }
}
