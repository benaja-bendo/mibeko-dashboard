<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mibeko:backup')->dailyAt('03:00');
Schedule::command('backup:clean')->dailyAt('04:00');

Schedule::command('mibeko:process-rag --limit=50 --batch=10 --delay=1000')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/process-rag.log'));
