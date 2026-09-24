<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

/*
 * mibeko-dashboard#183 : la purge des comptes supprimés tourne chaque nuit,
 * AVANT la sauvegarde — sinon la sauvegarde de 03:00 recopierait des comptes
 * que la politique de confidentialité promet effacés.
 */

function evenementPlanifie(string $commande): ?Event
{
    app(Kernel::class)->bootstrap();

    return collect(app(Schedule::class)->events())
        ->first(fn (Event $evenement) => str_contains((string) $evenement->command, $commande));
}

it('planifie la purge réelle des comptes supprimés chaque jour à 02:45 UTC', function () {
    $purge = evenementPlanifie('mibeko:purger-comptes-supprimes');

    expect($purge)->not->toBeNull()
        ->and($purge->command)->toContain('--execute')
        ->and($purge->expression)->toBe('45 2 * * *')
        ->and(config('app.timezone'))->toBe('UTC');
});

it('fait passer la purge avant la sauvegarde de la base', function () {
    $purge = evenementPlanifie('mibeko:purger-comptes-supprimes');
    $sauvegarde = evenementPlanifie('mibeko:backup');

    expect($sauvegarde->expression)->toBe('0 3 * * *')
        ->and($purge->expression)->toBe('45 2 * * *');
});
