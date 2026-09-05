<?php

use App\Ai\AiUsageLogger;
use App\Models\User;

/**
 * mibeko-dashboard#84 : la classe et un message tronqué/nettoyé de
 * l'exception, pour root-causer le prochain incident depuis la base — sans
 * accès aux logs serveur ni au VPS.
 */
it('capture la classe et le message d\'une exception sur une ligne en échec', function () {
    $user = User::factory()->create();

    $log = app(AiUsageLogger::class)->error(
        $user,
        'assistant/chat',
        exception: new RuntimeException('Le fournisseur a refusé la connexion.'),
    );

    expect($log->error_class)->toBe(RuntimeException::class);
    expect($log->error_message)->toBe('Le fournisseur a refusé la connexion.');
});

it('tronque un message d\'erreur trop long avant de l\'écrire', function () {
    $user = User::factory()->create();
    $long = 'Erreur réseau : '.str_repeat('détail ', 200); // très au-delà de 500 caractères

    $log = app(AiUsageLogger::class)->error(
        $user,
        'assistant/chat',
        exception: new RuntimeException($long),
    );

    expect(mb_strlen($log->error_message))->toBeLessThanOrEqual(500);
    expect($log->error_message)->toStartWith('Erreur réseau :');
});

it('retire un jeton d\'autorisation avant d\'écrire le message d\'erreur', function () {
    $user = User::factory()->create();
    $message = 'Requête sortante : Authorization: Bearer sk-abc123secret Host: api.mistral.ai';

    $log = app(AiUsageLogger::class)->error(
        $user,
        'assistant/chat',
        exception: new RuntimeException($message),
    );

    expect($log->error_message)->not->toContain('sk-abc123secret');
    expect($log->error_message)->toContain('[retiré]');
});

it('laisse error_class et error_message à null sans exception fournie', function () {
    $user = User::factory()->create();

    $log = app(AiUsageLogger::class)->error($user, 'assistant/chat');

    expect($log->error_class)->toBeNull();
    expect($log->error_message)->toBeNull();
});
