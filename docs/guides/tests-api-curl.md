# Tests API Laravel (cURL) — Mibeko

Ce document contient des requêtes `curl` prêtes à l’emploi pour tester l’API Laravel (v1), avec un accent particulier sur les endpoints “IA” (recherche hybride + RAG) exposés via `GET /api/v1/search` (alias `GET /api/v1/articles/search`).

***

## 1) Variables et prérequis

### Base URL

Adapte `BASE_URL` selon ton environnement :

```bash
export BASE_URL="http://127.0.0.1:8000"
```

### Headers communs

```bash
export JSON_HEADERS='Accept: application/json'
```

Si tu envoies du JSON (POST/PATCH), ajoute aussi :

```bash
export JSON_CT='Content-Type: application/json'
```

***

## 2) Endpoints “IA” (recherche hybride + RAG)

### 2.1 Test “autocomplete” (désactive le RAG)

Objectif : vérifier que la recherche remonte des articles sans générer de réponse IA.\
Astuce : `autocomplete=true` empêche explicitement le RAG.

```bash
curl -sS -G "$BASE_URL/api/v1/search" \
  -H "$JSON_HEADERS" \
  --data-urlencode "q=constitution" \
  --data-urlencode "autocomplete=true" \
  | jq .
```

Ce que tu dois observer :

- La réponse est au format paginé standard : `success`, `message`, `data` (liste), `pagination`.
- Pas de champ `data.answer`.

### 2.2 Test RAG explicite (force la génération IA)

Objectif : forcer une réponse IA basée sur les meilleures sources trouvées (top résultats).\
Astuce : `rag=true` force le RAG même si ta requête ne ressemble pas “assez” à une question.

```bash
curl -sS -G "$BASE_URL/api/v1/search" \
  -H "$JSON_HEADERS" \
  --data-urlencode "q=C'est quoi l'article 1 de la constitution ?" \
  --data-urlencode "rag=true" \
  | jq .
```

Ce que tu dois observer :

- La réponse est au format “RAG” (objet) : `success=true`, `message="Réponse générée avec succès"`.
- `data.answer` contient le texte généré.
- `data.sources` contient la liste des articles (avec `document_title`, `number`, `content`, `breadcrumb`, `score`).
- `data.pagination` existe (mais est dans `data` quand le RAG s’exécute).

### 2.3 Test RAG “auto” (déclenchement automatique)

Le RAG peut se déclencher sans `rag=true` si :

- la requête finit par `?`, ou
- elle ressemble à une question (ex: “comment”, “pourquoi”, “quel…”, “est-ce que…”), ou
- elle contient au moins 4 mots,
  et que `autocomplete=false`.

```bash
curl -sS -G "$BASE_URL/api/v1/search" \
  -H "$JSON_HEADERS" \
  --data-urlencode "q=comment fonctionne le préavis ?" \
  | jq .
```

### 2.4 Valider les filtres IA (document, type, tag)

L’endpoint IA supporte des filtres utiles pour “contraindre” le contexte RAG :

- `document_id` : limite à un document (UUID)
- `type` : limite à un type de document (code, ex: `CODE`, `LOI`)
- `tag` : limite aux articles taggés (slug)

#### Étape A — récupérer des types (`type`)

```bash
curl -sS "$BASE_URL/api/v1/document-types" \
  -H "$JSON_HEADERS" \
  | jq .
```

Repère un `code` dans la réponse, puis utilise-le dans `type=...`.

#### Étape B — récupérer des documents (`document_id`)

```bash
curl -sS -G "$BASE_URL/api/v1/legal-documents" \
  -H "$JSON_HEADERS" \
  --data-urlencode "per_page=5" \
  | jq .
```

Repère un `id`, puis utilise-le dans `document_id=...`.

#### Étape C — lancer une requête IA filtrée

```bash
export DOCUMENT_ID="REMPLACE_PAR_UN_UUID"
export TYPE_CODE="REMPLACE_PAR_UN_CODE_TYPE"  # ex: CODE

curl -sS -G "$BASE_URL/api/v1/search" \
  -H "$JSON_HEADERS" \
  --data-urlencode "q=quelles sont les conditions de licenciement ?" \
  --data-urlencode "rag=true" \
  --data-urlencode "document_id=$DOCUMENT_ID" \
  --data-urlencode "type=$TYPE_CODE" \
  | jq .
```

Ce que tu dois observer :

- Les `sources` proviennent bien du document / type ciblé.
- Le texte de `data.answer` cite des éléments présents dans les sources.

### 2.5 Vérifier la “dégradation gracieuse” quand l’embedding ne part pas

L’API tente de générer un embedding si `q` est assez long. Si l’embedding échoue, elle retombe sur une recherche textuelle.

Test simple (requête très courte) :

```bash
curl -sS -G "$BASE_URL/api/v1/search" \
  -H "$JSON_HEADERS" \
  --data-urlencode "q=ab" \
  --data-urlencode "autocomplete=true" \
  | jq .
```

Ce que tu dois observer :

- Soit peu/pas de résultats (normal), mais l’API répond correctement.
- Pas d’erreur serveur (pas de 500).

***

## 3) Endpoints API v1 “classiques” (pour valider le socle)

### 3.1 Home

```bash
curl -sS "$BASE_URL/api/v1/home" -H "$JSON_HEADERS" | jq .
```

### 3.2 Catalogue + stats

```bash
curl -sS "$BASE_URL/api/v1/catalog" -H "$JSON_HEADERS" | jq .
curl -sS "$BASE_URL/api/v1/catalog/stats" -H "$JSON_HEADERS" | jq .
```

### 3.3 Liste des documents + détail

```bash
curl -sS -G "$BASE_URL/api/v1/legal-documents" \
  -H "$JSON_HEADERS" \
  --data-urlencode "per_page=5" \
  | jq .
```

Puis :

```bash
export DOC_ID="REMPLACE_PAR_UN_UUID"
curl -sS "$BASE_URL/api/v1/legal-documents/$DOC_ID" -H "$JSON_HEADERS" | jq .
```

### 3.4 Arbre (structure) d’un document

```bash
export DOC_ID="REMPLACE_PAR_UN_UUID"
curl -sS "$BASE_URL/api/v1/legal-documents/$DOC_ID/tree" -H "$JSON_HEADERS" | jq .
```

### 3.5 Export / PDF proxy (selon ce qui est disponible)

```bash
export DOC_ID="REMPLACE_PAR_UN_UUID"
curl -sS -I "$BASE_URL/api/v1/legal-documents/$DOC_ID/export"
curl -sS -I "$BASE_URL/api/v1/legal-documents/$DOC_ID/pdf"
```

***

## 4) Auth (Sanctum) + endpoints protégés

Certaines routes nécessitent un token Sanctum (`auth:sanctum`), par exemple `GET /api/v1/me` et les notifications.

### 4.1 Login (récupérer un token)

Remarque : la réponse `login` renvoie directement `{ "token": "..." }` (pas enveloppé dans `success/data`).

```bash
curl -sS "$BASE_URL/api/v1/login" \
  -H "$JSON_HEADERS" \
  -H "$JSON_CT" \
  -d '{
    "email": "user@example.com",
    "password": "password",
    "device_name": "curl"
  }' \
  | jq .
```

Puis :

```bash
export TOKEN="REMPLACE_PAR_LE_TOKEN_RECU"
```

### 4.2 Me (token requis)

Remarque : la réponse renvoie directement l’objet `User` (pas enveloppé dans `success/data`).

```bash
curl -sS "$BASE_URL/api/v1/me" \
  -H "$JSON_HEADERS" \
  -H "Authorization: Bearer $TOKEN" \
  | jq .
```

### 4.3 Notifications (token requis)

```bash
curl -sS "$BASE_URL/api/v1/notifications" \
  -H "$JSON_HEADERS" \
  -H "Authorization: Bearer $TOKEN" \
  | jq .
```

Marquer une notification comme lue :

```bash
export NOTIF_ID="REMPLACE_PAR_UN_ID"
curl -sS -X PATCH "$BASE_URL/api/v1/notifications/$NOTIF_ID/read" \
  -H "$JSON_HEADERS" \
  -H "Authorization: Bearer $TOKEN" \
  | jq .
```

Tout marquer comme lu :

```bash
curl -sS "$BASE_URL/api/v1/notifications/read-all" \
  -X POST \
  -H "$JSON_HEADERS" \
  -H "Authorization: Bearer $TOKEN" \
  | jq .
```

### 4.4 Logout

```bash
curl -sS "$BASE_URL/api/v1/logout" \
  -X POST \
  -H "$JSON_HEADERS" \
  -H "Authorization: Bearer $TOKEN" \
  | jq .
```

***

## 5) Notes utiles pour diagnostiquer rapidement

- Si tu as une réponse 422 : c’est généralement une validation Laravel (ex: `q` trop court, `document_id` inexistant, `type` invalide).
- Si tu as une réponse 401 : token manquant ou invalide sur une route protégée.
- Le RAG ne s’exécute pas si `autocomplete=true`. Pour tester l’IA, utilise `rag=true` ou une vraie question.

