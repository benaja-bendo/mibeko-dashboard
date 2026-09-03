<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mibeko:backup --disk=gdrive --only-db')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/backup.log'));

Schedule::command('backup:clean')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/backup-clean.log'));

// Vérifie que la sauvegarde de la base a réellement abouti (âge, taille) et
// notifie sur échec — jusqu'ici jamais planifié, un dump en échec silencieux
// n'aurait été découvert qu'au moment d'une restauration. Nécessite
// MAIL_TO_ADDRESS renseigné en production (config/backup.php > notifications)
// — voir le bloc de commandes humain, ce fichier ne porte aucun secret.
Schedule::command('backup:monitor')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/backup-monitor.log'));

Schedule::command('mibeko:process-rag --limit=50 --batch=10 --delay=1000')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/process-rag.log'));

Schedule::command('mibeko:send-echeance-reminders')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/echeance-reminders.log'));

// Filet de sécurité : les documents ingérés par le pipeline Python (écriture
// directe en base, sans Eloquent) arrivent sans slug et resteraient invisibles
// du site vitrine une fois publiés. On répare les slugs manquants chaque heure.
Schedule::command('mibeko:backfill-document-slugs')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/backfill-document-slugs.log'));

// Reprise de la veille légale : un lot réservé (les textes portent déjà
// `watch_notified_at`) mais dont la diffusion n'a jamais abouti ne redeviendrait
// jamais candidat de lui-même. On rejoue toutes les demi-heures les lots âgés
// d'au moins 15 min — la file a largement eu le temps de faire son travail, et
// le rejeu ne peut pas produire de doublon.
Schedule::command('mibeko:retry-legal-watch --older-than=15')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/retry-legal-watch.log'));

// Le store de cache `database` n'oublie une entrée expirée que s'il la relit :
// une clé jamais redemandée reste en table indéfiniment. Mesuré en production
// le 25/08/2026, 88 % des lignes de `cache` étaient expirées (2 036 sur 2 302)
// — volume encore modeste, mais sans borne dans le temps. Hebdomadaire : ces
// lignes ne gênent personne tant qu'elles sont peu nombreuses, c'est leur
// accumulation sans fin qu'il s'agit d'arrêter.
Schedule::command('mibeko:purger-cache-expire')
    ->weeklyOn(1, '04:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/purge-cache.log'));

Schedule::command('mibeko:prune-audits --days=365')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/prune-audits.log'));

// Un e-mail de réinitialisation ou d'invitation qui ne part pas ne doit pas
// rester muet (mibeko-dashboard#60) : l'API répond 200 dans tous les cas
// (anti-énumération), et rien d'autre ne surveille cette file. Volontairement
// SANS --without-overlapping ni runInBackground : l'alerte elle-même doit
// rester simple à raisonner si jamais le worker de file est la panne.
Schedule::command('mibeko:surveiller-file-mail')
    ->everyFifteenMinutes()
    ->appendOutputTo(storage_path('logs/surveiller-file-mail.log'));
