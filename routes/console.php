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

Schedule::command('mibeko:prune-audits --days=365')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/prune-audits.log'));
