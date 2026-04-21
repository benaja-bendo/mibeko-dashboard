# 📄 MIBEKO - Documentation Globale & Stratégie Projet

## 1. Vision & Positionnement
**Mibeko** est la plateforme LegalTech de référence pour la **République du Congo (Brazzaville)**. Elle centralise le corpus juridique national pour le rendre accessible aux professionnels du droit et aux citoyens.

> [!IMPORTANT]
> **Promesse :** Le droit congolais, partout, tout le temps, même sans connexion.

---

## 2. Personas & Expérience Utilisateur

### ⚖️ L'Expert (Avocats, Magistrats, Étudiants)
- **Objectif :** Trouver une référence juridique précise en un temps record pour étayer un dossier.
- **Parcours Type :** Navigation hiérarchique profonde (Livre > Titre > Chapitre), recherche plein texte avancée, consultation des Codes, Lois et Jurisprudences.

### 🧑‍🤝‍🧑 Le Citoyen (Grand Public)
- **Objectif :** Comprendre ses droits et obligations face à une situation de la vie quotidienne (mariage, licenciement, bail, etc.).
- **Parcours Type :** Interroge l'IA en langage naturel ("Assistant Mibeko") et sauvegarde des réponses dans ses favoris personnels.

### 🛡️ L'Administrateur / Modérateur (Back-Office)
- **Objectif :** Garantir la fiabilité, l'exactitude et l'intégrité de la base de données juridique.
- **Parcours Type :** Modération des contributions utilisateurs, correction d'erreurs signalées, validation des textes avant publication officielle.

---

## 3. Périmètre Fonctionnel (Core Features)

### 🤖 Assistant IA (RAG)
- Moteur de recherche sémantique basé sur l'IA pour poser des questions en langage naturel.
- **Contrainte Forte :** Les réponses doivent s'appuyer *strictement* sur le droit congolais pour éviter les hallucinations (pas de droit français).

### 📚 Bibliothèque Juridique & Navigation
- Accès structuré au corpus national (Constitution, Codes, Lois, Jurisprudence).
- Navigation hiérarchique ultra-rapide et recherche lexicale.

### 📰 Journaux Officiels
- Affichage et consultation des publications (Journal de la République, etc.) directement dans l'application mobile (PDF ou texte extrait).

### 📴 Mode Hors-Ligne (Offline First)
- Mise en cache de sections entières (lois, codes, jurisprudences) pour un accès 100% hors-connexion.

### 📂 Espace Personnel (Favoris & Dossiers)
- Création de dossiers thématiques par l'utilisateur pour classer ses recherches et articles mis en favoris (ex: "Dossier Client X", "Mes Droits").

### 🤝 Mode Collaboratif (Crowdsourcing)
- **Signalements :** Possibilité pour un utilisateur de remonter des erreurs ou des incohérences dans les textes.
- **Contributions :** Ajout de nouveaux textes (lois, jurisprudences) soumis à un processus de validation strict par un expert avant publication.

---

## 4. Architecture Technique (Stack)

### ⚙️ Backend (API & Back-Office)
- **Framework :** Laravel.
- **Base de données :** PostgreSQL avec extensions critiques :
  - `pgvector` : Recherche sémantique (Embeddings vectoriels).
  - `ltree` : Gestion ultra-performante de la hiérarchie arborescente des textes.
  - `btree_gist` : Gestion des périodes de validité des lois (daterange).
- **Standards API :** Réponses structurées JSON, filtrage dynamique (`spatie/laravel-query-builder`).

### 📱 Mobile (KMP & Compose)
- **Framework :** Kotlin Multiplatform (KMP) pour le partage de la logique métier (Android/iOS).
- **Interface :** Jetpack Compose / Compose Multiplatform pour une UI native et fluide.
- **Persistance :** SQLite via **Room KMP** (pour le mode hors-ligne).
- **Réseau :** Ktor pour l'API et la synchronisation.

---

## 5. 🧪 Stratégie de Simulation & Tests de Production

Pour vous assurer que l'application n'est pas "contraignante au quotidien" et qu'elle répond aux besoins métier, voici les scénarios de tests à simuler avec des données réelles :

### A. Test d'Ingestion des Données (Admin)
- **Action :** Intégrer un vrai texte de loi complexe (ex: Code du Travail congolais).
- **Validation :** Le processus (upload PDF → extraction texte → structuration en base) est-il fluide ? L'arborescence (Livre/Titre/Chapitre) se crée-t-elle correctement sans trop d'intervention manuelle ?

### B. Test du Flux de Contribution (Modération)
- **Action :** Simuler la création de 5 "signalements d'erreurs" et 2 "ajouts de lois" depuis l'application mobile.
- **Validation :** Depuis le panel admin Laravel, est-il rapide (en 1 ou 2 clics) de lire la proposition, de la valider ou de la rejeter ? Le modérateur n'est-il pas noyé sous les clics ?

### C. Test de la Recherche IA / RAG (Citoyen)
- **Action :** Poser une question ambiguë : *"Quels sont mes droits si je suis licencié verbalement ?"*
- **Validation :** L'IA trouve-t-elle les bons articles congolais ? Le temps de réponse en streaming (SSE via Ktor) est-il acceptable sur mobile ?

### D. Test du Mode Hors-Ligne (Expert/Citoyen)
- **Action :** Mettre un code complet en cache, couper le réseau (Mode Avion), et chercher un article précis.
- **Validation :** La recherche locale en mémoire est-elle vraiment instantanée ? Les données restent-elles accessibles après un redémarrage de l'application ?

### E. Test de l'Espace Personnel
- **Action :** Créer 3 dossiers, y glisser 50 articles, puis naviguer entre les dossiers.
- **Validation :** La gestion des favoris (ajout/suppression/déplacement) est-elle ergonomique et sans latence ?
