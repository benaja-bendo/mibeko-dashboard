<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('mobile_user');
    // `CreateNewUser` promeut le tout premier compte en admin : le rôle doit
    // exister, sinon le test échoue pour une raison étrangère au statut.
    Role::findOrCreate('admin');
});

it('renseigne le statut du compte créé par l\'inscription mobile', function () {
    $this->postJson('/api/v1/register', [
        'name' => 'Nouvelle recrue',
        'email' => 'recrue@example.test',
        'password' => 'motdepasse-solide',
        'password_confirmation' => 'motdepasse-solide',
        'device_name' => 'iPhone de test',
    ])->assertSuccessful();

    $compte = User::where('email', 'recrue@example.test')->sole();

    expect($compte->status)->toBe('active');
});

it('renseigne le statut du compte créé par l\'inscription web', function () {
    $compte = app(CreateNewUser::class)->create([
        'name' => 'Nouvelle recrue web',
        'email' => 'web@example.test',
        'password' => 'motdepasse-solide',
        'password_confirmation' => 'motdepasse-solide',
    ]);

    expect($compte->status)->toBe('active');
});

it('pose « active » par défaut quand un chemin de création oublie le statut', function () {
    // Filet de la migration : même un chemin qui n'écrit pas `status` ne peut
    // plus fabriquer un compte invisible aux filtres `status = 'active'`.
    $compte = User::create([
        'name' => 'Compte sans statut',
        'email' => 'oubli@example.test',
        'password' => 'motdepasse-solide',
    ]);

    expect($compte->fresh()->status)->toBe('active');
});
