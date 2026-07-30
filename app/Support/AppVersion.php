<?php

namespace App\Support;

/**
 * Comparaison de versions d'application (semver toléré).
 *
 * Sert à décider quelle FORME de message push un appareil sait afficher (cf.
 * `SendLegalWatchNotifications::dispatchPushes`). Une comparaison de chaînes
 * naïve y serait fausse et silencieusement dangereuse : « 1.10.0 » est plus
 * récent que « 1.9.0 », alors que `'1.10.0' >= '1.9.0'` vaut `false`.
 *
 * Tolérances assumées, parce que la valeur vient du client mobile et non d'un
 * référentiel maîtrisé : préfixe `v`, nombre de segments libre (`1.2` ≡
 * `1.2.0`), métadonnées de build ignorées (`1.2.0+42`), suffixe de pré-version
 * traité selon la règle semver (`1.2.0-rc1` < `1.2.0`). Toute valeur nulle,
 * vide ou illisible est traitée comme « ancienne version » : c'est le repli sûr,
 * il conduit au format de message le plus largement affichable.
 */
class AppVersion
{
    /**
     * Vrai si `$version` est au moins `$minimum`.
     *
     * Un `$minimum` illisible (configuration erronée) renvoie `false` pour tout
     * le monde : mieux vaut servir le format hérité à tout le parc qu'un format
     * que la moitié des appareils n'affichera pas.
     */
    public static function atLeast(?string $version, string $minimum): bool
    {
        $candidate = self::parse($version);
        $reference = self::parse($minimum);

        if ($candidate === null || $reference === null) {
            return false;
        }

        return self::compareParsed($candidate, $reference) >= 0;
    }

    /**
     * Découpe une version en segments numériques + suffixe de pré-version.
     *
     * @return array{numbers: array<int, int>, pre_release: string|null}|null `null` si illisible
     */
    private static function parse(?string $version): ?array
    {
        $value = trim((string) $version);

        if ($value === '') {
            return null;
        }

        $value = ltrim($value, 'vV');

        // Les métadonnées de build ne participent pas à la précédence semver.
        $value = explode('+', $value, 2)[0];

        [$core, $preRelease] = array_pad(explode('-', $value, 2), 2, null);

        if (! preg_match('/^\d+(\.\d+)*$/', (string) $core)) {
            return null;
        }

        return [
            'numbers' => array_map('intval', explode('.', (string) $core)),
            'pre_release' => $preRelease,
        ];
    }

    /**
     * @param  array{numbers: array<int, int>, pre_release: string|null}  $left
     * @param  array{numbers: array<int, int>, pre_release: string|null}  $right
     * @return int -1, 0 ou 1
     */
    private static function compareParsed(array $left, array $right): int
    {
        $length = max(count($left['numbers']), count($right['numbers']));

        // Segment absent = 0 : « 1.2 » et « 1.2.0 » désignent la même version.
        for ($index = 0; $index < $length; $index++) {
            $comparison = ($left['numbers'][$index] ?? 0) <=> ($right['numbers'][$index] ?? 0);

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return self::comparePreRelease($left['pre_release'], $right['pre_release']);
    }

    /**
     * Règle semver : à segments numériques égaux, une version SANS suffixe de
     * pré-version l'emporte sur la même version AVEC (1.2.0 > 1.2.0-rc1). Entre
     * deux suffixes, les identifiants se comparent un à un — numériquement
     * entre eux, alphabétiquement sinon, un identifiant numérique étant toujours
     * de précédence inférieure à un identifiant alphanumérique.
     *
     * @return int -1, 0 ou 1
     */
    private static function comparePreRelease(?string $left, ?string $right): int
    {
        if ($left === $right) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        $leftIdentifiers = explode('.', $left);
        $rightIdentifiers = explode('.', $right);
        $length = max(count($leftIdentifiers), count($rightIdentifiers));

        for ($index = 0; $index < $length; $index++) {
            $leftIdentifier = $leftIdentifiers[$index] ?? null;
            $rightIdentifier = $rightIdentifiers[$index] ?? null;

            // À préfixe égal, le jeu d'identifiants le plus court est le plus
            // ancien (rc < rc.1).
            if ($leftIdentifier === null) {
                return -1;
            }

            if ($rightIdentifier === null) {
                return 1;
            }

            $leftIsNumeric = ctype_digit($leftIdentifier);
            $rightIsNumeric = ctype_digit($rightIdentifier);

            if ($leftIsNumeric !== $rightIsNumeric) {
                return $leftIsNumeric ? -1 : 1;
            }

            $comparison = $leftIsNumeric
                ? ((int) $leftIdentifier <=> (int) $rightIdentifier)
                : strcmp($leftIdentifier, $rightIdentifier);

            if ($comparison !== 0) {
                return $comparison <=> 0;
            }
        }

        return 0;
    }
}
