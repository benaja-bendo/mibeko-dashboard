# Tester les notifications push

> Statut : à jour au 1er août 2026 · Recette de test de `mibeko:test-push` et contrat de données lu par l'app mobile.

## Envoyer une notification de test

```bash
php artisan mibeko:test-push <FCM_TOKEN> \
  --title="Nouveau décret" \
  --message="Le décret 2026-01 est disponible." \
  --slug=<SLUG_DU_TEXTE> \
  --article_id=<NUMERO_ARTICLE>
```

Trois pièges qui font perdre du temps :

- **Le token est toujours un token FCM**, y compris pour un iPhone : Firebase Messaging encapsule APNs. Passer un token APNs brut échoue.
- **`--article_id` est un *numéro* d'article** (« 45 »), pas un identifiant technique — et il n'a de sens qu'avec `--slug`, qui porte la cible du deep link `mibeko://textes/{slug}`. Seul, il n'ouvre rien.
- **Sans appareil enregistré, il n'y a rien à cibler.** La table `devices` se remplit quand l'app s'enregistre au démarrage ; sur une base fraîche elle est vide. Vérifier avant de chercher un bug ailleurs :

  ```bash
  php artisan tinker --execute="echo \App\Models\Device::count();"
  ```

## Contrat de données

Les clés du `data` de la notification suivent ce que lit `MyFirebaseMessagingService` côté app : `slug` (obligatoire pour ouvrir un texte) et `article` (optionnel, pour se positionner sur un article). Toute évolution de ces clés doit être faite des deux côtés en même temps — le résolveur de liens mobile est le consommateur.

## État côté iOS

La réception de push n'est pas encore implémentée sur iOS : ni SDK Firebase Messaging, ni entitlement `aps-environment` dans `iosApp/`. Un envoi vers un appareil iOS n'aboutira donc pas, indépendamment de la commande. Chantier planifié — voir `docs/produit/plan-amelioration-app-mobile.md` dans le dépôt `docs/`.
