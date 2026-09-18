<?php

namespace App\Search;

/**
 * Surface d'origine canonique pour le journal de recherche
 * (mibeko-dashboard#111) — un seul endroit qui énumère les cinq points
 * d'entrée, pour que l'écran admin (requêtes fréquentes / sans résultat) et
 * les contrôleurs retombent toujours sur la même valeur.
 */
class SearchSurface
{
    public const LIBRARY_SEARCH = 'library/search';

    public const LIBRARY_SUGGEST = 'library/suggest';

    public const MOBILE_SEARCH = 'search';

    public const MOBILE_ARTICLES_SEARCH = 'articles/search';

    public const LEGAL_DOCUMENTS_SEARCH = 'legal-documents/search';
}
