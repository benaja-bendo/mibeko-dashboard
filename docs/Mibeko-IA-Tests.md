# Tester Mibeko IA (Laravel AI SDK) — Tinker, Console & cURL

Ce document explique comment tester **Mibeko IA** localement (sans passer par l’app mobile), soit via **Tinker**, soit via **cURL** contre l’API.

## Prérequis

### 1) Configuration du provider IA

Vérifie dans ton `.env` (exemple) :

```env
AI_PROVIDER=mistral
```

Ensuite, assure-toi d’avoir les variables nécessaires au provider choisi (clé API, endpoint, etc.) selon ta configuration dans [ai.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/config/ai.php).

### 2) Base de données (historique des conversations)

Mibeko IA stocke l’historique dans :

- `agent_conversations`
- `agent_conversation_messages`

Les conversations sont associées à l’utilisateur connecté via `user_id` (UUID).

## Tester avec Tinker (recommandé pour comprendre le flow)

### A) Envoyer un premier message (nouvelle conversation)

Commande :

```bash
php artisan tinker
```

Dans Tinker :

```php
use App\Ai\Agents\MibekoIA;
use App\Models\User;

$user = User::query()->first();

use App\Ai\Agents\MibekoIA;
use App\Models\User;

$user = User::query()->first();

$agent = new MibekoIA();
$response = $agent->forUser($user)->prompt('Bonjour Mibeko IA, explique-moi ce que tu peux faire.');

$response->text;
$response->conversationId;
$response = $agent->forUser($user)->prompt('Bonjour Mibeko IA, explique-moi ce que tu peux faire.');

$response->text;
$response->conversationId;
```

Résultat attendu :

- `$response->text` contient la réponse de l’IA
- `$response->conversationId` contient l’ID (string UUID) de la conversation créée

### B) Continuer une conversation existante

Toujours dans Tinker :

```php
use App\Ai\Agents\MibekoIA;
use App\Models\User;

$user = User::query()->first();
$conversationId = 'COLLE_ICI_UN_CONVERSATION_ID';

$agent = new MibekoIA();
$response = $agent->continue($conversationId, as: $user)
    ->prompt('Ok, donne-moi un exemple concret lié au droit congolais.');

$response->text;
```

### C) Vérifier que l’historique est bien enregistré

```php
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\User;

$user = User::query()->first();

AgentConversation::query()->where('user_id', $user->id)->latest()->first();

AgentConversationMessage::query()
    ->where('user_id', $user->id)
    ->latest()
    ->limit(10)
    ->get(['role', 'content', 'created_at']);
```

## Tester via l’API (cURL)

Les routes sont définies dans [api.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/routes/api.php) et passent par [AiAssistantController](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Http/Controllers/Api/V1/AiAssistantController.php).

### A) Obtenir un token Sanctum pour tester en local

Dans Tinker :

```php
use App\Models\User;

$user = User::query()->first();
$token = $user->createToken('curl-test')->plainTextToken;

$token;
```

Copie le token affiché.

### B) Lister les conversations

```bash
curl -s \
  -H "Authorization: Bearer 1|bLzrxuvZGuX55f125TUHHgWb6uYRjH4gKl9PxhJubf67ddf0" \
  -H "Accept: application/json" \
  http://127.0.0.1:8000/api/v1/assistant/conversations | jq
```

### C) Créer une conversation + envoyer un message (réponse JSON)

```bash
curl -s \
  -H "Authorization: Bearer 1|bLzrxuvZGuX55f125TUHHgWb6uYRjH4gKl9PxhJubf67ddf0" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"message":"Bonjour Mibeko IA, résume-moi les points clés d’un contrat de travail."}' \
  http://127.0.0.1:8000/api/v1/assistant/chat | jq
```

Réponse attendue (exemple) :

```json
{
  "conversation_id": "…",
  "reply": "…"
}
```

### D) Continuer une conversation (réponse JSON)

```bash
curl -s \
  -H "Authorization: Bearer 1|bLzrxuvZGuX55f125TUHHgWb6uYRjH4gKl9PxhJubf67ddf0" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"message":"Continue et donne-moi un exemple."}' \
  "http://127.0.0.1:8000/api/v1/assistant/chat/CONVERSATION_ID" | jq
```

## Tester le streaming (SSE) comme ChatGPT

Le streaming est activé avec `stream: true`.

### A) Nouvelle conversation en streaming

```bash
curl -N \
  -H "Authorization: Bearer 1|bLzrxuvZGuX55f125TUHHgWb6uYRjH4gKl9PxhJubf67ddf0" \
  -H "Accept: text/event-stream" \
  -H "Content-Type: application/json" \
  -d '{"message":"Écris une réponse longue en plusieurs points.", "stream": true}' \
  http://127.0.0.1:8000/api/v1/assistant/chat

// Exemple de résultat (événements SSE) :
// data: {"id":"evt_...","type":"stream_start","provider":"mistral",...}
// data: {"id":"evt_...","type":"text_delta","delta":"Bien","timestamp":1775916128}
// data: {"id":"evt_...","type":"text_delta","delta":" sûr.","timestamp":1775916129}
// ...
// data: [DONE]
```

Notes :

- `-N` empêche cURL de bufferiser la sortie, utile pour voir le flux en direct.
- Selon le client, tu verras des événements SSE successifs (texte progressif), puis la fin du flux.

### B) Continuer une conversation en streaming

```bash
curl -N \
  -H "Authorization: Bearer 1|bLzrxuvZGuX55f125TUHHgWb6uYRjH4gKl9PxhJubf67ddf0" \
  -H "Accept: text/event-stream" \
  -H "Content-Type: application/json" \
  -d '{"message":"Continue.", "stream": true}' \
  "http://127.0.0.1:8000/api/v1/assistant/chat/CONVERSATION_ID"
```

## Tester sans appeler le provider (mode “fake”)

Pour vérifier ton API sans consommer de crédits IA, utilise le fake fourni par `laravel/ai` dans tes tests (Pest).

Exemple existant :

- [AiAssistantControllerTest.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/tests/Feature/Api/V1/AiAssistantControllerTest.php)

Tu peux exécuter uniquement ce test :

```bash
php artisan test tests/Feature/Api/V1/AiAssistantControllerTest.php
```

## Dépannage rapide

### “Unauthorized” sur les routes `/assistant/*`

- Vérifie que tu passes bien `Authorization: Bearer <token>`.
- Vérifie que tu as créé le token via Sanctum sur un utilisateur existant.

### Erreurs provider / clé API manquante

- Vérifie `.env` (provider + clés).
- Vérifie la config dans [ai.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/config/ai.php).
- Lance un `php artisan config:clear` si tu viens de modifier `.env`.

### L’historique n’apparaît pas

- Vérifie que les migrations AI sont bien exécutées.
- Vérifie que tu utilises bien `forUser($user)` (nouvelle conversation) ou `continue($id, as: $user)` (suite).
- Vérifie les tables `agent_conversations` et `agent_conversation_messages`.

