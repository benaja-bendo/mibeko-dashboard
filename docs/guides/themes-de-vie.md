# Thèmes de vie — guide d'utilisation & bonnes pratiques

> Taxonomie éditoriale qui rend le droit congolais navigable **par situation de
> vie** (« on me licencie », « litige avec mon bailleur ») plutôt que par
> référence juridique. C'est un levier d'accessibilité pour le citoyen et un
> filtre thématique pour le professionnel.

## 1. Modèle de données

Les thèmes **réutilisent le modèle `Tag`** (pas de table dédiée) : toutes les
lignes de `tags` sont des thèmes.

| Colonne (`tags`) | Rôle |
|---|---|
| `name` | Libellé affiché (ex. « Travail & emploi ») |
| `slug` | Identifiant stable utilisé dans les URLs/filtres (ex. `travail`) |
| `icon` | Nom d'icône **lucide** rendu côté front (ex. `briefcase`) |
| `description` | Phrase courte affichée sous le thème |
| `display_order` | Ordre d'affichage dans la bande « Parcourir par thème » |

Le rattachement est **polymorphe** via `taggables` (`App\Models\Tag::legalDocuments()`,
`->articles()`). On assigne au **niveau document** ; à l'enregistrement, les
thèmes sont **propagés à tous les articles** du document
(`LegalDocumentController::propagateThemesToArticles`) afin que le filtre
article par `tag` (slug) du moteur de recherche fonctionne aussi.

## 2. Gérer la taxonomie

- **Socle canonique** : `database/seeders/ThemesSeeder.php` (idempotent,
  `updateOrCreate` par slug). C'est la source de vérité des ~10 thèmes de vie.
  Rejouer : `php artisan db:seed --class=ThemesSeeder`.
- **Au quotidien** : espace admin → Référentiels → onglet **Thèmes** (CRUD,
  champs icône + description + ordre). La suppression est **bloquée (409)** si le
  thème est encore rattaché à des documents/articles.

## 3. Assigner des thèmes à un texte (éditeur)

Dans le **viewer**, ouvrir « Informations du document » :

1. Cocher 1 à 3 thèmes pertinents.
2. (Optionnel) cliquer **« Suggérer (IA) »** : `ThemeClassifier` lit le titre +
   un extrait des articles et propose des thèmes **dans la taxonomie fermée**.
   La suggestion ne fait **rien écrire automatiquement** — l'éditeur valide.
3. « Enregistrer les thèmes » → `PATCH /api/v1/legal-documents/{id}` avec
   `themes: [tagId, …]`.

## 4. Côté utilisateur (bibliothèque)

- **Bande « Parcourir par thème »** sur l'accueil : chaque thème montre le
  nombre de textes publiés (`GET /library/themes`). Un clic ouvre la **liste des
  textes du thème** (`GET /library/themes/{slug}`) — ce n'est **pas** une
  recherche full-text.
- **Filtre Thème** dans le panneau de filtres : combiné à une requête texte, il
  restreint la recherche d'articles au thème (`GET /library/search?q=…&tag=slug`).

## 5. Endpoints

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/v1/library/themes` | Liste des thèmes + nb de textes publiés |
| GET | `/api/v1/library/themes/{slug}` | Textes publiés d'un thème |
| GET | `/api/v1/library/search?q=…&tag={slug}` | Recherche d'articles filtrée par thème (texte requis) |
| PATCH | `/api/v1/legal-documents/{id}` (`themes: []`) | Assigner les thèmes (editor/admin) |
| POST | `/api/v1/legal-documents/{id}/suggest-themes` | Suggestion IA (editor/admin) |
| `/api/v1/admin/tags` (CRUD) | | Gérer la taxonomie (admin) |

## 6. Bonnes pratiques

- **Taxonomie courte et stable** : viser 8 à 12 thèmes « de vie ». Ce n'est pas
  une folksonomie libre — on ne crée pas un thème par sujet pointu.
- **Slugs immuables** : ils servent d'identifiant dans les URLs et filtres. Pour
  renommer, changer le `name`, jamais le `slug`.
- **Assigner au niveau document** (1 doc, pas 100 articles) ; la propagation aux
  articles est automatique. Ré-enregistrer remplace l'ensemble.
- **1 à 3 thèmes par texte** : au-delà, le rattachement perd son sens.
- **L'IA assiste, l'humain décide** : `suggest-themes` ne fait que proposer ;
  rien n'est écrit sans validation de l'éditeur.
- **Icônes = noms lucide** (`briefcase`, `home`, `gavel`…). Une icône inconnue
  retombe sur une icône générique côté front.
- **Ne pas supprimer un thème utilisé** : le réaffecter d'abord (la suppression
  est bloquée tant qu'il reste rattaché).

## 7. Pistes (non livrées)

- Centres d'intérêt : un utilisateur suit des thèmes et reçoit une alerte au
  prochain Journal Officiel concerné (veille).
- Suggestion IA en masse (batch) sur tout le fonds existant.
- Rattacher le champ libre `Dossier.tag` à cette taxonomie.
