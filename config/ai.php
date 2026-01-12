<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fournisseur d'IA par défaut
    |--------------------------------------------------------------------------
    |
    | Ce paramètre définit le service d'IA utilisé par défaut dans l'application.
    | Valeurs supportées : "openai", "mistral"
    |
    */

    'default' => env('AI_PROVIDER', 'mistral'),

    'providers' => [

        'openai' => [
            'class' => \App\Services\Ai\OpenAiService::class,
            'api_key' => env('OPENAI_API_KEY'),
            'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
        ],

        'mistral' => [
            'class' => \App\Services\Ai\MistralAiService::class, // À implémenter plus tard
            'api_key' => env('MISTRAL_API_KEY'),
            'embedding_model' => env('MISTRAL_EMBEDDING_MODEL', 'mistral-embed'),
            'chat_model' => env('MISTRAL_CHAT_MODEL', 'mistral-tiny'),
        ],

    ],

];
