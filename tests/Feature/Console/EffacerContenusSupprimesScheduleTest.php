<?php

use Illuminate\Console\Scheduling\Schedule;

it("planifie l'effacement des contenus supprimés chaque nuit, avant la sauvegarde de 03:00", function () {
    $evenements = collect(app(Schedule::class)->events());

    $effacement = $evenements->first(fn ($evenement) => str_contains($evenement->command, 'mibeko:effacer-contenus-supprimes'));
    $sauvegarde = $evenements->first(fn ($evenement) => str_contains($evenement->command, 'mibeko:backup'));

    expect($effacement)->not->toBeNull()
        ->and($effacement->command)->toContain('--execute')
        ->and($effacement->expression)->toBe('50 2 * * *')
        ->and($effacement->withoutOverlapping)->toBeTrue()
        ->and($sauvegarde)->not->toBeNull()
        ->and($sauvegarde->expression)->toBe('0 3 * * *');
});
