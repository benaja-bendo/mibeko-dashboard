<?php

namespace App\Console\Commands;

use App\Models\PlanGrant;
use App\Models\PlanGrantReminder;
use App\Models\UserSetting;
use App\Notifications\PlanGrantExpiringReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Envoie les rappels e-mail des octrois Pro vendus à la main qui arrivent à
 * échéance — mibeko-dashboard#121.
 *
 * Horizons fixes (J-7 et J-1), à la différence des échéances de dossier qui
 * autorisent un horizon par ligne : un octroi n'a pas de notion de rappel
 * personnalisé, une seule cadence suffit pour tous. Idempotence garantie par
 * `plan_grant_reminders` (offset, jour), même mécanique que
 * `mibeko:send-echeance-reminders`. Un octroi révoqué n'est jamais rappelé :
 * son accès s'est déjà arrêté, un rappel d'échéance n'aurait plus de sens.
 */
class SendPlanGrantReminders extends Command
{
    /** @var list<int> */
    private const OFFSETS = [7, 1];

    protected $signature = 'mibeko:send-plan-grant-reminders';

    protected $description = 'Envoie les rappels e-mail des abonnements Pro vendus à la main qui expirent bientôt (J-7/J-1).';

    public function handle(): int
    {
        $today = Carbon::today();
        $sent = 0;
        $skippedByPreference = 0;

        PlanGrant::query()
            ->whereNull('revoked_at')
            ->where('ends_at', '>=', $today->toDateString())
            ->where('ends_at', '<=', $today->copy()->addDays(max(self::OFFSETS))->endOfDay())
            ->with('user.settings')
            ->chunkById(200, function ($grants) use ($today, &$sent, &$skippedByPreference): void {
                foreach ($grants as $grant) {
                    $result = $this->remind($grant, $today);
                    if ($result === 'sent') {
                        $sent++;
                    } elseif ($result === 'skipped_by_preference') {
                        $skippedByPreference++;
                    }
                }
            });

        $this->info("Rappels d'échéance d'abonnement envoyés : {$sent} (préférence désactivée : {$skippedByPreference}).");

        return self::SUCCESS;
    }

    /**
     * @return 'sent'|'skipped_by_preference'|'not_due'
     */
    private function remind(PlanGrant $grant, Carbon $today): string
    {
        $daysUntil = (int) $today->diffInDays($grant->ends_at->copy()->startOfDay());

        if (! in_array($daysUntil, self::OFFSETS, true)) {
            return 'not_due';
        }

        $user = $grant->user;

        if ($user === null) {
            return 'not_due';
        }

        // Gatée par préférence, à la différence de la confirmation d'achat :
        // un rappel est du contenu qu'on peut légitimement vouloir couper,
        // pas la preuve d'une transaction déjà réalisée. Même repli que
        // `LegalWatchNotifier::accepts()` : pas de ligne `user_settings`
        // encore créée => valeur par défaut du type.
        $allowed = $user->settings?->allowsNotification(UserSetting::TYPE_BILLING, 'email')
            ?? UserSetting::notificationDefaultFor(UserSetting::TYPE_BILLING, 'email');

        if (! $allowed) {
            return 'skipped_by_preference';
        }

        $reminder = PlanGrantReminder::firstOrCreate([
            'plan_grant_id' => $grant->id,
            'offset_days' => $daysUntil,
            'sent_on' => $today->toDateString(),
        ]);

        if (! $reminder->wasRecentlyCreated) {
            return 'not_due';
        }

        $user->notify(new PlanGrantExpiringReminderNotification($grant, $daysUntil));

        return 'sent';
    }
}
