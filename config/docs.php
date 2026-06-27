<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Accès à la documentation API (/docs/api)
    |--------------------------------------------------------------------------
    |
    | Identifiants HTTP Basic protégeant la documentation Scramble. Lorsqu'ils
    | sont renseignés, la documentation est accessible (y compris en production)
    | aux seuls porteurs de ces identifiants. Laissés vides, l'accès est bloqué
    | en production et ouvert hors production.
    |
    */

    'username' => env('API_DOCS_USERNAME'),

    'password' => env('API_DOCS_PASSWORD'),

];
