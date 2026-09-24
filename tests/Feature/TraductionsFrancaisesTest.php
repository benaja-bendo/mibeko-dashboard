<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/*
 * mibeko-dashboard#187 : la prod tourne en `APP_LOCALE=fr` ET
 * `APP_FALLBACK_LOCALE=fr`, sans aucun fichier `lang/` jusqu'au 24/09/2026 —
 * l'API renvoyait `validation.unique` en clé brute et l'e-mail de
 * vérification partait en anglais. On reproduit exactement cette
 * configuration : avec un repli `en`, une traduction manquante passerait
 * inaperçue.
 */

beforeEach(function () {
    config(['app.locale' => 'fr', 'app.fallback_locale' => 'fr']);
    app()->setLocale('fr');
    Role::findOrCreate('mobile_user');
    Role::findOrCreate('admin');
});

it('traduit chaque clé des fichiers de langue du framework', function (string $fichier) {
    $anglais = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$fichier}.php");
    $francais = require lang_path("fr/{$fichier}.php");

    $cles = fn (array $traductions) => array_keys(Arr::dot(Arr::except($traductions, ['custom', 'attributes'])));

    expect(array_diff($cles($anglais), $cles($francais)))->toBe([]);
})->with(['validation', 'auth', 'passwords', 'pagination']);

it('renvoie les erreurs de validation de l\'API en français, jamais en clé brute', function () {
    $this->getJson('/api/v1/legal-documents/search?q[]=x')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Le champ recherche doit être du texte.');
});

it('résume en français un refus qui porte sur plusieurs champs', function () {
    // `ValidationException::summarize()` ajoute « (and :count more errors) »
    // au premier message : resté en anglais dans la prod du 24/09/2026 après
    // la première passe de traduction, relevé en vérifiant le déploiement.
    $this->postJson('/api/v1/register', [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Le champ nom est obligatoire. (et 3 autres erreurs)');

    $this->postJson('/api/v1/register', ['name' => 'Un nom', 'password' => 'motdepasse-solide', 'password_confirmation' => 'motdepasse-solide'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Le champ adresse e-mail est obligatoire. (et 1 autre erreur)');
});

it('oriente vers la connexion quand l\'adresse est déjà inscrite (inscription mobile)', function () {
    User::factory()->create(['email' => 'deja@example.test']);

    $this->postJson('/api/v1/register', [
        'name' => 'Deuxième essai',
        'email' => 'deja@example.test',
        'password' => 'motdepasse-solide',
        'password_confirmation' => 'motdepasse-solide',
        'device_name' => 'Android de test',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.email.0', __('auth.email_deja_inscrit'));

    expect(__('auth.email_deja_inscrit'))->toStartWith('Un compte existe déjà');
});

it('oriente vers la connexion quand l\'adresse est déjà inscrite (inscription web)', function () {
    User::factory()->create(['email' => 'deja@example.test']);

    try {
        app(CreateNewUser::class)->create([
            'name' => 'Deuxième essai',
            'email' => 'deja@example.test',
            'password' => 'Motdepasse-solide-1',
            'password_confirmation' => 'Motdepasse-solide-1',
        ]);
        $this->fail('La validation aurait dû refuser l\'adresse déjà inscrite.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['email'][0])->toBe(__('auth.email_deja_inscrit'));
    }
});

it('rédige l\'e-mail de vérification entièrement en français', function () {
    $compte = User::factory()->unverified()->create();

    $message = (new VerifyEmailNotification)->toMail($compte);
    $html = (string) $message->render();

    expect($message->subject)->toBe('Mibeko — Confirmez votre adresse e-mail')
        ->and($html)->toContain('Bonjour,')
        ->and($html)->toContain('Confirmer mon adresse e-mail')
        ->and($html)->toContain('valable 60 minutes')
        ->and($html)->toContain('Tous droits réservés.')
        ->and($html)->toContain('ne fonctionne pas, copiez l');

    foreach (['Hello!', 'Regards,', 'Verify', 'having trouble', 'All rights reserved'] as $anglais) {
        expect($html)->not->toContain($anglais);
    }
});
