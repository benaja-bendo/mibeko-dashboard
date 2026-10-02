# Registre des décisions — API (mibeko-dashboard)

> Statut : à jour au 2 octobre 2026 · **Fait autorité sur** : les décisions en vigueur qui ne changent que le code de ce dépôt. Les décisions qui touchent plusieurs dépôts, le produit ou la méthode sont dans le registre transverse (`docs/decisions.md` du monorepo, dépôt `mibeko-docs`), qui donne aussi le gabarit et les règles (D-001).

Identifiants `API-NNN`, jamais réutilisés ; une nouvelle décision s'ajoute à la fin de sa section. Les décisions reprises le 28/09/2026 ne portent « Écarté » et « On rouvre si » que si l'original les donnait ; texte d'origine : `docs/_archive/2026-09-28-journal-decisions-2026-07-a-09.md` (dépôt `mibeko-docs`).

## Outillage

### API-001 · 2026-09-25 · `CLAUDE.md` se réduit à `@AGENTS.md` ; `AGENTS.md` est la seule copie des consignes
**Statut** : en vigueur

**Contexte** : depuis sa version 2.10, Laravel Boost écrit les consignes des agents dans `AGENTS.md`. Deux copies tenues à la main auraient divergé dès le premier `boost:update`.
**Décision** : le fichier de référence est celui qu'on écrit ; dans ce dépôt, c'est Boost. `CLAUDE.md` importe `AGENTS.md`, car Claude Code lit d'abord le `CLAUDE.md` racine du monorepo.
**Écarté** : épingler Boost sur `CLAUDE.md` (Codex, déclaré dans `boost.json`, continuerait d'écrire `AGENTS.md`).

### API-002 · 2026-09-25 · `laravel/ai` reste en 0.9.1
**Statut** : en vigueur

**Contexte** : la 1.0 (23/09) franchit trois versions à ruptures : participants polymorphes, `steps` à la place de `tool_calls`/`tool_results`, reprise des messages de production. Deux régressions silencieuses ont été repérées.
**Décision** : on ne monte que lorsqu'un besoin le justifie, par un chantier dédié testé sur une copie du dump de production.
**On rouvre si** : l'erreur « Réponse IA incomplète (fin : tool_calls) » devient fréquente. Mesure du 25/09 : 0 sur 19 tours depuis le 12/09. À refaire à quelques centaines de tours.

### API-019 · 2026-10-02 · L'alerte de file mail signale un échec une seule fois ; un blocage, à chaque passage
**Statut** : en vigueur · **Réf.** : dashboard#224, dashboard#185

**Contexte** : `mibeko:surveiller-file-mail` relisait tout `failed_jobs` toutes les 15 minutes. Trois échecs du 22/09 (550 Sender mismatch) ont produit environ 700 alertes à partir du retour du SMTP, le 24/09. Elle était muette avant, car elle part par le même SMTP.
**Décision** : un échec est un événement. Il n'alerte que s'il a moins de 24 h et n'a pas déjà été signalé (plus grand `failed_jobs.id` notifié, gardé en cache, avancé seulement après un envoi réussi). Un blocage (`jobs` en attente depuis plus de 10 minutes) est un état : il alerte à chaque passage tant que le worker est arrêté. La console `/admin/sante` garde la mesure complète, sans fenêtre.
**Écarté** : purger `failed_jobs` (`DELETE` physique interdit en production, et ces lignes sont la trace de l'incident) ; une fenêtre seule (jusqu'à 96 alertes par échec sur 24 h) ; une mémoire seule (un `cache:clear` rejouerait tout l'historique).
**On rouvre si** : un échec déjà signalé doit être rappelé après un premier silence (par exemple à J+1), ou si la console doit suivre la même fenêtre que l'alerte.

## Assistant IA et mesure

### API-003 · 2026-09-04 · L'Assistant a un contrat explicite de non-réponse
**Statut** : en vigueur · **Réf.** : dashboard#15, #80

**Décision** : « le corpus est muet » (`aucun_extrait`), « mon filtre ne désigne rien » (`filtre_sans_correspondance`) et « déjà fournis » sont des états distincts ; un seul autorise à annoncer une absence de texte. Le backend dit la non-réponse au client (SSE `no_result`, `meta.no_result`) plutôt que de la laisser deviner. Le schéma de l'outil énumère les codes de type réels.
**Contexte** : l'Assistant annonçait « je n'ai pas trouvé » puis répondait de mémoire, ou déclarait absent un texte publié parce qu'il avait inventé un code de type.

### API-004 · 2026-08-09 · Le RAG de `GET /v1/search` ne se déclenche que sur `rag=1` explicite
**Statut** : en vigueur · **Réf.** : dashboard#14, D-033

**Décision** : fini le déclenchement implicite (quatre mots ou un point d'interrogation). Le RAG est plafonné par le limiteur `ai_assistant` existant, 5 par minute et par IP pour un anonyme. Un dépassement dégrade en silence vers la recherche seule (200), sans 429.
**Écarté** : un limiteur dédié, ou réserver le RAG aux comptes.

### API-005 · 2026-09-03 · `ai_usage_logs` ne journalise que les trois routes orientées usager
**Statut** : en vigueur · **Réf.** : dashboard#61

**Décision** : sont journalisés `assistant/chat`, `library/explain` et `library/synthesis`, mais pas l'outillage éditeur qui partage le même limiteur. Le coût d'un appel reste `null`, jamais inventé, pour un couple fournisseur/modèle sans tarif dans `config('ai.pricing')`.

### API-006 · 2026-09-12 · Mesure d'activation : deux événements seulement, aucune donnée juridique ni coordonnée
**Statut** : en vigueur · **Réf.** : dashboard#137

**Décision** : l'activation est une réponse de l'Assistant réussie, suivie de l'ouverture d'une source de cette réponse précise. Seuls `search_useful` et `source_opened_after_answer` vivent dans `product_activation_events` : aucune colonne texte, charge utile limitée à 5 clés, et une source ouverte vérifiée au serveur. L'agrégat durable (`product_activation_cohort_stats`) n'a pas d'`user_id` et se calcule avant la purge du détail (180 jours).
**Conséquences** : l'application mobile n'écrit pas encore ces événements. Les taux par surface restent une approximation par nom de jeton.

### API-007 · 2026-09-12 · Profil partagé : cadre d'usage en codes stables, métier en texte libre, intérêts = thèmes de vie
**Statut** : en vigueur · **Réf.** : dashboard#135

**Décision** : `usage_context` vaut `personal`, `studies`, `professional` ou `other`, en codes non traduits, recopiés côté clients. `job_title` reste libre, pour ne pas deviner un métier. Les intérêts réutilisent les thèmes (`User::tags()`, `GET library/themes`). `mobile_profiles.user_id` est unique et s'écrit par upsert atomique. Donner un numéro de téléphone ne vaut jamais consentement.

### API-008 · 2026-09-23 · Le journal des recherches mesure la demande, 90 jours au plus
**Statut** : en vigueur · **Réf.** : dashboard#111, #177

**Décision** : `search_logs` est conservé 90 jours (`mibeko:purge-search-logs`) et écrit par une file. On n'y compte ni les recherches arrivées par un lien pré-rédigé (`origine=lien`, en-tête `X-Mibeko-Search-Origin`), ni les pages au-delà de la première, ni le gabarit JSON-LD. L'usager est résolu par `$request->user('sanctum')`, sur des routes qui restent publiques. Une demande de texte manquant réutilise `curation_flags` (`type_probleme='texte_manquant'`).
**Contexte** : le 23/09, 80 % des lignes venaient de liens écrits par le site lui-même, et aucune n'avait d'`user_id`.
**On rouvre si** : il faut compter l'autocomplétion (`library/suggest`), une question laissée ouverte.

## Sécurité, comptes et facturation

### API-009 · 2026-08-16 · L'audit attribue les écritures de l'API ; l'historique sans auteur n'est pas reconstitué
**Statut** : en vigueur · **Réf.** : dashboard#47

**Décision** : le guard `sanctum` est dans `config/audit.php`, et le guard fantôme `api` est retiré. Les tests d'attribution utilisent un vrai jeton (`createToken()` et un en-tête `Authorization`), jamais `actingAs()`.
**Écarté** : reconstituer les auteurs passés par recoupement d'IP (attribution plausible, donc fausse pour un journal d'audit).

### API-010 · 2026-09-05 · L'export PDF est réservé au Pro par un jeton Bearer ou une URL signée
**Statut** : en vigueur · **Réf.** : dashboard#86

**Décision** : le middleware `EnsureExportEntitled` accepte soit un Bearer dont l'entitlement `export` est vrai, soit une URL signée de 120 secondes, émise à la demande pour un compte Pro. Le clic reste « ouvrir une URL » sur le web comme sur le mobile.
**Écarté** : passer au fetch authentifié avec blob des deux côtés (plus intrusif).

### API-011 · 2026-09-03 · Un compte naît `active` ; une adresse jamais vérifiée n'est jamais marquée vérifiée
**Statut** : en vigueur · **Réf.** : dashboard#11

**Décision** : l'inscription écrit `status = active`, et la colonne a ce défaut en base. `email_verified_at` n'est jamais rempli rétroactivement. Les rattrapages passent par l'API d'administration, auditée, pas par `pgsql_prod_rw`.
**Écarté** : `pending`, qui décrirait une restriction inexistante et laisserait ces comptes hors de tout filtre `active`.

### API-012 · 2026-09-11 · Facturation : l'accès, l'octroi et l'encaissement sont trois choses distinctes
**Statut** : en vigueur · **Réf.** : dashboard#109, #121, #122, D-028

**Décision** :
- un rôle Pro ne prouve pas un paiement ;
- ce qui a été réellement encaissé se dérive d'un grand livre en ajout seul (`plan_grant_movements`), jamais du montant saisi à la vente ;
- rembourser et couper l'accès sont deux gestes distincts ;
- le justificatif est un reçu de confirmation, jamais une facture (pas d'entité juridique) ;
- une révocation pose `revoked_at` sans réécrire `ends_at`.

## Corpus

### API-013 · 2026-08-02 · `review` → `published` est une transition valide
**Statut** : en vigueur

**Décision** : `validated` est une étape facultative. `draft → validated` et `draft → published` restent refusés.

### API-014 · 2026-08-29 · Renuméroter un article se fait en trois temps : retirer, garer, attribuer
**Statut** : en vigueur · **Réf.** : dashboard#72

**Décision** : l'index `uq_articles_document_numero` est partiel et non différable. On apparie donc sans écrire, on retire d'abord les articles abandonnés, on gare les numéros qui changent sur une valeur temporaire, puis on attribue. Un article restauré reçoit son numéro dans la même écriture. Trois tests verrouillent l'ordre.

### API-015 · 2026-08-16 · Un document dépublié ne se republie pas par une opération en masse
**Statut** : en vigueur · **Réf.** : dashboard#20

**Décision** : une dépublication est une exclusion durable des lots génériques (détectée dans l'audit). Le `PATCH` unitaire reste possible après correction.
**Contexte** : le 11/08, une campagne en masse a republié deux compilations privées dépubliées avec motif le 08/08.

### API-016 · 2026-09-19 · Les alias de slug visent le document, et l'API ne redirige pas
**Statut** : en vigueur · **Réf.** : dashboard#155, D-046

**Décision** : `document_slug_aliases` pointe vers le document, ce qui résout une chaîne d'alias en un saut. Un slug n'est jamais à la fois canonique d'un document et alias d'un autre, règle vérifiée à l'écriture. `GET /legal-documents/slug/{slug}` renvoie `canonical_slug`, et c'est le site qui redirige, puisque l'API sert aussi le mobile.

### API-017 · 2026-09-19 · La purge du CDN vide tout, une minute après le déclencheur
**Statut** : en vigueur, partiellement appliquée · **Réf.** : dashboard#161, D-011

**Décision** : `purge_everything`, car l'offre gratuite ne purge pas par préfixe. La purge passe par un job unique différé d'une minute, pour qu'une rafale ne fasse qu'un appel. Sans configuration Cloudflare, c'est un no-op silencieux, décidé par un seul service (`CloudflarePurger`).
**Conséquences** : la dépublication en masse et `mibeko:appliquer-numeros` ne déclenchent pas encore de purge.

### API-018 · 2026-08-07 · `POST /v1/auth/firebase` est supprimé
**Statut** : en vigueur

**Décision** : la route contournait la double authentification et n'avait aucun usage réel.
**On rouvre si** : une connexion sociale est voulue ; elle devra alors passer par le même contrôle 2FA que `login()`.
