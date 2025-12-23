# Configuration de la Base de Données de Test (PostgreSQL + pgvector)

Ce projet utilise des fonctionnalités spécifiques à PostgreSQL (types `tsvector` pour la recherche, `daterange`, et l'extension `vector` pour l'IA). Par conséquent, les tests ne peuvent pas tourner sur SQLite et nécessitent une instance PostgreSQL dédiée.

## Prérequis

Assurez-vous que votre conteneur Docker PostgreSQL tourne :

```bash
docker ps
# Vous devriez voir le conteneur 'postgres-mibeko'
```

## Installation

### 1. Création de la base de données de test

Il faut créer une base de données séparée pour éviter d'écraser vos données de développement lors des tests.

Exécutez cette commande dans votre terminal :

```bash
docker exec -it postgres-mibeko createdb -U root mibeko_testing
```

> **Note :** Les identifiants sont configurés sur `root` / `root` dans le fichier `.env` et `phpunit.xml`.

### 2. Activation de l'extension `vector`

Pour que les fonctionnalités d'IA fonctionnent lors des tests, l'extension `vector` doit être activée sur cette nouvelle base.

```bash
docker exec -it postgres-mibeko psql -U root -d mibeko_testing -c "CREATE EXTENSION IF NOT EXISTS vector;"
```

### 3. Configuration de PHPUnit

Le fichier `phpunit.xml` à la racine du projet configure Laravel pour utiliser cette base lors des tests.

La section `<php>` doit contenir les variables suivantes (déjà configurées) :

```xml
<php>
    <!-- ... -->
    <env name="DB_CONNECTION" value="pgsql" />
    <env name="DB_DATABASE" value="mibeko_testing" />
    <env name="DB_USERNAME" value="root" />
    <env name="DB_PASSWORD" value="root" />
    <!-- ... -->
</php>
```

## Lancer les tests

Une fois la configuration terminée, vous pouvez lancer les tests normalement. Laravel se chargera de vider la base `mibeko_testing` et de jouer les migrations automatiquement à chaque lancement.

```bash
php artisan test
```
