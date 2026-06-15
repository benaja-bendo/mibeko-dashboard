<?php

namespace App\Console\Commands;

use App\Models\DossierEcheance;
use App\Models\EcheanceReminder;
use App\Notifications\EcheanceReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Envoie les rappels e-mail des échéances à venir.
 *
 * Pour chaque échéance « à venir » datée, on déclenche un rappel quand le
 * nombre de jours restants correspond à l'un de ses horizons (`reminders`,
 * défaut J-15/7/2/0). Le journal `echeance_reminders` garantit l'idempotence
 * (un seul envoi par échéance/horizon/jour), même si la command tourne deux fois.
 */
class SendEcheanceReminders extends Command
{
    protected $signature = 'mibeko:send-echeance-reminders';

    protected $description = 'Envoie les rappels e-mail des échéances de dossier à venir (J-15/7/2/0 par défaut).';

    public function handle(): int
    {
        $today = Carbon::today();
        $sent = 0;

        DossierEcheance::query()
            ->where('status', 'a_venir')
            ->whereNotNull('due_date')
            ->where('due_date', '>=', $today->toDateString())
            ->with('dossier.user')
            ->chunkById(200, function ($echeances) use ($today, &$sent): void {
                foreach ($echeances as $echeance) {
                    if ($this->remind($echeance, $today)) {
                        $sent++;
                    }
                }
            });

        $this->info("Rappels d'échéance envoyés : {$sent}");

        return self::SUCCESS;
    }

    /**
     * Envoie le rappel si l'échéance tombe sur l'un de ses horizons et qu'il
     * n'a pas déjà été envoyé aujourd'hui. Retourne vrai si un mail est parti.
     */
    private function remind(DossierEcheance $echeance, Carbon $today): bool
    {
        $reminders = $echeance->reminders ?? [];
        $daysUntil = (int) $today->diffInDays($echeance->due_date);

        if (! in_array($daysUntil, $reminders, true)) {
            return false;
        }

        $user = $echeance->dossier?->user;

        if ($user === null) {
            return false;
        }

        $reminder = EcheanceReminder::firstOrCreate([
            'echeance_id' => $echeance->id,
            'offset_days' => $daysUntil,
            'sent_on' => $today->toDateString(),
        ]);

        if (! $reminder->wasRecentlyCreated) {
            return false;
        }

        $user->notify(new EcheanceReminderNotification($echeance, $daysUntil));

        return true;
    }
}
