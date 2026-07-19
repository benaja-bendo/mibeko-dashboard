<?php

use App\Mcp\Servers\MibekoServer;
use Laravel\Mcp\Facades\Mcp;

// Le serveur web MCP pour exposer la base de données Mibeko à des agents externes (ex: Claude).
// Authentifié par jeton Sanctum : une egress non authentifiée, même limitée au
// contenu publié, reste une porte d'extraction massive du corpus (audit P0.6).
Mcp::web('/mcp/mibeko', MibekoServer::class)
    ->middleware(['auth:sanctum', 'throttle:60,1']);

// Optionnel: Serveur local pour les outils CLI (Laravel Boost, etc)
Mcp::local('mibeko', MibekoServer::class);
