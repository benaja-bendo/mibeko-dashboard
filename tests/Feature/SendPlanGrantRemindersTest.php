<?php

use App\Models\PlanGrant;
use App\Models\PlanGrantReminder;
use App\Models\User;
use App\Models\UserSetting;
use App\Notifications\PlanGrantExpiringReminderNotification;
use Illuminate\Support\Facades\Notification;

it('envoie un rappel sept jours avant l\'échéance', function () {
    $user = User::factory()->create();
    PlanGrant::factory()->for($user)->create(['ends_at' => now()->addDays(7)]);

    Notification::fake();
    $this->artisan('mibeko:send-plan-grant-reminders')->assertSuccessful();

    Notification::assertSentTo($user, PlanGrantExpiringReminderNotification::class);
    expect(PlanGrantReminder::count())->toBe(1);
});

it('envoie un rappel la veille de l\'échéance', function () {
    $user = User::factory()->create();
    PlanGrant::factory()->for($user)->create(['ends_at' => now()->addDay()]);

    Notification::fake();
    $this->artisan('mibeko:send-plan-grant-reminders')->assertSuccessful();

    Notification::assertSentTo($user, PlanGrantExpiringReminderNotification::class);
});

it('n\'envoie rien hors des horizons J-7/J-1', function () {
    $user = User::factory()->create();
    PlanGrant::factory()->for($user)->create(['ends_at' => now()->addDays(3)]);

    Notification::fake();
    $this->artisan('mibeko:send-plan-grant-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('ignore un octroi révoqué même à l\'approche de son échéance contractuelle', function () {
    $user = User::factory()->create();
    $grant = PlanGrant::factory()->for($user)->create(['ends_at' => now()->addDays(7)]);
    $grant->revoke();

    Notification::fake();
    $this->artisan('mibeko:send-plan-grant-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('est idempotent dans la même journée', function () {
    $user = User::factory()->create();
    PlanGrant::factory()->for($user)->create(['ends_at' => now()->addDay()]);

    Notification::fake();
    $this->artisan('mibeko:send-plan-grant-reminders')->assertSuccessful();
    $this->artisan('mibeko:send-plan-grant-reminders')->assertSuccessful();

    Notification::assertSentToTimes($user, PlanGrantExpiringReminderNotification::class, 1);
    expect(PlanGrantReminder::count())->toBe(1);
});

it('respecte une préférence de notification désactivée', function () {
    $user = User::factory()->create();
    $user->settings()->create([
        ...UserSetting::defaults(),
        'notification_preferences' => array_merge(
            UserSetting::defaultNotificationPreferences(),
            ['billing' => ['email' => false, 'push' => false, 'in_app' => false]],
        ),
    ]);
    PlanGrant::factory()->for($user)->create(['ends_at' => now()->addDay()]);

    Notification::fake();
    $this->artisan('mibeko:send-plan-grant-reminders')->assertSuccessful();

    Notification::assertNothingSent();
    // Pas de ligne de rappel : si le titulaire réactive la préférence avant
    // l'horizon suivant, rien n'empêche un futur rappel de partir.
    expect(PlanGrantReminder::count())->toBe(0);
});
