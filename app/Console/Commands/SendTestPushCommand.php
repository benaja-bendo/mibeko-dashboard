<?php

namespace App\Console\Commands;

use App\Services\PushNotificationService;
use Illuminate\Console\Command;

class SendTestPushCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mibeko:test-push
                            {token : The FCM device token}
                            {--title=Test Mibeko : Notification title}
                            {--message=Ceci est une notification de test. : Notification message}
                            {--article_id= : Article ID for redirection}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envoie une notification push de test à un appareil spécifique.';

    /**
     * Execute the console command.
     */
    public function handle(PushNotificationService $pushService)
    {
        $token = $this->argument('token');
        $title = $this->option('title');
        $message = $this->option('message');
        $articleId = $this->option('article_id');

        $data = [];
        if ($articleId) {
            $data['article_id'] = $articleId;
            $data['type'] = 'reader';
        }

        $this->info("Envoi de la notification à : $token");

        $result = $pushService->sendToDevices([$token], $title, $message, $data);

        if ($result['success'] > 0) {
            $this->info("Notification envoyée avec succès !");
        } else {
            $this->error("Échec de l'envoi de la notification.");
        }
    }
}
