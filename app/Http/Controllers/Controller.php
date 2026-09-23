<?php

namespace App\Http\Controllers;

use App\Traits\HttpResponses;
use Illuminate\Http\Request;

abstract class Controller
{
    use HttpResponses;

    /**
     * Taille de page demandée (`per_page`), bornée à [1, $max].
     *
     * Toute valeur absente, non entière ou non positive retombe sur $default.
     * L'ancien `min((int) $request->input('per_page'), 100)` ne bornait que
     * par le haut : `per_page=-1` passait tel quel, et le query builder
     * ignore en silence une LIMIT négative — la « page » devenait alors la
     * table entière, sur des routes publiques.
     */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        $value = filter_var($request->input('per_page'), FILTER_VALIDATE_INT);

        return $value !== false && $value >= 1 ? min($value, $max) : $default;
    }
}
