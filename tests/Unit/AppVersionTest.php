<?php

use App\Support\AppVersion;

/**
 * Comparateur de versions d'app (`AppVersion::atLeast`).
 *
 * Unité pure, testée sans base ni conteneur. L'enjeu n'est pas cosmétique : ce
 * comparateur décide de la FORME du push envoyé à un appareil, et une erreur de
 * son côté produit des alertes invisibles que le marqueur `watch_notified_at`
 * consomme définitivement.
 */
it('compare les segments numériquement, jamais comme des chaînes', function () {
    // Le piège que la comparaison de chaînes rate : '1.10.0' < '1.9.0' en ASCII.
    expect(AppVersion::atLeast('1.10.0', '1.9.0'))->toBeTrue()
        ->and(AppVersion::atLeast('1.9.0', '1.10.0'))->toBeFalse()
        ->and(AppVersion::atLeast('2.0.0', '10.0.0'))->toBeFalse()
        ->and(AppVersion::atLeast('10.0.0', '2.0.0'))->toBeTrue();
});

it('accepte la version exactement égale au seuil', function () {
    expect(AppVersion::atLeast('1.2.0', '1.2.0'))->toBeTrue();
});

it('complète les segments manquants par des zéros', function () {
    expect(AppVersion::atLeast('1.2', '1.2.0'))->toBeTrue()
        ->and(AppVersion::atLeast('1.2', '1.2.1'))->toBeFalse()
        ->and(AppVersion::atLeast('2', '1.2.0'))->toBeTrue()
        ->and(AppVersion::atLeast('1', '1.2.0'))->toBeFalse()
        // Quatre segments (versionnage Android à build) : le surplus compte.
        ->and(AppVersion::atLeast('1.2.0.1', '1.2.0'))->toBeTrue()
        ->and(AppVersion::atLeast('1.2.0', '1.2.0.1'))->toBeFalse();
});

it('tolère le préfixe v et les métadonnées de build', function () {
    expect(AppVersion::atLeast('v1.2.0', '1.2.0'))->toBeTrue()
        ->and(AppVersion::atLeast('V1.3', '1.2.0'))->toBeTrue()
        ->and(AppVersion::atLeast('1.2.0+build.42', '1.2.0'))->toBeTrue()
        ->and(AppVersion::atLeast(' 1.2.0 ', '1.2.0'))->toBeTrue();
});

it('classe une pré-version avant la version définitive', function () {
    // Règle semver : 1.2.0-rc1 précède 1.2.0. Une release candidate n'a pas la
    // garantie du comportement final, elle retombe donc sur le format hérité.
    expect(AppVersion::atLeast('1.2.0-rc1', '1.2.0'))->toBeFalse()
        ->and(AppVersion::atLeast('1.2.0', '1.2.0-rc1'))->toBeTrue()
        ->and(AppVersion::atLeast('1.2.1-rc1', '1.2.0'))->toBeTrue()
        ->and(AppVersion::atLeast('1.2.0-rc.2', '1.2.0-rc.1'))->toBeTrue()
        ->and(AppVersion::atLeast('1.2.0-rc.1', '1.2.0-rc.2'))->toBeFalse()
        ->and(AppVersion::atLeast('1.2.0-rc.1', '1.2.0-rc'))->toBeTrue()
        ->and(AppVersion::atLeast('1.2.0-alpha', '1.2.0-beta'))->toBeFalse()
        // Identifiant numérique toujours inférieur à un identifiant alphanumérique.
        ->and(AppVersion::atLeast('1.2.0-1', '1.2.0-alpha'))->toBeFalse()
        ->and(AppVersion::atLeast('1.2.0-alpha', '1.2.0-1'))->toBeTrue();
});

it('traite toute version absente ou illisible comme ancienne', function () {
    // Le parc installé (app publiée) n'annonce rien : c'est le cas NOMINAL, pas
    // un cas d'erreur, et il doit conduire au format hérité.
    expect(AppVersion::atLeast(null, '1.2.0'))->toBeFalse()
        ->and(AppVersion::atLeast('', '1.2.0'))->toBeFalse()
        ->and(AppVersion::atLeast('   ', '1.2.0'))->toBeFalse()
        ->and(AppVersion::atLeast('abc', '1.2.0'))->toBeFalse()
        ->and(AppVersion::atLeast('1.2.x', '1.2.0'))->toBeFalse()
        ->and(AppVersion::atLeast('v', '1.2.0'))->toBeFalse()
        ->and(AppVersion::atLeast('1..2', '1.2.0'))->toBeFalse()
        ->and(AppVersion::atLeast('latest', '1.2.0'))->toBeFalse();
});

it('refuse tout le monde quand le seuil lui-même est illisible', function () {
    // Configuration erronée : mieux vaut servir le format hérité à tout le parc
    // qu'un format qu'une partie des appareils n'affichera pas.
    expect(AppVersion::atLeast('9.9.9', ''))->toBeFalse()
        ->and(AppVersion::atLeast('9.9.9', 'n/a'))->toBeFalse();
});
