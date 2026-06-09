<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Formules d'abonnement
    |--------------------------------------------------------------------------
    |
    | Catalogue des plans proposés. `stripe_price` est l'identifiant de prix
    | Stripe (Price ID) ; laissé nul tant que Stripe n'est pas configuré, le
    | checkout reste alors indisponible côté API. Les libellés de prix sont en
    | francs congolais (FC) — marché cible République Congoaise.
    |
    */

    'plans' => [
        [
            'id' => 'pro_monthly',
            'name' => 'Pro mensuel',
            'price_label' => '15 000 FC / mois',
            'features' => [
                'Bibliothèque juridique complète',
                'Assistant IA avec citations',
                'Dossiers illimités',
                'Export PDF & JSON',
            ],
            'stripe_price' => env('STRIPE_PRICE_PRO_MONTHLY'),
        ],
        [
            'id' => 'pro_yearly',
            'name' => 'Pro annuel',
            'price_label' => '150 000 FC / an',
            'features' => [
                'Tous les avantages du plan mensuel',
                '2 mois offerts',
                'Support prioritaire',
            ],
            'stripe_price' => env('STRIPE_PRICE_PRO_YEARLY'),
        ],
    ],

];
