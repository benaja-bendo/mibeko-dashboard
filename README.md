# Mibeko Dashboard

Mibeko Dashboard est une plateforme de gestion et de curation de textes juridiques et réglementaires. Elle permet d'administrer une base de données de documents légaux (lois, décrets, arrêtés), leurs structures, ainsi que les institutions associées.

Le projet est conçu comme une application "Single Page" (SPA) moderne utilisant l'architecture monolithique de Laravel couplée à Inertia.js et React.

## 🛠 Stack Technique

Ce projet utilise les dernières technologies de l'écosystème Laravel et React :

* **Backend** : [Laravel 12](https://laravel.com)
* **Frontend** : [React 19](https://react.dev) avec [Inertia.js v2](https://inertiajs.com)
* **Style** : [Tailwind CSS v4](https://tailwindcss.com)
* **Base de données** : PostgreSQL (avec pgvector)
* **Stockage de fichiers** : MinIO (Compatible S3 local)
* **Authentification** : Laravel Fortify & Sanctum

## ✨ Fonctionnalités Clés

* **Gestion Documentaire** : CRUD complet pour les documents légaux, articles de loi et nœuds de structure.
* **Curation** : Interface dédiée pour la validation, le "flagging" et l'édition des contenus juridiques.
* **Institutions** : Gestion des entités émettrices des textes.
* **Audit** : Traçabilité des actions utilisateurs via `laravel-auditing`.
* **Sécurité** : Authentification complète avec support de l'authentification à deux facteurs (2FA).
* **Recherche** : Intégration de fonctionnalités de recherche avancée avec RAG (Mistral/OpenAI).
* **Notifications Push** : Envoi de notifications mobiles via Firebase Cloud Messaging (FCM).
* **Sauvegardes Automatisées** : Sauvegardes planifiées (DB & fichiers) via `spatie/laravel-backup` (voir [BACKUP.md](BACKUP.md)).
* **Monitoring** : Surveillance applicative intégrée avec Nightwatch.

## 🚀 Prérequis

Assurez-vous d'avoir installé les outils suivants sur votre machine :

* [Docker Desktop](https://www.docker.com/products/docker-desktop) (recommandé pour l'environnement Sail)
* PHP 8.2 ou supérieur (si exécution locale sans Docker)
* Node.js & NPM

## 📦 Installation

1.  **Cloner le dépôt**
    ```bash
    git clone [https://github.com/benaja-bendo/mibeko-dashboard.git](https://github.com/benaja-bendo/mibeko-dashboard.git)
    cd mibeko-dashboard
    ```

2.  **Installer les dépendances PHP**
    ```bash
    composer install
    ```

3.  **Configurer l'environnement**
    Copiez le fichier d'exemple et générez la clé d'application.
    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

4.  **Démarrer l'environnement Docker (Sail)**
    Cette commande lance les conteneurs pour l'application, PostgreSQL et MinIO.
    ```bash
    ./vendor/bin/sail up -d
    ```
    *(Note : Vous pouvez créer un alias pour `sail` pour simplifier les commandes suivantes)*
    ```bash
    php artisan serve --host=0.0.0.0 --port=8000
    php artisan db:seed --class=RealisticLegalSeeder
    ```

5.  **Installer les dépendances JavaScript**
    ```bash
    ./vendor/bin/sail npm install
    ./vendor/bin/sail npm run build
    ```

6.  **Base de données & Migration**
    Exécutez les migrations et les seeders pour initialiser la base de données.
    ```bash
    ./vendor/bin/sail artisan migrate --seed
    ```

## 🧠 RAG & génération des embeddings

Par défaut, le seeding ne fait **aucun appel à l'API d'IA** : les seeders remplissent la base (`articles`, `article_versions`, etc.) sans générer d'embeddings. Cela permet d'initialiser la base de données sans coût externe.

### 1. Peupler la base sans IA

Vous pouvez réinitialiser et peupler la base comme d'habitude :

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

ou, pour lancer un seeder spécifique (ex. données réalistes) :

```bash
./vendor/bin/sail artisan db:seed --class=RealisticLegalSeeder
```

Dans tous les cas, les embeddings ne seront **pas** générés pendant ces seeders.

### 2. Générer les embeddings (RAG) plus tard

Une fois la base peuplée, vous pouvez lancer la génération des embeddings manquants via une commande dédiée :

```bash
./vendor/bin/sail artisan mibeko:process-rag \
    --limit=200 \
    --batch=20 \
    --delay=500
```

- `--limit` : nombre maximum d'articles à traiter lors de cet appel
- `--batch` : taille des lots envoyés à l'API d'IA
- `--delay` : délai **en millisecondes** entre chaque batch pour éviter le rate limit

Vous pouvez relancer la commande plusieurs fois (par exemple avec un `--limit` plus élevé) : seuls les articles **sans embedding** seront pris en compte.

### 3. Tester le RAG sur un petit échantillon (optionnel)

Pour vérifier la configuration IA sur un faible volume, un seeder de test est disponible :

```bash
./vendor/bin/sail artisan db:seed --class=TestEmbeddingSeeder
```

Ce seeder ne traite qu'un nombre limité de documents, ce qui permet de valider les embeddings et le RAG avant de lancer un traitement complet.

## ⚙️ Configuration MinIO (Stockage Local)

Le projet utilise MinIO pour simuler un stockage S3 en local. Le fichier `docker-compose.yml` inclut un service `createbuckets` qui configure automatiquement le bucket par défaut.

* **Console MinIO** : [http://localhost:9001](http://localhost:9001)
* **User** : `sail`
* **Password** : `password`

## 🧪 Tests

Pour exécuter la suite de tests (Pest PHP) :

```bash
./vendor/bin/sail artisan test

```

Pour lancer l'analyse statique et le formatage du code :

```bash
./vendor/bin/sail npm run lint    # ESLint
./vendor/bin/sail npm run format  # Prettier
./vendor/bin/pint                 # Laravel Pint

```

## 🌍 Déploiement en Production

Le projet est configuré pour un déploiement automatisé et conteneurisé sur un VPS.

### 🏗 Architecture Docker
L'application en production utilise une image Docker optimisée (multi-stage build) construite via le `Dockerfile` à la racine, qui inclut :
- La compilation du frontend (Vite/React).
- L'installation des dépendances backend (Composer).
- L'environnement d'exécution basé sur PHP 8.2 FPM (avec OPcache et extensions nécessaires).

L'orchestration s'effectue via le fichier `docker-compose.prod.yml` composé de 4 services :
- `app` : Serveur PHP-FPM.
- `nginx` : Serveur web (reverse proxy).
- `queue` : Worker pour les files d'attente (background jobs).
- `scheduler` : Planificateur de tâches (cron).

Ces services communiquent avec les instances **PostgreSQL** et **MinIO** hébergées directement sur le VPS (via un réseau Docker `proxy`). Le trafic entrant (HTTPS) est géré par **Traefik** (domaine `app.mibeko.benaja-bendo.fr`).

### 🚀 CI/CD avec GitHub Actions
Un workflow de déploiement continu (`deploy-prod.yml`) se déclenche automatiquement lors d'un push sur la branche `main` :
1. **Build & Push** : Construction de l'image Docker et publication sur GitHub Container Registry (GHCR).
2. **Déploiement VPS (SSH)** : 
   - Connexion au serveur de production.
   - Génération dynamique de la configuration (`.env`, `docker-compose.yml`, config Nginx) à l'aide des GitHub Secrets.
   - Téléchargement (pull) de la nouvelle image et redémarrage des conteneurs sans interruption (`docker compose up -d`).
   - Exécution des commandes d'optimisation Laravel (migrations, cache des routes/vues/configs).

### 🔑 Variables d'environnement
Le fichier `.env.vps` sert de référence pour les variables requises en production, notamment les identifiants de la base de données, les accès S3 (MinIO), les clés d'API (Mistral/OpenAI) et les credentials Firebase pour les notifications Push.

## 📂 Structure du Projet

* `app/Models` : Modèles Eloquent (LegalDocument, Article, Institution, etc.).
* `resources/js/pages` : Pages React (Inertia).
* `resources/js/components` : Composants UI réutilisables.
* `database/migrations` : Définitions de la structure de la base de données.

## 📄 Licence

Ce projet est sous licence [MIT](https://opensource.org/licenses/MIT).
