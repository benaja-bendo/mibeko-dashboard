# Dossiers (Mobile) — cible API (remote-only)

## Objectif
Déplacer la gestion des dossiers (collections) du mobile vers l’API afin que :
- les dossiers soient retrouvés à chaque reconnexion / changement d’appareil,
- l’organisation soit cohérente entre mobile et back-office,
- la synchronisation soit simple (source de vérité = serveur).

Décisions retenues :
- un dossier peut contenir **des documents et des articles**,
- stratégie **remote-only** quand l’utilisateur est connecté.

## Modèle métier
### Dossier
Champs recommandés :
- `id` (UUID)
- `user_id` (UUID)
- `name` (string)
- `legal_domain` (string, optionnel)
- `tag` (enum: `EN_COURS|URGENT|ARCHIVE|FAVORIS`)
- `description` (string, optionnel)
- `color` (string, optionnel, ex: `#1565C0`)
- `created_at`, `updated_at` (ISO 8601)

### DossierItem
Un item représente une entrée dans un dossier.
Champs recommandés :
- `id` (bigint/autoincrement)
- `dossier_id` (UUID)
- `type` (enum: `article|document`)
- `item_id` (UUID) — id de l’article ou du document
- `note` (string, optionnel)
- `added_at` (ISO 8601)

## API (proposition)
Routes protégées par `auth:sanctum`.

### CRUD dossiers
- `GET /api/v1/dossiers`
  - retourne la liste des dossiers de l’utilisateur (avec `items_count`)
- `POST /api/v1/dossiers`
  - crée un dossier
- `GET /api/v1/dossiers/{id}`
  - retourne un dossier + items (paginables si besoin)
- `PUT /api/v1/dossiers/{id}`
  - met à jour un dossier
- `DELETE /api/v1/dossiers/{id}`
  - supprime un dossier (et ses items)

### Gestion des items
- `POST /api/v1/dossiers/{id}/items`
  - body: `{ "type": "article|document", "item_id": "<uuid>", "note": "..." }`
- `DELETE /api/v1/dossiers/{id}/items`
  - body: `{ "type": "...", "item_id": "<uuid>" }`
  - (pratique quand on ne veut pas exposer l’id technique de l’item)

### Dossier “Favoris”
Option recommandée : le dossier `FAVORIS` est **créé automatiquement** au premier login (ou à l’inscription), et le mobile l’utilise comme “Favoris” serveur.

## Règles côté mobile (KMP)
- Non connecté :
  - les “favoris” peuvent rester en local (optionnel) et être affichés comme “Favoris (local)”
- Connecté :
  - la liste des dossiers vient de l’API
  - l’action “Ajouter au dossier” affiche les dossiers serveur
  - aucune création/édition locale de dossiers (remote-only)

## Migration depuis l’existant
Si des dossiers existent déjà en local :
- proposer une action “Importer mes dossiers locaux” (one-shot) après connexion :
  - création des dossiers serveur,
  - push des items (articles/documents) vers chaque dossier,
  - puis suppression/archivage local.
