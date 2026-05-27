<?php

use Laravel\Mcp\Facades\Mcp;
use App\Mcp\Servers\MibekoServer;

// Le serveur web MCP pour exposer la base de données Mibeko à des agents externes (ex: Claude)
Mcp::web('/mcp/mibeko', MibekoServer::class)
    ->middleware(['throttle:60,1']);

// Optionnel: Serveur local pour les outils CLI (Laravel Boost, etc)
Mcp::local('mibeko', MibekoServer::class);
