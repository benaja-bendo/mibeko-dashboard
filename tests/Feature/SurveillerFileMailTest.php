<?php

use App\Notifications\FileMailBloqueeNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\UserInvitationNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function insererJobEchoue(string $classe, ?string $failedAt = null): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $classe, 'data' => ['commandName' => $classe]]),
        'exception' => 'Swift_TransportException: Connection refused',
        'failed_at' => $failedAt ?? now(),
    ]);
}

function insererJobEnAttente(string $classe, int $ilYA): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $classe, 'data' => ['commandName' => $classe]]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinutes($ilYA)->timestamp,
        'created_at' => now()->subMinutes($ilYA)->timestamp,
    ]);
}

it('ne fait rien quand la file est saine', function () {
    Notification::fake();

    $this->artisan('mibeko:surveiller-file-mail')->assertSuccessful();

    Notification::assertNothingSent();
});

it('alerte sur un échec de réinitialisation de mot de passe', function () {
    Notification::fake();
    insererJobEchoue(PasswordResetCodeNotification::class);

    $this->artisan('mibeko:surveiller-file-mail')->assertFailed();

    Notification::assertSentOnDemand(
        FileMailBloqueeNotification::class,
        fn ($notification) => $notification->echecs !== [] && $notification->echecs[0]['classe'] === 'PasswordResetCodeNotification',
    );
});

it('alerte sur une invitation bloquée au-delà du seuil', function () {
    Notification::fake();
    insererJobEnAttente(UserInvitationNotification::class, 15);

    $this->artisan('mibeko:surveiller-file-mail')->assertFailed();

    Notification::assertSentOnDemand(
        FileMailBloqueeNotification::class,
        fn ($notification) => $notification->bloques !== [] && $notification->bloques[0]['classe'] === 'UserInvitationNotification',
    );
});

it('ignore une invitation en attente depuis moins de dix minutes', function () {
    Notification::fake();
    insererJobEnAttente(UserInvitationNotification::class, 3);

    $this->artisan('mibeko:surveiller-file-mail')->assertSuccessful();

    Notification::assertNothingSent();
});

it('ignore les échecs de jobs sans rapport avec l\'accès au compte', function () {
    Notification::fake();
    insererJobEchoue('App\\Jobs\\EmbedArticleChunkJob');

    $this->artisan('mibeko:surveiller-file-mail')->assertSuccessful();

    Notification::assertNothingSent();
});

it('ignore un échec plus ancien que la fenêtre de surveillance', function () {
    Notification::fake();
    insererJobEchoue(PasswordResetCodeNotification::class, now()->subHours(25)->toDateTimeString());

    $this->artisan('mibeko:surveiller-file-mail')->assertSuccessful();

    Notification::assertNothingSent();
});

it('ne signale un même échec qu\'une seule fois', function () {
    Notification::fake();
    insererJobEchoue(PasswordResetCodeNotification::class);

    $this->artisan('mibeko:surveiller-file-mail')->assertFailed();
    $this->artisan('mibeko:surveiller-file-mail')->assertSuccessful();

    Notification::assertSentOnDemandTimes(FileMailBloqueeNotification::class, 1);
});

it('signale un nouvel échec arrivé après un premier signalement, et lui seul', function () {
    Notification::fake();
    insererJobEchoue(PasswordResetCodeNotification::class, now()->subHours(2)->toDateTimeString());
    $this->artisan('mibeko:surveiller-file-mail')->assertFailed();

    insererJobEchoue(UserInvitationNotification::class);
    $this->artisan('mibeko:surveiller-file-mail')->assertFailed();

    Notification::assertSentOnDemandTimes(FileMailBloqueeNotification::class, 2);
    Notification::assertSentOnDemand(
        FileMailBloqueeNotification::class,
        fn ($notification) => count($notification->echecs) === 1 && $notification->echecs[0]['classe'] === 'UserInvitationNotification',
    );
});

it('garde un échec à signaler tant que l\'alerte n\'a pas pu partir', function () {
    insererJobEchoue(PasswordResetCodeNotification::class);

    $smtpEnPanne = true;
    $envoyees = 0;
    Event::listen(NotificationSending::class, function () use (&$smtpEnPanne) {
        if ($smtpEnPanne) {
            throw new RuntimeException('550 5.7.1 Sender mismatch');
        }
    });
    Event::listen(NotificationSent::class, function () use (&$envoyees) {
        $envoyees++;
    });

    expect(fn () => Artisan::call('mibeko:surveiller-file-mail'))->toThrow(RuntimeException::class);
    expect($envoyees)->toBe(0);

    $smtpEnPanne = false;
    $this->artisan('mibeko:surveiller-file-mail')->assertFailed();

    expect($envoyees)->toBe(1);
});

it('continue d\'alerter à chaque passage tant qu\'un job reste bloqué', function () {
    Notification::fake();
    insererJobEnAttente(UserInvitationNotification::class, 15);

    $this->artisan('mibeko:surveiller-file-mail')->assertFailed();
    $this->artisan('mibeko:surveiller-file-mail')->assertFailed();

    Notification::assertSentOnDemandTimes(FileMailBloqueeNotification::class, 2);
});
