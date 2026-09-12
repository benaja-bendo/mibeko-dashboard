<?php

/**
 * Nombres de politique de la mesure d'activation produit — mibeko-dashboard#137.
 *
 * Centralisés ici plutôt que dispersés dans le rapport (`mibeko:kpis`) et la
 * purge (`mibeko:purge-product-events`) : les deux doivent lire exactement
 * les mêmes seuils, sinon un compte pourrait être « mature » pour l'un et
 * pas pour l'autre.
 */
return [

    /**
     * Fenêtre pendant laquelle une première activation compte pour la
     * cohorte de son compte. Purement une fenêtre de MESURE — n'affecte
     * jamais un droit ni un accès produit.
     */
    'activation_window_days' => env('PRODUCT_ACTIVATION_WINDOW_DAYS', 90),

    /**
     * Fenêtre calendaire du retour à J+7 : [créé + start_days, créé + end_days).
     * Une cohorte n'est « mature » pour ce calcul que si `now() >= created_at
     * + end_days` pour tous ses comptes.
     */
    'return_window' => [
        'start_days' => 7,
        'end_days' => 13,
    ],

    /**
     * Rétention du détail nominatif (`product_activation_events`). Doit
     * rester strictement supérieure à activation_window_days + return_window
     * pour que l'agrégat durable (calculé par la purge AVANT suppression)
     * dispose toujours du détail encore présent au moment du calcul.
     */
    'retention_days' => env('PRODUCT_ACTIVATION_RETENTION_DAYS', 180),

];
