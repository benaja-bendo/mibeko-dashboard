<?php

use App\Models\Dossier;
use App\Models\DossierEcheance;
use App\Models\EcheanceReminder;
use App\Models\User;
use App\Notifications\EcheanceReminderNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Crée une échéance datée appartenant à un nouvel utilisateur.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: DossierEcheance}
 */
function echeanceDueInDays(int $days, array $attributes = []): array
{
    $user = User::factory()->create();
    $echeance = DossierEcheance::factory()
        ->for(Dossier::factory()->for($user))
        ->dueInDays($days)
        ->create($attributes);

    return [$user, $echeance];
}

it('envoie un rappel quand l\'horizon correspond', function () {
    [$user] = echeanceDueInDays(7, ['reminders' => [15, 7, 2, 0]]);

    Notification::fake();
    $this->artisan('mibeko:send-echeance-reminders')->assertSuccessful();

    Notification::assertSentTo($user, EcheanceReminderNotification::class);
    expect(EcheanceReminder::count())->toBe(1);
});

it('envoie aussi le jour même (J-0)', function () {
    [$user] = echeanceDueInDays(0, ['reminders' => [0]]);

    Notification::fake();
    $this->artisan('mibeko:send-echeance-reminders')->assertSuccessful();

    Notification::assertSentTo($user, EcheanceReminderNotification::class);
});

it('n\'envoie rien hors des horizons configurés', function () {
    echeanceDueInDays(5, ['reminders' => [15, 7, 2, 0]]);

    Notification::fake();
    $this->artisan('mibeko:send-echeance-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('ignore les échéances passées et celles déjà faites', function () {
    echeanceDueInDays(-3, ['reminders' => [0]]);
    echeanceDueInDays(0, ['reminders' => [0], 'status' => 'fait']);

    Notification::fake();
    $this->artisan('mibeko:send-echeance-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('est idempotent dans la même journée', function () {
    [$user] = echeanceDueInDays(7, ['reminders' => [7]]);

    Notification::fake();
    $this->artisan('mibeko:send-echeance-reminders')->assertSuccessful();
    $this->artisan('mibeko:send-echeance-reminders')->assertSuccessful();

    Notification::assertSentToTimes($user, EcheanceReminderNotification::class, 1);
    expect(EcheanceReminder::count())->toBe(1);
});
