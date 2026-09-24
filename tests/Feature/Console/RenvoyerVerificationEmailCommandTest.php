<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
 * mibeko-dashboard#185 : renvoi de l'e-mail de vérification aux inscrits de la
 * panne SMTP du 21 au 24/09/2026. Seuls les comptes de la fenêtre, non
 * vérifiés et soumis à la vérification doivent le recevoir.
 */

beforeEach(function () {
    Notification::fake();
    Carbon::setTestNow('2026-09-24 18:00:00');

    $this->fantome = User::factory()->unverified()->create([
        'email' => 'fantome@example.test',
        'email_verification_required' => true,
        'created_at' => '2026-09-22 10:00:00',
    ]);
    $this->connecte = User::factory()->unverified()->create([
        'email_verification_required' => true,
        'created_at' => '2026-09-23 09:00:00',
    ]);
    $this->connecte->createToken('iPhone');

    $this->dejaVerifie = User::factory()->create([
        'email_verification_required' => true,
        'created_at' => '2026-09-22 11:00:00',
    ]);
    $this->avantLaPanne = User::factory()->unverified()->create([
        'email_verification_required' => true,
        'created_at' => '2026-09-20 10:00:00',
    ]);
    $this->nonSoumis = User::factory()->unverified()->create([
        'email_verification_required' => false,
        'created_at' => '2026-09-22 12:00:00',
    ]);
    $this->supprime = User::factory()->unverified()->create([
        'email_verification_required' => true,
        'created_at' => '2026-09-22 13:00:00',
    ]);
    $this->supprime->delete();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('simule par défaut : annonce les comptes visés sans rien envoyer', function () {
    $this->artisan('mibeko:renvoyer-verification-email', ['--depuis' => '2026-09-21T07:53:00Z'])
        ->expectsOutputToContain('Simulation : 2 compte(s)')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('envoie l\'e-mail aux seuls comptes non vérifiés de la fenêtre', function () {
    $this->artisan('mibeko:renvoyer-verification-email', ['--depuis' => '2026-09-21T07:53:00Z', '--execute' => true])
        ->expectsOutputToContain('Envoi demandé pour 2 compte(s), 0 échec(s).')
        ->assertSuccessful();

    Notification::assertSentTo($this->fantome, VerifyEmailNotification::class);
    Notification::assertSentTo($this->connecte, VerifyEmailNotification::class);
    Notification::assertNotSentTo([$this->dejaVerifie, $this->avantLaPanne, $this->nonSoumis, $this->supprime], VerifyEmailNotification::class);
    Notification::assertCount(2);
});

it('borne la fenêtre par --jusqua', function () {
    $this->artisan('mibeko:renvoyer-verification-email', [
        '--depuis' => '2026-09-21T07:53:00Z',
        '--jusqua' => '2026-09-22T23:59:59Z',
        '--execute' => true,
    ])->assertSuccessful();

    Notification::assertSentTo($this->fantome, VerifyEmailNotification::class);
    Notification::assertNotSentTo($this->connecte, VerifyEmailNotification::class);
});

it('refuse de partir sans fenêtre', function () {
    $this->artisan('mibeko:renvoyer-verification-email', ['--execute' => true])
        ->expectsOutputToContain('--depuis est obligatoire')
        ->assertFailed();

    Notification::assertNothingSent();
});

it('masque les adresses dans la sortie', function () {
    $this->artisan('mibeko:renvoyer-verification-email', ['--depuis' => '2026-09-21T07:53:00Z'])
        ->expectsOutputToContain('f***@example.test')
        ->doesntExpectOutputToContain('fantome@example.test')
        ->assertSuccessful();
});
