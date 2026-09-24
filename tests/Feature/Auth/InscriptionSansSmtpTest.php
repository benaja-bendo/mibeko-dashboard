<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
 * mibeko-dashboard#185 : du 21 au 24/09/2026, le SMTP refusait tout envoi
 * (« 550 5.7.1 Sender mismatch »). L'e-mail de vérification partait en
 * synchrone pendant l'inscription : 500 après la création du compte, avant
 * celle du jeton — 12 comptes fantômes sur 16 inscriptions. Ces tests posent
 * un transport qui échoue toujours et une file `database` (celle de la prod) :
 * l'inscription doit aboutir, et l'e-mail attendre dans la file.
 */

beforeEach(function () {
    Role::findOrCreate('mobile_user');

    Mail::extend('en-panne', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Expected response code "250" but got code "550", with message "550 5.7.1 Sender mismatch".');
        }

        public function __toString(): string
        {
            return 'en-panne://';
        }
    });

    config([
        'mail.mailers.en-panne' => ['transport' => 'en-panne'],
        'mail.default' => 'en-panne',
        'queue.default' => 'database',
    ]);
});

it('crée le compte et rend un jeton même quand le SMTP refuse tout envoi', function () {
    $this->postJson('/api/v1/register', [
        'name' => 'Inscrite pendant la panne',
        'email' => 'panne@example.test',
        'password' => 'motdepasse-solide',
        'password_confirmation' => 'motdepasse-solide',
        'device_name' => 'Android de test',
    ])->assertSuccessful()
        ->assertJsonStructure(['data' => ['token', 'user']]);

    $compte = User::where('email', 'panne@example.test')->sole();

    expect($compte->tokens()->count())->toBe(1)
        ->and($compte->hasVerifiedEmail())->toBeFalse();

    // L'e-mail n'est pas perdu : il attend dans la file, et un
    // `queue:retry` le renverra une fois le SMTP rétabli.
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('payload'))->toContain('VerifyEmailNotification');
});

it('accepte la demande de renvoi même quand le SMTP refuse tout envoi', function () {
    $compte = User::factory()->unverified()->create();

    $this->withToken($compte->createToken('test')->plainTextToken)
        ->postJson('/api/v1/email/verification-notification')
        ->assertStatus(202);

    expect(DB::table('jobs')->count())->toBe(1);
});

it('met l\'e-mail de vérification en file d\'attente', function () {
    expect(new VerifyEmailNotification)->toBeInstanceOf(ShouldQueue::class);
});
