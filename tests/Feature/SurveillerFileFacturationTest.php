<?php

use App\Notifications\FacturationMailBloqueeNotification;
use App\Notifications\PlanGrantActivatedNotification;
use App\Notifications\PlanGrantExpiringReminderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function insererJobFacturationEchoue(string $classe, ?string $failedAt = null): void
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

function insererJobFacturationEnAttente(string $classe, int $ilYA): void
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

    $this->artisan('mibeko:surveiller-file-facturation')->assertSuccessful();

    Notification::assertNothingSent();
});

it('alerte sur un échec de confirmation d\'achat', function () {
    Notification::fake();
    insererJobFacturationEchoue(PlanGrantActivatedNotification::class);

    $this->artisan('mibeko:surveiller-file-facturation')->assertFailed();

    Notification::assertSentOnDemand(
        FacturationMailBloqueeNotification::class,
        fn ($notification) => $notification->echecs !== [] && $notification->echecs[0]['classe'] === 'PlanGrantActivatedNotification',
    );
});

it('alerte sur un rappel d\'échéance bloqué au-delà du seuil', function () {
    Notification::fake();
    insererJobFacturationEnAttente(PlanGrantExpiringReminderNotification::class, 15);

    $this->artisan('mibeko:surveiller-file-facturation')->assertFailed();

    Notification::assertSentOnDemand(
        FacturationMailBloqueeNotification::class,
        fn ($notification) => $notification->bloques !== [] && $notification->bloques[0]['classe'] === 'PlanGrantExpiringReminderNotification',
    );
});

it('ignore les échecs de jobs sans rapport avec la facturation', function () {
    Notification::fake();
    insererJobFacturationEchoue('App\\Jobs\\EmbedArticleChunkJob');

    $this->artisan('mibeko:surveiller-file-facturation')->assertSuccessful();

    Notification::assertNothingSent();
});
