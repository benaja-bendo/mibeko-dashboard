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
* **Recherche** : Intégration de fonctionnalités de recherche avancée.

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

## 📂 Structure du Projet

* `app/Models` : Modèles Eloquent (LegalDocument, Article, Institution, etc.).
* `resources/js/pages` : Pages React (Inertia).
* `resources/js/components` : Composants UI réutilisables.
* `database/migrations` : Définitions de la structure de la base de données.

## 📄 Licence

Ce projet est sous licence [MIT](https://opensource.org/licenses/MIT).
