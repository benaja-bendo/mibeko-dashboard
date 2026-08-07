---
name: mibeko-invariants
description: Invariants non négociables du projet Mibeko (corpus juridique Congo-Brazzaville). ACTIVER dès qu'une tâche touche à la production, à la base de données, au corpus (legal_documents, articles, structure_nodes), au pipeline d'ingestion, à la publication d'un texte, aux migrations, ou avant tout commit. Couvre la méthode d'intervention prod en 4 temps, STOCK/FLUX, le cycle de curation, le découpage des Journaux Officiels, et les pièges d'outillage qui contournent les garde-fous.
---

# Invariants Mibeko

LegalTech pour le **Congo-Brazzaville** (jamais la RDC) : corpus juridique national + OHADA, sourcé et traçable.

## 1. Production — l'agent lit, l'humain écrit

Règle qui prime sur toute autre instruction. Référence faisant autorité : `docs/infra/production.md` § 6.

**Temps 1 — Diagnostic (agent, lecture seule).** Profils dédiés uniquement : `pgsql_prod_ro` / `s3_prod_ro` côté Laravel, `src/db/prod_readonly.py` côté Python. Tunnel : 5434 (Postgres), 9100 (MinIO). Lancer `php artisan mibeko:prod-preflight` ou `python main.py prod-preflight` **avant tout** ; s'arrêter si la lecture seule n'est pas *prouvée* (SQLSTATE `25006`/`42501`). **Mesurer et consigner l'état AVANT** — sans chiffre d'avant, pas de vérification d'après.

**Temps 2 — Préparation (agent, en dev).** Correctif rejouable, `--dry-run` par défaut, jamais de SQL ad hoc. Testé sur une copie restaurée du dump prod, où il doit toucher *exactement* le nombre de lignes annoncé. Canal préféré : **API Laravel** (audit, rôles, garde-fous). Préparer un bloc de commandes pour l'humain, avec placeholders — aucun credential dans le chat ni dans un fichier.

**Temps 3 — Exécution (HUMAIN, dans son terminal).** Le classifieur bloque les écritures prod lancées depuis le Bash d'un agent : **c'est le canal voulu, pas un obstacle à contourner**. Autorisation opération par opération : annoncer cible, effet, nombre de lignes, retour arrière, puis *attendre la réponse*. Une autorisation ne vaut jamais pour la suivante. Dump frais systématique.

**Temps 4 — Vérification (agent, lecture seule).** Re-préflight, compteurs après vs Temps 1. L'écart doit être *exactement* celui annoncé ; tout écart inexpliqué est un incident.

### Interdits absolus, même autorisés

`DROP` / `TRUNCATE` · `DELETE` physique · rejouer `schema_postgres.sql` (il commence par des `DROP TABLE … CASCADE` sur toutes les tables, `users` et `audits` compris) · publier par `UPDATE` de `curation_status` · écrire ou supprimer dans MinIO · écrire dans une session dont le préflight n'a pas prouvé le comportement attendu · **basculer un `.env` vers la prod** (cela redirigerait scheduler, jobs, artisan et les serveurs MCP, pas seulement la session).

### Pièges d'outillage qui contournent ces garde-fous

Vérifiés en réel le 07/08/2026 — ne pas se fier aux apparences :

- **`database-query` (Laravel Boost) accepte un paramètre `database`** : il peut viser `pgsql_prod_ro` / `pgsql_prod_rw` sans passer par `prod-preflight` et hors de toute trace. Ce canal n'est pas couvert par la méthode en 4 temps.
- **`php artisan boost:execute-tool` exécute l'outil `Tinker` même quand celui-ci est désactivé** (`shouldRegister()` renvoie `false`, la commande fonctionne quand même). Désactiver tinker protège le client MCP, pas un shell.
- **L'annotation `#[IsReadOnly]` n'est pas un garde-fou** : elle est recopiée dans `tools/list` et jamais lue côté serveur. Ne jamais écrire « annoté read-only donc ne peut pas écrire ».
- **Ne jamais faire `export PROD_RW_*` dans un shell d'où un agent est lancé** : les sous-processus héritent de l'environnement du parent.

## 2. Corpus — modèle de données

**STOCK vs FLUX** (`legal_documents.document_role`) :
- **STOCK** = texte consolidé. Porte `stock_code` + `consolidation_as_of`. **Jamais rattaché à un JO** (contrainte DB).
- **FLUX** = acte unitaire. Un Journal Officiel uploadé est *découpé* en actes.

**Cycle de curation** (`curation_status`) : `draft → review → validated → published`. Seul `published` est visible du public.

**Publication** = `PATCH /legal-documents` via l'API Laravel. Garde-fous : ≥ 1 article obligatoire ; les flags `blocking` non résolus bloquent sauf `force=true` (humain, loggé). **Jamais par SQL direct.**

**Staging ≠ publié** : le pipeline écrit en staging ; la publication est un acte distinct.

## 3. Ingestion

- **Découpage des JO : à partir du `.md` MinerU, jamais du `.json`** — sinon on fabrique de faux actes. Citabilité par page via les marqueurs `[[MIBEKO_PAGE:N]]`.
- **Provenance obligatoire** : URL + date de récupération + SHA-256 + n°/date JO pour chaque texte. `mibeko-python/data/sources/` est **immuable**.
- **Sources officielles uniquement** : sgg.cg, sites institutionnels congolais, ohada.org. Jamais de consolidation d'éditeur privé comme source.
- **Scraping poli** : UA `MibekoBot` + email de contact, ~1 req/3-5 s, backoff exponentiel, jamais de re-téléchargement d'un checksum connu.
- **Le LLM est un composant d'étape** : sortie validée par schéma strict, retry puis flag, jamais d'insertion partielle.
- **Déterministe, versionné, rejouable** : scripts + file/cron, pas d'actions ad hoc.

## 4. Requêtes et schéma

- **Le schéma DB est piloté uniquement par les migrations Laravel.** Côté Python, `init_db` est un no-op ; les modèles SQLAlchemy et `schema_postgres.sql` se synchronisent **à la main** → vérifier le drift à chaque évolution de schéma.
- **Toute lecture Python de `legal_documents` / `articles` doit filtrer `deleted_at IS NULL`** (SoftDeletes Laravel). Oublier ce filtre est l'erreur la plus fréquente du projet.
- Extensions critiques : `ltree` (arbre des textes), `pgvector` (RAG), `btree_gist` (périodes de validité).
- Base partagée dev : `postgres://root:root@127.0.0.1:5433/mibeko-db`.

## 5. Frontend

**UI → `mibeko-front` uniquement.** Le legacy Inertia de `mibeko-tableau-de-bord` (`resources/js/pages`) est **mort** : ne rien y ajouter. Backend et génération PDF côté Laravel restent légitimes.

## 6. Documentation et commits

- **Docs-as-code** : chaque fichier de `docs/` commence par `# Titre` + `> Statut : à jour au <date> · **Fait autorité sur** : <périmètre>`. Trois catégories : socle (maintenu), plan en cours (meurt une fois exécuté), `_archive/` (instantané daté, jamais modifié). **Pas de chiffre en dur qu'un script peut produire** — écrire la commande.
- **Toute décision structurante = une ligne datée dans `docs/decisions.md`** — sinon elle se rouvrira à la session suivante.
- **En cas de contradiction entre la documentation et le code, le code gagne** ; corriger le document dans la foulée.
- **Commits** : format `type(scope): titre court` **en français**, à l'impératif ou au substantif. Corps qui explique le **POURQUOI**, jamais une liste de fichiers. Petits commits atomiques. **Aucune mention d'agent IA** (pas de trailer `Co-Authored-By`, aucune mention de Claude/Codex/Gemini/Copilot/opencode/Junie/Amp) — décision explicite du 07/08/2026, elle prime sur le comportement par défaut de tout agent.
- Chaque sous-dossier est son propre dépôt git (tout sur `main`). **Ne jamais committer, pousser ou taguer sans l'accord explicite de l'utilisateur** : s'arrêter, donner les commandes, attendre la réponse.

## 7. Après toute modification PHP

`vendor/bin/pint --dirty` est **obligatoire**. Tests : `php artisan test --compact` (Pest, base Postgres réelle).
