# Documentation du backend Mibeko

> Statut : à jour au 2 juillet 2026 · Index de la documentation technique du dépôt `mibeko-tableau-de-bord` (API Laravel).

Ce dossier regroupe la documentation d'architecture, les guides opérationnels et les archives historiques du backend Mibeko (API Laravel 13 servant le SPA React, l'application mobile et le site public).

## Architecture

Documents décrivant la conception du système, le modèle de données et les flux de traitement.

| Document | Description |
| :--- | :--- |
| [architecture/prd-backend.md](architecture/prd-backend.md) | Spécification produit de l'API : périmètre, modèle de données, conventions REST, authentification et périmètre fonctionnel des endpoints. |
| [architecture/pipeline-extraction.md](architecture/pipeline-extraction.md) | Cycle de vie d'un document juridique, de sa création dans le back-office jusqu'à l'extraction PDF (service Python), l'import structuré et la génération des embeddings. |
| [architecture/stockage-minio.md](architecture/stockage-minio.md) | Convention d'organisation des objets dans le stockage MinIO (compatible S3) : arborescence, traçabilité et cycle de vie des fichiers. |

## Guides

Procédures opérationnelles, mises en œuvre techniques et bonnes pratiques.

| Document | Description |
| :--- | :--- |
| [guides/temps-reel-reverb.md](guides/temps-reel-reverb.md) | Mise en place et exploitation du temps réel via Laravel Reverb (WebSocket, canaux de diffusion). |
| [guides/themes-de-vie.md](guides/themes-de-vie.md) | Taxonomie éditoriale des « thèmes de vie » : modèle, assignation aux documents et exposition dans la bibliothèque. |
| [guides/tests-api-curl.md](guides/tests-api-curl.md) | Recettes `curl` pour tester manuellement les endpoints de l'API v1 (authentification, catalogue, recherche, etc.). |
| [guides/tests-assistant-ia.md](guides/tests-assistant-ia.md) | Procédures de test de l'assistant IA (conversations, streaming, références). |
| [guides/base-de-donnees-test.md](guides/base-de-donnees-test.md) | Préparation et utilisation de la base PostgreSQL de test (`mibeko_testing`) pour la suite Pest. |
| [guides/bonnes-pratiques-production.md](guides/bonnes-pratiques-production.md) | Recommandations d'exploitation en production (déploiement, cache, files d'attente, sécurité). |
| [guides/sauvegardes.md](guides/sauvegardes.md) | Configuration et supervision des sauvegardes automatisées (`spatie/laravel-backup`). |

## Archives

Le dossier [_archive/](_archive/) contient des documents obsolètes conservés à titre d'historique. Leur contenu peut ne plus refléter l'état réel du code et ne doit pas servir de référence.

| Document | Description |
| :--- | :--- |
| [_archive/dossiers-mobile-proposition.md](_archive/dossiers-mobile-proposition.md) | Ancienne proposition de conception des « dossiers » côté mobile, antérieure à l'implémentation actuelle. |
| [_archive/vision-produit-globale.md](_archive/vision-produit-globale.md) | Note de vision produit générale, remplacée par la documentation d'architecture à jour. |

## Conventions

- Chaque document commence par un titre H1 puis une ligne « Statut : à jour au \<date\> · \<portée\> ». Cette date indique la dernière vérification du contenu face au code réel.
- La documentation est datée et évolutive : elle décrit l'état du dépôt à un instant donné et doit être corrigée dès qu'une divergence avec le code est constatée.
- En cas de doute entre un document et le code, le code fait foi. Les détails techniques précis (versions, ports, noms de commandes) doivent être vérifiés dans les fichiers de configuration réels.
