# Mibeko — Backend & API (`mibeko-tableau-de-bord`)

> Statut : à jour au 2 juillet 2026 · API Laravel de l'écosystème Mibeko (droit du Congo-Brazzaville) servant le dashboard React, l'application mobile et le site public.

Ce dépôt est le **backend** de Mibeko : une API Laravel 13 qui centralise la base de données juridique (lois, décrets, arrêtés, actes uniformes OHADA, journaux officiels), l'authentification, l'assistant IA et la facturation. Il est l'unique source de vérité de l'écosystème.

Il **ne rend plus d'interface** : le legacy Inertia est quasi mort (`resources/js/pages` est vide). L'interface réelle est le SPA React séparé **`mibeko-front`** (dashboard pro `/app`, éditorial `/editor`, admin `/admin`). Le backend expose une API JSON `/api/v1`, des pages de partage social, une page de statut et la documentation Scramble.

La documentation technique détaillée se trouve dans [`docs/`](docs/README.md).

## Stack technique

- **Framework** : [Laravel 13](https://laravel.com) (PHP ≥ 8.2 ; l'image de production tourne sous PHP 8.4).
- **Base de données** : PostgreSQL avec `pgvector` (recherche sémantique), `ltree` (arborescence) et `btree_gist` (contraintes temporelles).
- **Authentification** : [Laravel Sanctum](https://laravel.com/docs/sanctum) (jetons API) + [Fortify](https://laravel.com/docs/fortify) (2FA TOTP au login). Rôles via `spatie/laravel-permission`.
- **Stockage de fichiers** : MinIO (compatible S3) pour les PDF sources.
- **Assistant IA & MCP** : `laravel/ai` (assistant conversationnel, détection d'anomalies, embeddings) et `laravel/mcp` (serveur MCP exposant la base juridique).
- **Facturation** : `laravel/cashier` (Stripe) — **codée mais dormante en production** (Stripe non configuré ; les endpoints se dégradent proprement).
- **Temps réel** : [Laravel Reverb](https://laravel.com/docs/reverb) (WebSocket).
- **Documentation API** : `dedoc/scramble` (`/docs/api`).
- **Tests** : [Pest](https://pestphp.com) — environ 365 tests exécutés contre une base PostgreSQL réelle.

Les clients servis par cette API sont : le SPA `mibeko-front`, l'application mobile `mibeko-app-kmp` (Kotlin Multiplatform) et le site public `mibeko-site` (Astro).

## Fonctionnalités clés

- **Gestion documentaire** : CRUD des documents légaux, articles, versions (SCD Type 2) et nœuds de structure.
- **Curation** : détection d'anomalies, signalements (`curation_flags`), garde-fou à la publication.
- **Recherche hybride** : lexicale (`tsvector`) + trigram (`pg_trgm`) + sémantique (`pgvector`).
- **Assistant IA** : conversations, IA à la demande (explication / synthèse en streaming SSE), suggestion de thèmes.
- **Synchronisation mobile** : catalogue « offline-ready », téléchargement à plat, synchronisation des dossiers.
- **Audit** : traçabilité via `owen-it/laravel-auditing`.
- **Notifications push** : Firebase Cloud Messaging (FCM).
- **Sauvegardes automatisées** : `spatie/laravel-backup` (voir [`docs/guides/sauvegardes.md`](docs/guides/sauvegardes.md)).

## Prérequis

- [Docker Desktop](https://www.docker.com/products/docker-desktop) (recommandé, via Laravel Sail).
- PHP 8.2 ou supérieur (si exécution locale sans Docker).
- Node.js & NPM (uniquement pour les outils de build et le linting front résiduels).

## Installation

1. **Cloner le dépôt**
   ```bash
   git clone https://github.com/benaja-bendo/mibeko-dashboard.git
   cd mibeko-dashboard
   ```

2. **Installer les dépendances PHP**
   ```bash
   composer install
   ```

3. **Configurer l'environnement**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Démarrer l'environnement Docker (Sail)**
   Cette commande lance les conteneurs pour l'application, PostgreSQL et MinIO.
   ```bash
   ./vendor/bin/sail up -d
   ```

5. **Base de données & migrations**
   ```bash
   ./vendor/bin/sail artisan migrate --seed
   ```

## RAG & génération des embeddings

Par défaut, le seeding ne fait **aucun appel à l'API d'IA** : les seeders remplissent la base (`articles`, `article_versions`, etc.) sans générer d'embeddings, ce qui permet d'initialiser la base sans coût externe.

Une fois la base peuplée, la génération des embeddings manquants se lance via une commande dédiée :

```bash
./vendor/bin/sail artisan mibeko:process-rag --limit=200 --batch=20 --delay=500
```

- `--limit` : nombre maximum d'articles traités lors de l'appel.
- `--batch` : taille des lots envoyés à l'API d'IA.
- `--delay` : délai (en millisecondes) entre chaque lot pour éviter le rate limit.

La commande est réentrante : seuls les articles **sans embedding** sont traités, on peut donc la relancer avec un `--limit` plus élevé.

## Configuration MinIO (stockage local)

MinIO simule un stockage S3 en local. Le `docker-compose.yml` inclut un service qui configure automatiquement le bucket par défaut.

- **Console** : [http://localhost:9001](http://localhost:9001)
- **Identifiants par défaut** : `sail` / `password`

La convention d'organisation des objets est décrite dans [`docs/architecture/stockage-minio.md`](docs/architecture/stockage-minio.md).

## Tests

La suite Pest s'exécute contre une **base PostgreSQL réelle** (`mibeko_testing`, cf. `phpunit.xml`) et non SQLite : elle couvre `ltree`, `pgvector` et les contraintes `gist`. Voir [`docs/guides/base-de-donnees-test.md`](docs/guides/base-de-donnees-test.md) pour la préparation de cette base.

```bash
./vendor/bin/sail artisan test --compact
```

Analyse statique et formatage :

```bash
./vendor/bin/pint          # Formatage PHP (Laravel Pint)
```

## Déploiement en production

Le projet est déployé de façon automatisée et conteneurisée sur un VPS.

### Architecture Docker

L'image de production est construite via le `Dockerfile` racine (multi-stage, base **PHP 8.4-fpm** avec OPcache). L'orchestration passe par `.deploy/docker-compose.yml` (copié sur le VPS par la CI), composé de cinq services :

- `app` : serveur PHP-FPM.
- `nginx` : serveur web (reverse proxy).
- `queue` : worker des files d'attente.
- `scheduler` : planificateur de tâches (cron).
- `reverb` : serveur WebSocket (temps réel).

Ces services dialoguent avec les instances **PostgreSQL** et **MinIO** hébergées sur le VPS. Le trafic HTTPS est géré par **Traefik** : l'API répond sur `api.mibeko.fr` et les WebSockets sur `reverb.mibeko.fr`. Le SPA `mibeko-front` est servi sur `app.mibeko.fr` et le site public sur `mibeko.fr`.

### CI/CD (GitHub Actions)

Le workflow `deploy-prod.yml` se déclenche sur push vers `main` :

1. **Build & push** : construction de l'image Docker et publication sur GitHub Container Registry (GHCR).
2. **Déploiement VPS (SSH)** : génération dynamique de la configuration à partir des GitHub Secrets, pull de la nouvelle image, redémarrage des conteneurs, puis migrations et mise en cache Laravel (routes/vues/config).

Le fichier `.env.vps` sert de référence pour les variables requises en production (base de données, accès MinIO, clés IA, credentials Firebase).

## Structure du projet

- `app/Http/Controllers/Api/V1` : contrôleurs de l'API versionnée.
- `app/Models` : modèles Eloquent (`LegalDocument`, `Article`, `ArticleVersion`, `Institution`, etc.).
- `app/Ai` : assistant IA, agents et outils (`laravel/ai`).
- `app/Mcp` : serveur et outils MCP (`laravel/mcp`).
- `routes/api.php` : définition des endpoints `/api/v1`.
- `database/migrations` : structure de la base (pilote unique du schéma partagé avec le service Python).
- `docs/` : documentation technique ([index](docs/README.md)).

> Note : `resources/js/pages` est vide. L'interface utilisateur vit dans le dépôt séparé `mibeko-front` ; ne pas rétablir de pages Inertia ici.

## Licence

Ce projet est sous licence [MIT](https://opensource.org/licenses/MIT).
