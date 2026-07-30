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
                            {--slug= : Slug du texte à ouvrir au tap (deep link mibeko://textes/{slug})}
                            {--article_id= : Numéro d\'article à ouvrir dans ce texte}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envoie une notification push de test à un appareil spécifique.';

    /**
     * Execute the console command.
     *
     * Les clés du `data` suivent le contrat lu par l'app mobile
     * (`MyFirebaseMessagingService`) : `slug` (+ `article` optionnel) pour le
     * deep link. L'ancienne clé `article_id` n'était lue par personne — une
     * notification de test ne pouvait donc jamais valider le lien profond,
     * c'est-à-dire précisément ce qu'on veut vérifier avec cette commande.
     */
    public function handle(PushNotificationService $pushService)
    {
        $token = $this->argument('token');
        $title = $this->option('title');
        $message = $this->option('message');
        $slug = $this->option('slug');
        $articleId = $this->option('article_id');

        $data = [];
        if ($slug) {
            $data['type'] = 'legal_watch';
            $data['slug'] = $slug;
            $data['deeplink'] = 'mibeko://textes/'.$slug;

            if ($articleId) {
                $data['article'] = $articleId;
            }
        }

        $this->info("Envoi de la notification à : $token");

        $result = $pushService->sendToDevices([$token], $title, $message, $data);

        if ($result['success'] > 0) {
            $this->info('Notification envoyée avec succès !');
        } else {
            $this->error("Échec de l'envoi de la notification.");
        }
    }
}
