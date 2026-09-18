<?php

return [

    /*
     * mibeko-dashboard#111 : une requête juridique est une donnée personnelle
     * sensible ("licenciement abusif" raconte une situation) — rétention
     * courte, purgée par `mibeko:purge-search-logs`. 90 jours : assez pour
     * révéler un manque de fond récurrent sur un trimestre, assez court pour
     * rester défendable dans la politique de confidentialité.
     */
    'retention_days' => env('SEARCH_LOGGING_RETENTION_DAYS', 90),

];
