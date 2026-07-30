<?php

namespace App\Jobs;

use App\Services\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envoie une tranche de jetons FCM pour une alerte de veille légale.
 *
 * FCM HTTP v1 impose une requête HTTP par jeton : sans découpage, l'envoi à
 * plusieurs milliers d'appareils tiendrait dans une seule exécution et
 * dépasserait le timeout du worker. Une tranche échouée est rejouée seule.
 */
class SendLegalWatchPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, string>  $data
     * @param  string  $format  Forme du message : `PushNotificationService::FORMAT_DATA_ONLY`
     *                          (défaut, deep link fiable, exige l'app v1.2+) ou
     *                          `FORMAT_LEGACY` (bloc `notification`, s'affiche sur
     *                          les versions publiées avant). La tranche est donc
     *                          homogène : c'est l'appelant qui répartit les jetons.
     */
    public function __construct(
        public array $tokens,
        public string $title,
        public string $message,
        public array $data = [],
        public string $format = PushNotificationService::FORMAT_DATA_ONLY,
    ) {}

    public function handle(PushNotificationService $push): void
    {
        if ($this->tokens === []) {
            return;
        }

        $push->sendToDevices($this->tokens, $this->title, $this->message, $this->data, $this->format);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendLegalWatchPush: tranche échouée — '.$exception->getMessage(), [
            'tokens' => count($this->tokens),
            'format' => $this->format,
        ]);
    }
}
