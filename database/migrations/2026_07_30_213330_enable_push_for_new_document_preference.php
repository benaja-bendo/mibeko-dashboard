<?php

use App\Models\UserSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Active le canal push de la veille légale (`new_document`) sur les réglages
 * déjà enregistrés.
 *
 * Changer la valeur par défaut du modèle ne suffit pas : `settingsOrCreate()`
 * PERSISTE la matrice complète à la création du compte, si bien que tous les
 * comptes existants portent en base l'ancien `push => false`. Sans ce
 * rattrapage, la veille resterait invisible pour eux.
 *
 * Pourquoi c'est sûr MAINTENANT et ne le sera plus après : la chaîne de veille
 * n'a jamais envoyé la moindre alerte (aucun producteur n'existait). Personne
 * n'a donc pu désactiver délibérément un canal qu'il n'a jamais vu — un
 * `false` en base ne peut être que l'ancien défaut. Une fois la veille en
 * service, ce raisonnement ne tiendra plus : ne pas rejouer cette migration.
 *
 * Les autres types de notification ne sont pas touchés : ils restent en opt-in.
 */
return new class extends Migration
{
    private const TYPE = UserSetting::TYPE_NEW_DOCUMENT;

    public function up(): void
    {
        $this->setPushChannel(true);
    }

    public function down(): void
    {
        $this->setPushChannel(false);
    }

    /**
     * Réécrit le seul couple (new_document, push) en préservant strictement le
     * reste de la matrice : autres types, autres canaux et `_frequency`.
     */
    private function setPushChannel(bool $enabled): void
    {
        DB::table('user_settings')
            ->whereNotNull('notification_preferences')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($enabled) {
                foreach ($rows as $row) {
                    $preferences = json_decode((string) $row->notification_preferences, true);

                    if (! is_array($preferences)
                        || ! isset($preferences[self::TYPE])
                        || ! is_array($preferences[self::TYPE])) {
                        continue;
                    }

                    if (($preferences[self::TYPE]['push'] ?? null) === $enabled) {
                        continue;
                    }

                    $preferences[self::TYPE]['push'] = $enabled;

                    DB::table('user_settings')
                        ->where('id', $row->id)
                        ->update(['notification_preferences' => json_encode($preferences)]);
                }
            });
    }
};
