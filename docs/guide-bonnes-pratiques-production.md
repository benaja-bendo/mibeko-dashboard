# 🛡️ Guide des Bonnes Pratiques & Check-list de Production (Mibeko)

Ce document sert de **checkpoint** pour faire évoluer l'application Mibeko sereinement, sans risquer de casser la production, de perdre des données ou d'introduire des régressions.

***

## 1. 💾 Base de Données & Migrations (Règle d'Or : Ne rien détruire)

Une fois en production, la base de données contient des données réelles (utilisateurs, dossiers, extractions IA). **Il est strictement interdit de supprimer ou renommer des colonnes directement.**

### ❌ À ne jamais faire dans une migration :

- `Schema::dropIfExists('table_active');`
- `$table->dropColumn('ancienne_colonne');`
- `$table->renameColumn('ancien_nom', 'nouveau_nom');`

### ✅ Comment modifier une structure (Pattern "Expand & Contract") :

Si tu dois remplacer la colonne `titre` par `titre_complet` :

1. **Déploiement 1 (Expand) :** Crée la nouvelle colonne `titre_complet` (nullable). Modifie le code pour écrire dans les DEUX colonnes, mais lire depuis l'ancienne.
2. **Déploiement 2 (Migrate) :** Lance une commande (ou un script) pour copier toutes les données de `titre` vers `titre_complet`.
3. **Déploiement 3 (Contract) :** Modifie le code pour lire et écrire uniquement sur `titre_complet`.
4. **Déploiement 4 (Clean) :** Des mois plus tard, supprime l'ancienne colonne `titre`.

***

## 2. 🚀 Check-list avant de fusionner sur `main` (Déploiement)

Avant de déclencher le GitHub Action de production, vérifie ces points :

- [ ] **Migrations Safe :** Mes nouvelles migrations modifient-elles des données existantes ? (Si oui, voir point 1).
- [ ] **Variables d'Environnement :** Ai-je ajouté de nouvelles variables dans `.env.example` ? Si oui, **je dois les ajouter dans les Secrets GitHub** et dans le fichier `.github/workflows/deploy-prod.yml`.
- [ ] **Tests Locaux :** J'ai lancé `php artisan test` et tout est au vert.
- [ ] **Jobs Asynchrones :** Les tâches lourdes (PDF, requêtes LLM) sont-elles bien dispatchées (RabbitMQ / Queues) et non synchrones dans les requêtes HTTP ?
- [ ] **Pas de fichiers locaux :** Ai-je utilisé `Storage::disk('s3')` (MinIO) au lieu de `disk('local')` ou `disk('public')` pour les nouveaux uploads ?

***

## 3. 🔙 Procédure de Rollback (En cas de crash)

Si un déploiement casse le VPS, pas de panique.
Ton workflow GitHub Actions tague les images Docker avec le SHA du commit (`ghcr.io/...:sha-123456`).

1. Connecte-toi en SSH au VPS.
2. Va dans `/opt/docker/mibeko-app`.
3. Édite le `docker-compose.yml` et remplace le tag `latest` par le tag du commit précédent qui fonctionnait.
4. Lance `docker compose up -d`.
5. Si des migrations ont cassé l'app, restaure la base de données depuis le backup.

***

## 4. 🔒 Backups & Sécurité

- **Vérification S3 Externe :** Le package `spatie/laravel-backup` tourne toutes les nuits. Vérifie dans ton `.env` de production que `FILESYSTEM_DISK=s3` pour les backups pointe bien vers un S3 externe (ex: AWS, Scaleway, R2) et **non vers le MinIO local**. (Si le VPS brûle, le MinIO brûle avec).
- **Test de Restauration :** Une sauvegarde ne vaut rien si elle n'a jamais été testée. Une fois par trimestre, télécharge le fichier zip de backup en local, et essaie de remonter la base de données PostgreSQL (`pg_restore`).

***

## 5. 📈 Préparation au Scale (Évolutivité)

L'application Mibeko est bien conçue (`SESSION_DRIVER=database`), ce qui signifie qu'elle est *stateless*. Pour garder cette évolutivité :

- Ne stocke **jamais** de données de session en mémoire ou sur le disque local (`SESSION_DRIVER=file` est interdit).
- Ne stocke **jamais** de fichiers (PDF, images) dans le dossier `public/` ou `storage/app/public/`. Toujours sur le S3 (MinIO).
- Garde l'extraction d'embeddings `pgvector` et la vectorisation de texte dans des Jobs asynchrones (RabbitMQ). Le serveur Web (Nginx/PHP-FPM) ne doit servir qu'à répondre vite aux requêtes HTTP/SSE.

