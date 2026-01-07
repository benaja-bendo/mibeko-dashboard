# Product Requirements Document (PRD) – Backend & API

**Projet :** Mibeko Backend (API + Back-office)  
**Version :** 1.0 (MVP)  
**Stack Technique :** Laravel 12, PostgreSQL 16 (extensions: `ltree`, `vector`, `btree_gist`), Spatie Laravel Query Builder, Docker, MinIO (S3 compatible), Scramble, Pest.  
**Client :** Application Mobile Mibeko (Kotlin Multiplatform).  
**Versioning API :** `/api/v1`
**Dernière mise à jour :** 04-01-2026

---

## 1. Vision & Objectifs Techniques

Le Backend Mibeko agit comme l'orchestrateur de la complexité juridique. Il gère la structure (arborescence), le temps (validité des lois) et le sens (recherche vectorielle), pour servir des "paquets" de données optimisés pour l'usage mobile.

### Objectifs Clés
- **Distribution "Offline-Ready"** : Générer des payloads JSON optimisés pour une insertion massive dans SQLite/Room côté mobile.
- **Intégrité Temporelle** : Garantir qu'un article abrogé n'est jamais servi comme "en vigueur" (gestion stricte des `daterange`).
- **Performance Hybride** : Combiner la précision des mots-clés (Postgres `tsvector`) avec la compréhension sémantique (`pgvector`).

---

## 2. Architecture des Données (Schéma & Modèles)

### 2.1 Modèles Principaux (Eloquent)

| Modèle Laravel | Table SQL | Rôle | Spécificité Technique |
| :--- | :--- | :--- | :--- |
| **LegalDocument** | `legal_documents` | Racine (Code, Loi) | Métadonnées globales (Titre, Date, Type). |
| **StructureNode** | `structure_nodes` | Squelette (Livre > Titre) | Extension **Ltree** (`path`) pour récursion performante. |
| **Article** | `articles` | Entité logique | Lien immuable ("Article 45"). Conserve son ID à travers les versions. |
| **ArticleVersion** | `article_versions` | Contenu (Le texte) | Utilise `tstzrange` (`validity_period`) et `vector` (`embedding`). |
| **Media** | `media` | Sources PDF | Liaison via Spatie MediaLibrary ou table custom pointant vers MinIO. |

### 2.2 Stratégie de Versionnement (SCD Type 2)
Le backend gère le cycle de vie via une logique de "Close and Insert" :
- **Modification** : On met à jour la version précédente pour fermer son `validity_period` (ex: `upper(validity_period) = NOW()`).
- **Nouvelle version** : On insère la nouvelle ligne avec `validity_period = [NOW(), infinity)`.
- **Contrainte SQL** : Utilisation de `EXCLUDE USING gist` pour empêcher tout chevauchement temporel pour un même article.

---

## 3. Standards de Communication API

### 3.1 Versioning Pro
Toutes les routes API doivent être préfixées par `/api/v1`.  
Les contrôleurs et ressources sont organisés dans des dossiers `V1` respectifs pour permettre des évolutions futures sans rupture (ex: `/api/v2`).

### 3.2 Format de Réponse (L'enveloppe)
Toutes les réponses de l'API doivent suivre une structure unifiée via le trait `HttpResponses`.

#### Succès (200 OK, 201 Created)
```json
{
  "success": true,
  "message": "Opération réussie",
  "data": { ... } // Objet ou Tableau
}
```

#### Succès Paginé (200 OK)
Pour les listes de résultats paginées.
```json
{
  "success": true,
  "message": "Opération réussie",
  "data": [ ... ],
  "pagination": {
    "total": 150,
    "per_page": 20,
    "current_page": 1,
    "last_page": 8
  }
}
```

#### Erreur (4xx, 5xx)
```json
{
  "success": false,
  "message": "Description de l'erreur",
  "errors": { ... } // Détails techniques ou erreurs de validation
}
```

### 3.3 Utilisation des API Resources
L'API ne doit **jamais** retourner directement un modèle Eloquent.  
Utilisez systématiquement les `AnonymousResourceCollection` ou les classes `Resource` natives de Laravel.
- **Transformation** : Centralisez la logique de présentation (formatage des dates, renommage de champs).
- **Sécurité** : Évitez l'exposition accidentelle de champs sensibles (`password`, etc.).
- **Performance** : Utilisez les `AnonymousResourceCollection` pour éviter les requêtes N+1.

### 3.4 Filtrage & Recherche (Laravel Query Builder)
Pour les endpoints de liste (`index`), utilisez `spatie/laravel-query-builder` pour offrir une API flexible :
- **Filtres** : `/api/v1/resource?filter[nom]=valeur`
- **Tris** : `/api/v1/resource?sort=-created_at`
- [x] Inclusions : `/api/v1/resource?include=relations`

### 3.5 Documentation Automatisée (Scramble)
L'API doit être documentée via `dedoc/scramble`.
- **Endpoint** : `/docs/api`
- **Règles** : Utiliser des DocBlocks complets sur les méthodes de contrôleur, les FormRequests et les Resources pour générer une spec OpenAPI 3 parfaite.

---

## 4. Périmètre Fonctionnel de l'API (Endpoints)

Convention : Réponses JSON, dates au format ISO 8601 (UTC).

### BE1 – Catalogue & Synchronisation
**Endpoint :** `GET /api/v1/catalog`  
**Objectif :** Permettre au mobile de comparer ses versions locales avec le serveur. Inclut une section "Global" pour les données essentielles à mettre à jour au démarrage.

```json
{
  "global_update_required": true,
  "last_essential_sync": "2026-01-05T08:00:00Z",
  "resources": [
    {
      "id": "uuid-v4",
      "title": "Code de la Famille",
      "type": "CODE",
      "version_hash": "a1b2c3d4",
      "last_updated": "2026-01-04T10:00:00Z",
      "download_size_kb": 450
    }
  ]
}
```

### BE2 – Téléchargement Sélectif & Bulk
**Endpoint :** `GET /api/v1/resources/{id}/download`  
**Paramètres optionnels :** `?node_id={uuid}` (pour télécharger un bloc spécifique).
**Stratégie :** **Flat List (Liste plate)**.
- **Pourquoi ?** Streaming SQL direct et Bulk Insert simplifié côté mobile.
- **Logique de Sélection :** 
  - Sans `node_id` : Télécharge tout le document.
  - Avec `node_id` : Télécharge le nœud sélectionné et tous ses descendants récursivement via le `path` Ltree.
- **Gestion du Nettoyage :** Inclure une clé `deleted_ids` (ou un flag `status: archived`) pour permettre au mobile de supprimer les articles obsolètes de sa base de données locale (Room).

```json
{
  "resource_id": "uuid-v4",
  "node_id": "uuid-parent-optional",
  "generated_at": "2026-01-04T10:00:00Z",
  "nodes": [ ... ],
  "articles": [ ... ]
}
```

### BE4 – Sources PDF (Storage)
**Endpoint :** `GET /api/v1/resources/{id}/pdf`  
**Logic :** Utilise `league/flysystem-aws-s3-v3` pour rediriger vers ou streamer le fichier "source" original (PDF scanné) depuis MinIO.

### BE5 – Export PDF Dynamique
**Endpoint :** `POST /api/v1/resources/export`  
**Payload :** `{ "resource_id": "uuid", "node_ids": ["uuid1", "uuid2"] }`
**Logic :** 
- **Queued Processing** : Pour les documents volumineux, la génération est effectuée en arrière-plan (Queue) pour éviter les timeouts HTTP. L'API retourne un `job_id` ou une URL de statut.
- Utilise `barryvdh/laravel-dompdf` pour générer un PDF propre et léger à partir d'une vue Blade.
- Branding Mibeko inclus (Logo, Filigranes).
- Retours : Stream direct ou URL temporaire signée (S3).

### BE6 – Deep Links (Navigation)
**Base URL :** `https://mibeko.cg/`
**Configuration Android :** `GET /.well-known/assetlinks.json` (Fichier de configuration obligatoire pour les **Android App Links** afin de garantir une ouverture directe dans l'app sans sélecteur).
**Structure :** `https://mibeko.cg/loi/{slug-resource}/{article-ref}`
**Logic :** Le backend doit être capable de résoudre ces liens et de rediriger soit vers l'application mobile (via Android Intents / iOS Universal Links), soit vers une page web de consultation simplifiée.

### BE3 – Recherche Unifiée (Hybride)
**Endpoint :** `GET /api/v1/search?q={query}`  
**Paramètres :** `?q={query}&document_id={uuid}` (facultatif, pour restreindre la recherche à un Code spécifique).
**Logique Algorithmique :**
1. **Scope Filtering** : Si `document_id` est fourni, filtrer les versions d'articles appartenant à ce document avant tout calcul.
2. **Embedding** : Appel OpenAI (`text-embedding-3-small`) pour vectoriser la requête.
3. **Scoring SQL** :
   - **Score A** : `ts_rank` (Full-text search sur `search_tsv`).
   - **Score B** : `cosine_distance` (Recherche sémantique via `pgvector`).
3. **Pondération** : `(Score A * 0.7) + (Score B * 0.3)`.

---

<!-- ## 4. Back-office

L'administration permet aux juristes de maintenir le corpus.
- **BO1 – Gestion Hiérarchique** : Visualisation en arbre. Au déplacement (Drag & Drop), recalcul automatique des `path` (Ltree) en transaction.
- **BO2 – Édition & Publication** : Workflow de révision. Modification = création de nouvelle version.
- **BO3 – Job d'Embedding** : Dispatch asynchrone pour mettre à jour les vecteurs via OpenAI après chaque modification. -->

---

## 5. Exigences Non Fonctionnelles (NFR)

- **Sécurité** : `X-API-KEY` obligatoire pour l'application mobile.
- **Performance** : Indexation GINST (tsv) et HNSW (vector). Cache Redis.
- **Poids** : Compression Gzip active.
- **Qualité (Testing)** : Utilisation de **Pest**. 
  - Coverage cible : 90% sur la logique de synchronisation et les API resources. 
  - Tests de Smoke sur les endpoints `/catalog` et `/download`.
  - Simulation S3 via MinIO local pour les tests de PDF.

---

## 6. Réponses aux Questions Ouvertes

1. **Vecteurs & Embeddings ?** Oui, via OpenAI `text-embedding-3-small`. Job asynchrone lors de l'enregistrement.
2. **Format de Téléchargement ?** **FLAT LIST**. Le mobile reconstruit l'UI hiérarchique à partir des `parent_id` et `path`.
3. **Pgvector vs Elasticsearch ?** Pgvector au sein de Postgres 16 pour réduire la complexité infra et garantir une atomicité parfaite entre texte et vecteurs.