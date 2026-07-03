# Spécification produit — Backend & API Mibeko

> Statut : à jour au 2 juillet 2026 · Périmètre, modèle de données, conventions et endpoints de l'API Laravel du dépôt `mibeko-tableau-de-bord`.

**Projet :** Mibeko Backend (API v1)
**Stack :** Laravel 13 (PHP ≥ 8.2, exécuté sous PHP 8.4/8.5), PostgreSQL (extensions `ltree`, `vector`, `btree_gist`), `spatie/laravel-query-builder`, MinIO (compatible S3), Scramble, Pest.
**Clients servis :** SPA React `mibeko-front` (dashboard pro/éditorial/admin), application mobile `mibeko-app-kmp` (Kotlin Multiplatform), site public `mibeko-site` (Astro).
**Versioning API :** `/api/v1`

---

## 1. Rôle du backend

Le backend Mibeko est l'unique source de vérité de l'écosystème. Il centralise la structure du corpus juridique (arborescence), le temps (validité des textes), le sens (recherche hybride lexicale + sémantique) et sert des payloads optimisés à trois clients distincts.

Il ne rend plus d'interface : le legacy Inertia est **quasi mort**. Le dossier `resources/js/pages` est **vide** et l'interface réelle est le SPA React séparé `mibeko-front`. Le backend expose donc essentiellement une API JSON (`/api/v1`), quelques pages de partage social (`/article/{id}`, `/document/{id}`) et une page de statut à la racine.

### Objectifs clés

- **Distribution « offline-ready »** : générer des payloads JSON à plat pour insertion massive côté mobile (Room/SQLite).
- **Intégrité temporelle** : garantir qu'un article abrogé n'est jamais servi comme « en vigueur » (contraintes `tstzrange` + `EXCLUDE USING gist`).
- **Recherche hybride** : combiner la précision lexicale (`tsvector`) et la compréhension sémantique (`pgvector`).
- **Assistance IA** : offrir un assistant conversationnel et des outils IA à la demande (explication, synthèse, détection d'anomalies) via `laravel/ai`.

---

## 2. Architecture des données (schéma & modèles)

### 2.1 Modèles principaux (Eloquent)

| Modèle Laravel | Table SQL | Rôle | Spécificité technique |
| :--- | :--- | :--- | :--- |
| **LegalDocument** | `legal_documents` | Racine (Code, Loi, Décret…) | Métadonnées, `curation_status` (`draft/review/validated/published`), `slug` public. |
| **StructureNode** | `structure_nodes` | Squelette (Livre > Titre) | Extension **ltree** (`path`) pour la récursion performante. |
| **Article** | `articles` | Entité logique | Lien immuable (« Article 45 ») conservant son ID à travers les versions. |
| **ArticleVersion** | `article_versions` | Contenu (le texte) | `tstzrange` (`validity_period`) et `vector` (`embedding`). |
| **OfficialJournal** | `official_journals` | Journaux officiels | Source FLUX, contrainte STOCK ↛ JO. |
| **CurationFlag** | `curation_flags` | Anomalies & signalements | Alimente la vue Contrôle et le triage admin. |

### 2.2 Stratégie de versionnement (SCD Type 2)

Le backend gère le cycle de vie par « close & insert » :

- **Modification** : fermeture de la période de validité de la version précédente (`upper(validity_period) = NOW()`).
- **Nouvelle version** : insertion d'une ligne avec `validity_period = [NOW(), infinity)`.
- **Contrainte SQL** : `EXCLUDE USING gist` empêche tout chevauchement temporel pour un même article.

---

## 3. Authentification & autorisation

- **Jetons API** : `laravel/sanctum`. Le login mobile et web s'effectue via `POST /api/v1/login` (email/mot de passe), qui renvoie un jeton porteur (`Bearer`). Les routes protégées passent par le middleware `auth:sanctum`.
- **Double authentification (2FA)** : TOTP géré au login et par les endpoints `profile/two-factor` (activation, confirmation, codes de récupération, désactivation).
- **Rôles** : `spatie/laravel-permission`. Les opérations d'écriture éditoriales sont réservées à `role:editor|admin` ; l'espace Administration (`/api/v1/admin/*`) est réservé à `role:admin`.
- **Login Firebase** : `POST /api/v1/auth/firebase` pour l'authentification déléguée mobile.
- **Réinitialisation de mot de passe** : par code OTP (`forgot-password` / `reset-password`), avec limitation de débit dédiée.

> Remarque de sécurité : le serveur MCP web (`/mcp/mibeko`, voir §6) est actuellement exposé **sans authentification** (seulement une limitation de débit `throttle:60,1`).

---

## 4. Standards de communication API

### 4.1 Versioning

Toutes les routes sont préfixées par `/api/v1`. Les contrôleurs et ressources sont organisés sous des espaces de noms `V1`.

### 4.2 Format de réponse (enveloppe)

Les réponses suivent une structure unifiée via le trait `HttpResponses`.

Succès :
```json
{ "success": true, "message": "Opération réussie", "data": { } }
```

Erreur :
```json
{ "success": false, "message": "Description de l'erreur", "errors": { } }
```

### 4.3 API Resources

L'API ne retourne jamais un modèle Eloquent brut : elle passe par des classes `Resource` / `AnonymousResourceCollection` pour centraliser la présentation et éviter d'exposer des champs sensibles.

### 4.4 Filtrage & recherche

Les endpoints de liste utilisent `spatie/laravel-query-builder` : `?filter[nom]=valeur`, `?sort=-created_at`, `?include=relations`.

### 4.5 Documentation automatisée (Scramble)

L'API est documentée via `dedoc/scramble`, exposée sur `/docs/api` (accès restreint et non indexé en production). Les DocBlocks des contrôleurs, FormRequests et Resources alimentent la spec OpenAPI 3.

---

## 5. Périmètre fonctionnel de l'API (endpoints réels)

Convention : réponses JSON, dates ISO 8601 (UTC). Références vérifiées dans `routes/api.php`.

### 5.1 Authentification & compte

- `POST /register`, `POST /login`, `POST /auth/firebase`, `POST /logout`, `GET /me`.
- `POST /forgot-password`, `POST /reset-password` (OTP mobile).
- `GET|PUT /profile`, `PUT /profile/password`, préférences, consentements RGPD.
- 2FA : `GET|POST|DELETE /profile/two-factor` (+ `confirm`, `recovery-codes`).
- Sessions Sanctum : `GET /profile/sessions`, révocation ciblée ou globale.
- Conformité RGPD : `GET /profile/export`, `DELETE /profile`.
- Appareils (notifications push) : `POST /devices/register`, `POST /devices/unregister`.

### 5.2 Catalogue & synchronisation (mobile offline-ready)

- `GET /catalog` (BE1) : comparaison des versions locales / serveur.
- `GET /catalog/stats`, `GET /sync`.
- `GET /legal-documents/{id}/download` (BE2) : liste plate d'un document ou d'un sous-arbre (`?node_id=`), avec gestion des identifiants supprimés.

### 5.3 Consultation du corpus

- `GET /legal-documents` (index, show), `GET /legal-documents/slug/{slug}` (vue publique SEO, publié uniquement).
- `GET /legal-documents/{document}/tree` : arborescence structurée.
- `GET /institutions`, `GET /document-types`, `GET /official-journals` (+ `years`).
- `GET /legal-documents/{id}/pdf` (BE4) : proxy du PDF source depuis MinIO.
- `GET /legal-documents/{id}/export`, `GET /articles/{id}/export` (BE5) : export PDF.

### 5.4 Recherche

- `GET /search`, `GET /articles/search` (BE3) : recherche hybride pour le mobile.
- `GET /articles/{id}/context` : résolution d'un article isolé issu d'un résultat de recherche.
- `GET /library/search`, `GET /library/suggest` : moteur de la bibliothèque (lexical + filet trigram + filet sémantique), en lecture publique, avec limitation de débit dédiée.

### 5.5 Bibliothèque publique

- `GET /library/home`, `GET /library/themes`, `GET /library/themes/{slug}` : contenu identique pour tous, mis en cache serveur. La consultation ne requiert pas de compte.

### 5.6 Assistant IA (authentifié)

- Conversations : `GET|PUT|DELETE /assistant/conversations/{id}`, `GET /assistant/conversations`.
- `POST /assistant/chat/{id?}` : échange conversationnel (limité par `throttle:ai_assistant`).
- `GET /assistant/references` : références citables (@).
- Avis : `POST|DELETE /assistant/messages/{message}/feedback`.
- IA à la demande (streaming SSE) : `POST /library/explain`, `POST /library/synthesis`.

### 5.7 Dossiers

- Synchronisation mobile (LWW) : `GET /dossiers`, `POST /dossiers/sync`.
- CRUD « affaire » web : `POST /dossiers`, `GET|PATCH|DELETE /dossiers/{dossier}`, échéances associées.
- `POST /dossiers/export-pdf` (BE6).

### 5.8 Facturation (Cashier — dormante en production)

- `GET /billing`, `PUT /billing/info`, `POST /billing/checkout`, `GET /billing/portal`, `GET /billing/invoices/{id}/pdf`.
- Ces endpoints s'appuient sur `laravel/cashier` (Stripe). **Le code est présent mais dormant en production** : tant que `cashier.secret` n'est pas renseigné, `stripeEnabled()` renvoie `false` et les endpoints se dégradent proprement (lecture de l'état local possible, checkout et portail refusés). Stripe n'est pas configuré en production à ce jour.

### 5.9 Édition & curation (rôle `editor|admin`)

- Écriture documents : `POST /legal-documents`, `PATCH /legal-documents/{id}`, bulk update/delete, suppression avec `deletion-impact`.
- Structure & articles : `apiResource` structure-nodes / articles, `move`, versions, relations.
- Curation : `GET /legal-documents/{id}/curation-flags`, `detect-anomalies`, `analyze-ai`, `PATCH /curation-flags/{flag}`.
- Embeddings : `POST|DELETE /legal-documents/{document}/embed`.
- Thèmes de vie : `POST /legal-documents/{id}/suggest-themes` (suggestion IA).
- Journaux officiels : `PATCH|DELETE /official-journals/{id}`.

### 5.10 Espace Administration (rôle `admin`)

Sous `/api/v1/admin/*` : `overview`, CRUD des référentiels (`document-types`, `institutions`, `tags`), triage des signalements (`flags`), gestion des utilisateurs (`users`, stats, restore, reset mot de passe, révocation de jetons, vérification email, désactivation 2FA, impersonation), invitations d'équipe, et journal d'activité (`audits`) via `owen-it/laravel-auditing`.

### 5.11 Signalements & partage

- `POST /reports` : signalement d'un contenu depuis l'application mobile.
- `POST /contact` : formulaire de contact public (site vitrine), fortement limité.
- `GET /sitemap` : plan du site (documents publiés + numéros d'articles).
- Pages de partage social (route `web`, hors `/api`) : `GET /article/{id}`, `GET /document/{id}` portent les balises Open Graph / App Links et un `rel=canonical` vers le lecteur public `mibeko.fr` lorsque le contenu est publié.

---

## 6. Assistant IA & serveur MCP

- **Assistant** : implémenté côté Laravel via `laravel/ai` (répertoire `app/Ai/`). Il comprend l'agent conversationnel (`MibekoIA`), un magasin de conversations compactées, un classifieur de thèmes, un détecteur d'anomalies et un outil de recherche en base. Le fournisseur IA est paramétrable (`AI_PROVIDER`, par défaut `mistral` dans `.env.example` ; les embeddings utilisent un fournisseur dédié).
- **Serveur MCP** : `laravel/mcp` expose `MibekoServer` (outil `SearchLegalDatabaseTool`) sur `/mcp/mibeko` (web) et en local. L'accès web est aujourd'hui **non authentifié** (uniquement `throttle:60,1`), ce qui constitue un point de vigilance sécurité à traiter.

---

## 7. Exigences non fonctionnelles (NFR)

- **Sécurité** : jetons Sanctum, 2FA, rôles Spatie, limitation de débit par domaine (`throttle:api`, `throttle:ai_assistant`, `throttle:search_public`, etc.).
- **Performance** : index GIN (`tsv`) et HNSW (`vector`), mise en cache serveur des lectures publiques.
- **Qualité (tests)** : suite **Pest** d'environ 365 tests (≈ 380 cas répartis sur ~63 fichiers Feature et quelques Unit), exécutée contre une **base PostgreSQL réelle** (`mibeko_testing`, cf. `phpunit.xml`) et non SQLite en mémoire, afin de couvrir `ltree`, `pgvector` et les contraintes `gist`.
- **Base partagée** : PostgreSQL est partagée avec le service Python d'ingestion (`mibeko-python`), mais le schéma est piloté **uniquement** par les migrations Laravel.

---

## 8. Points de vigilance connus

- MCP web exposé sans authentification (§6).
- Cashier codé mais dormant : ne pas présenter la facturation en ligne comme active tant que Stripe n'est pas configuré (§5.8).
- Legacy Inertia mort : ne pas modifier `resources/js/pages` (vide) ; toute évolution UI se fait dans `mibeko-front`.
- Deep links historiques `mibeko.cg` : domaine mort. Les liens canoniques et de partage pointent vers `mibeko.fr` (site public) et `app.mibeko.fr` (SPA), l'API répondant sur `api.mibeko.fr`.
