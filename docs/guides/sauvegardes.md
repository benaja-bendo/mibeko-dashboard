# Documentation des Sauvegardes (Laravel Backup)

Ce projet utilise le package `spatie/laravel-backup` pour automatiser la sauvegarde de la base de données et des fichiers.

## Fonctionnement

### 1. Planification
Les sauvegardes sont planifiées dans `routes/console.php` pour s'exécuter automatiquement :
- **Sauvegarde complète** : Tous les jours à **03h00**.
- **Nettoyage (Clean)** : Tous les jours à **04h00** (supprime les anciennes sauvegardes selon la politique de rétention).

### 2. Destinations
Les fichiers ZIP de sauvegarde sont envoyés vers deux destinations (configurées dans `config/backup.php`) :
- **Local** : Stocké dans `storage/app/private/Mibeko/`.
- **S3 (Cloud/Minio)** : Envoyer vers le bucket configuré dans le `.env`.
- **Google Drive (optionnel)** : Activable via `BACKUP_ENABLE_GDRIVE=true` et le disk `gdrive` (credentials dans le `.env`).

## Commandes Utiles

Vous pouvez lancer ces commandes manuellement depuis la racine du projet :

### Créer une sauvegarde manuellement
```bash
# Sauvegarde complète (base de données + fichiers inclus)
php artisan backup:run

# Sauvegarde de la base de données uniquement (plus rapide)
php artisan backup:run --only-db

# Sauvegarde vers un disk spécifique (ex: Google Drive)
php artisan backup:run --only-to-disk=gdrive

# Variante Mibeko (wrapper)
php artisan mibeko:backup --disk=gdrive --clean
```

### Vérifier l'état des sauvegardes
```bash
php artisan backup:list
```

### Nettoyer les anciennes sauvegardes
```bash
php artisan backup:clean
```

## Configuration Technique

- **Binary Path** : Pour macOS (avec Homebrew `libpq`), le chemin vers `pg_dump` est configuré dans `config/database.php` sous la clé `dump_binary_path` (`/opt/homebrew/bin`).
- **Notifications** : Si configurées, des notifications par mail sont envoyées en cas de succès ou d'échec (utilise `MAIL_TO_ADDRESS` dans le `.env`).
- **Google Drive** :
  - `GOOGLE_DRIVE_CLIENT_ID`
  - `GOOGLE_DRIVE_CLIENT_SECRET`
  - `GOOGLE_DRIVE_REFRESH_TOKEN`
  - `GOOGLE_DRIVE_FOLDER_ID`

## Restauration
Pour restaurer une sauvegarde :
1. Récupérez le fichier ZIP dans `storage/app/private/Mibeko/` ou sur votre stockage S3.
2. Extrayez le fichier `.sql`.
3. Importez-le dans votre base de données :
   ```bash
   psql -h 127.0.0.1 -U root -d laravel-mibeko < nom_du_fichier.sql
   ```
