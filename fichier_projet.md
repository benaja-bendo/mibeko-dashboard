# 📄 MIBEKO - Documentation Globale & Stratégie Projet

## 1. Vision & Positionnement

Mibeko est la plateforme LegalTech de référence pour la République du Congo (Brazzaville). Elle centralise, structure et rend intelligible le corpus juridique national, devenant l'outil indispensable des professionnels du droit et l'allié des citoyens.

> \[!IMPORTANT]
> **Promesse :** Le droit congolais, clair, sourcé et accessible partout, même sans connexion.

***

## 2. Personas & Expérience Utilisateur

### ⚖️ L'Expert (Avocats, Magistrats, Étudiants)

- **Objectif :** Trouver une référence juridique précise et fiable pour étayer un dossier.
- **Parcours Type :** Navigation hiérarchique profonde (Livre > Titre > Chapitre), recherche hybride (mots-clés + sémantique), mise en favoris d'articles spécifiques dans des dossiers de travail.

### 🧑‍🤝‍🧑 Le Citoyen (Grand Public)

- **Objectif :** Comprendre ses droits face à une situation quotidienne (contrat de travail, litige immobilier).
- **Parcours Type :** Interroge l'Assistant IA en langage naturel ("Quels sont mes droits si..."), sauvegarde la réponse et la partage à ses proches via un lien direct.

### 🛡️ L'Administrateur / Modérateur (Back-Office)

- **Objectif :** Garantir l'exactitude absolue de la base de données via des processus simples sans intervention technique.
- **Parcours Type :** Connexion au panel d'administration, traitement de la file d'attente des signalements utilisateurs, validation des nouveaux textes via un visualiseur de différences (diff checker) avant publication.

***

## 3. Périmètre Fonctionnel

### 🤖 Assistant IA (RAG Juridique Avancé)

- **Recherche Hybride :** Combinaison de la recherche vectorielle (compréhension du contexte) et de la recherche lexicale (respect strict des termes juridiques).
- **Garde-fous (Anti-Hallucination) :** Le prompt système restreint strictement l'IA au droit congolais et OHADA.
- **Traçabilité :** Chaque réponse générée inclut obligatoirement les citations et les liens cliquables vers les articles de loi utilisés pour formuler la réponse.

### 📚 La Bibliothèque Juridique

- L'annuaire structuré du droit.
- **Contenu :** Constitution, Codes (Civil, Pénal, Travail, etc.), Lois, Jurisprudences, et Traités (ex : OHADA).
- **Parcours :** Exploration par arborescence ou filtres rapides pour isoler retrouver une loi spécifique.

### 📰 Actualités & Journaux Officiels (Accueil)

- Positionnés sur l'écran d'accueil sous la barre de recherche IA.
- Affiche les dernières parutions du Journal Officiel de la République pour tenir les utilisateurs informés des nouvelles promulgations.

### 📴 Mode Hors-Ligne (Offline On-Demand / À la demande)

- Application conçue pour être légère et nécessiter internet par défaut.
- **Fonctionnalité "Mettre en cache" :** Téléchargement local (Room KMP) d'un Code entier, d'un dossier de favoris, ou de l'historique d'une conversation IA.

### 📂 Espace Personnel (Workspaces)

- Bureau de travail virtuel : création de dossiers thématiques (ex : "Dossier Client Litige RH", "Création d'Entreprise").
- Classement des articles de loi, jurisprudences et conversations IA.

### 🤝 Boucle de Partage & Qualité

- **Viralité (Partage) :** Bouton de partage natif sur chaque contenu (Article, Résumé IA) générant un Deep Link.
- **Signalements :** Bouton "Signaler une erreur" sur chaque texte permettant de remonter une anomalie dans le tableau de bord des modérateurs.

***

## 4. Architecture Technique (Stack)

### ⚙️ Backend (API & Back-Office)

- **Framework :** Laravel.
- **Base de données :** PostgreSQL avec extensions critiques :
  - `pgvector` : Embeddings vectoriels pour l'IA.
  - `ltree` : Gestion ultra-performante de l'arborescence des textes de loi.
  - `btree_gist` : Gestion des périodes de validité des lois.
- **Standards API :** JSON structuré, filtrage dynamique (`spatie/laravel-query-builder`).

### 📱 Mobile (KMP & Compose)

- **Framework :** Kotlin Multiplatform (KMP) pour centraliser la logique métier et réseau.
- **Interface :** Jetpack Compose / Compose Multiplatform pour une UI native Android/iOS.
- **Persistance :** SQLite via Room KMP (pour les éléments sauvegardés hors-ligne).
- **Réseau :** Ktor (avec support du Server-Sent Events pour l'affichage de l'IA en temps réel).

***

## 5. 🧪 Stratégie de Simulation & Tests de Production

### A. Test d'Ingestion & Administration (Le Workflow)

- **Action :** Un administrateur intègre une nouvelle loi complexe via le back-office.
- **Validation :** L'interface de création permet-elle de découper facilement le texte (Livre/Titre) sans toucher au code ? Le traitement des signalements utilisateurs se fait-il en moins de 3 clics ?

### B. Test de la Recherche IA (Le Citoyen)

- **Action :** Poser une question ambiguë : "Quels sont mes droits si je suis licencié verbalement ?"
- **Validation :** L'IA répond-elle en s'appuyant uniquement sur le Code du Travail congolais ? Les articles sont-ils cités et cliquables ? L'effet streaming du texte (SSE) sur mobile est-il fluide ?

### C. Test du Mode Hors-Ligne (Le Professionnel en déplacement)

- **Action :** L'utilisateur télécharge le Code de la Famille, passe en mode avion, et cherche une disposition spécifique.
- **Validation :** Le texte s'affiche-t-il instantanément depuis la base locale SQLite ?

### D. Test de Partage (La Viralité)

- **Action :** Partager un article de loi intéressant vers WhatsApp.
- **Validation :** Le destinataire reçoit-il un aperçu propre ? S'il clique depuis son téléphone, l'application Mibeko s'ouvre-t-elle directement sur le bon article (Deep Linking) ?
