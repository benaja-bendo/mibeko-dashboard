<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => env('AI_PROVIDER', 'openai'),
    'default_for_images' => env('AI_PROVIDER', 'gemini'),
    'default_for_audio' => env('AI_PROVIDER', 'openai'),
    'default_for_transcription' => env('AI_PROVIDER', 'openai'),
    'default_for_embeddings' => env('AI_PROVIDER_EMBEDDINGS', 'mistral'),
    'default_for_reranking' => env('AI_PROVIDER', 'cohere'),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
            'models' => [
                'text' => [
                    'default' => env('AI_MODEL', 'mistral-large-latest'),
                ],
            ],
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'models' => [
                'text' => [
                    'default' => env('AI_MODEL', 'qwen3:0.6b'),
                ],
            ],
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'models' => [
                'text' => [
                    'default' => env('AI_MODEL', 'gpt-4o'),
                ],
            ],
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quotas de l'assistant IA (Mibeko)
    |--------------------------------------------------------------------------
    |
    | Plafonds appliqués par le limiteur `ai_assistant` (AppServiceProvider) :
    | requêtes par minute (confort d'usage) ET par jour (maîtrise du coût des
    | fournisseurs LLM). Les administrateurs ont aussi un plafond journalier —
    | un jeton admin compromis ne doit pas pouvoir générer une facture
    | illimitée.
    |
    */

    'quotas' => [
        'standard' => [
            'per_minute' => (int) env('AI_QUOTA_STANDARD_PER_MINUTE', 20),
            'per_day' => (int) env('AI_QUOTA_STANDARD_PER_DAY', 200),
        ],
        'premium' => [
            'per_minute' => (int) env('AI_QUOTA_PREMIUM_PER_MINUTE', 60),
            'per_day' => (int) env('AI_QUOTA_PREMIUM_PER_DAY', 1000),
        ],
        'admin' => [
            'per_day' => (int) env('AI_QUOTA_ADMIN_PER_DAY', 2000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Assistant IA — chaîne de fournisseurs (failover)
    |--------------------------------------------------------------------------
    |
    | Liste ORDONNÉE de fournisseurs pour l'assistant Mibeko IA. Le premier est
    | le principal ; sur une erreur « failover-able » (rate-limit, surcharge,
    | crédits épuisés — jamais une erreur de requête), le SDK bascule sur le
    | suivant. Vide = comportement par défaut (seul `ai.default`). N'active le
    | failover qu'avec des fournisseurs dont la clé est valide, et en gardant à
    | l'esprit la résidence des données : un fournisseur étranger recevrait alors
    | les requêtes juridiques des utilisateurs. Ex. : AI_ASSISTANT_FAILOVER="mistral,openai".
    |
    */

    'assistant' => [
        'providers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AI_ASSISTANT_FAILOVER', '')),
        ))),
    ],

];
