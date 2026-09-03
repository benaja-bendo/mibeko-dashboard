<?php

use App\Notifications\FileMailBloqueeNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\UserInvitationNotification;
use Illuminate\Support\Facades\DB;
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
