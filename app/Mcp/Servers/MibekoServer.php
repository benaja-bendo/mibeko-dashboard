<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\DetectAnomaliesTool;
use App\Mcp\Tools\GetArticleTool;
use App\Mcp\Tools\ListCurationFlagsTool;
use App\Mcp\Tools\SearchLegalDatabaseTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('Mibeko LegalTech Server')]
#[Version('1.1.0')]
#[Instructions('This server provides access to Mibeko legal database containing laws, constitutions, and official codes. Always be concise and accurate when using this data. The curation tools (detect anomalies, list curation flags, get article) are editor-only and assistive: they detect and describe non-conforming text and may carry correction suggestions, but never modify the corpus — corrections are always applied and published by a human through the editor UI.')]
class MibekoServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        SearchLegalDatabaseTool::class,
        DetectAnomaliesTool::class,
        ListCurationFlagsTool::class,
        GetArticleTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
