<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application mobile — versions & force-update
    |--------------------------------------------------------------------------
    |
    | Contrat de GET /api/v1/app-config, consommé au démarrage par l'app KMP.
    | `min_supported_version` est la plus ancienne version encore acceptée :
    | en dessous, l'app impose la mise à jour (écran bloquant côté client).
    | `latest_version` sert à l'incitation douce (mise à jour disponible).
    |
    | Défauts sûrs : la 1.0 (version actuellement publiée sur les stores)
    | reste supportée pour ne bloquer personne tant que l'environnement ne
    | dit pas explicitement l'inverse.
    |
    */

    'min_supported_version' => env('MOBILE_MIN_SUPPORTED_VERSION', '1.0'),

    'latest_version' => env('MOBILE_LATEST_VERSION', '1.1.0'),

    /*
    | Message optionnel affiché avec l'écran de mise à jour (null = message
    | par défaut du client).
    */

    'update_message' => env('MOBILE_UPDATE_MESSAGE'),

    /*
    | Liens de mise à jour vers les stores (fiches publiées : Play cg.mibeko.app,
    | App Store id 6768865781). Une URL vide est exposée comme null.
    */

    'store_urls' => [
        'android' => env('MOBILE_STORE_URL_ANDROID', 'https://play.google.com/store/apps/details?id=cg.mibeko.app'),
        'ios' => env('MOBILE_STORE_URL_IOS', 'https://apps.apple.com/app/id6768865781'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Veille légale (alerte de publication)
    |--------------------------------------------------------------------------
    |
    | `digest_threshold` : au-delà de N textes publiés dans une même opération,
    | on n'envoie plus une alerte par texte mais UNE synthèse (« N nouveaux
    | textes publiés »). Défaut 3 : une publication éditoriale ordinaire porte
    | sur un à trois textes et mérite un titre lisible avec lien direct ; au-delà
    | il s'agit d'un traitement de masse (les 103 brouillons du fonds, par
    | exemple), et N alertes rendraient à la fois le centre de notifications et
    | le tiroir du téléphone inutilisables — sans compter N envois FCM par
    | appareil.
    |
    | `push_chunk_size` : nombre de jetons traités par job d'envoi. FCM HTTP v1
    | impose une requête par jeton : on borne la durée d'un job plutôt que
    | d'enchaîner des milliers d'appels dans une seule exécution.
    |
    | `data_only_min_app_version` : version d'app à partir de laquelle le push
    | part en « data-only » (aucun bloc `notification`), seule forme qui préserve
    | le deep link. C'est la v1.2.0 qui a appris à AFFICHER un tel message
    | (repli sur `data["title"]` / `data["message"]` dans
    | `MyFirebaseMessagingService`) ; en dessous — et pour tout appareil dont la
    | version est inconnue — le serveur retombe sur le format hérité, visible
    | partout. À relever seulement si une génération ultérieure changeait de
    | contrat ; à ne JAMAIS abaisser sous 1.2.0, cela rendrait les alertes
    | invisibles sur le parc publié.
    |
    */

    'watch' => [
        'digest_threshold' => (int) env('MOBILE_WATCH_DIGEST_THRESHOLD', 3),
        'push_chunk_size' => (int) env('MOBILE_WATCH_PUSH_CHUNK_SIZE', 100),
        'data_only_min_app_version' => env('MOBILE_WATCH_DATA_ONLY_MIN_APP_VERSION', '1.2.0'),
    ],

];
