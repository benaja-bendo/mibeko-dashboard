<?php

/**
 * Le déploiement exécute `route:cache`. Ensuite `routes/*.php` n'est plus chargé :
 * toute fonction globale qu'un fichier de routes déclarait n'existe plus, et la
 * route qui l'appelle répond 500. C'est ce qui est arrivé aux pages de partage
 * (mibeko-dashboard#241) : en dev et en test, les routes ne sont pas en cache et
 * rien ne le montre. La logique d'une route vit dans une classe, jamais dans une
 * fonction déclarée à côté de la route.
 */
it('declares no global function in a route file', function (string $file) {
    $tokens = token_get_all((string) file_get_contents($file));
    $declared = [];

    foreach ($tokens as $position => $token) {
        if (! is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        // `function nom(` déclare une fonction ; `function (` ou `function &(` ouvre une closure.
        for ($next = $position + 1; $next < count($tokens); $next++) {
            if (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE || $tokens[$next] === '&') {
                continue;
            }

            if (is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                $declared[] = $tokens[$next][1];
            }

            break;
        }
    }

    expect($declared)->toBe([]);
})->with(fn () => array_map(
    fn (string $file) => [$file],
    glob(__DIR__.'/../../routes/*.php') ?: []
));
