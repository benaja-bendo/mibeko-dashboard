# Réparer une extraction juridique par curl en développement

> Statut : à jour au 29 août 2026 · **Fait autorité sur** : la réparation locale, mesurée et reproductible d'une extraction de document juridique par les endpoints `extraction-snapshot` et `replace-extraction`.

Ce guide décrit un parcours **exclusivement local**. Toutes les URL HTTP doivent viser `127.0.0.1:8000`, PostgreSQL doit répondre sur `127.0.0.1:5433` et MinIO sur `127.0.0.1:9000`. Ne jamais adapter ces commandes à la production, à un tunnel de production ou à un fichier `.env` basculé vers la production.

Le PDF officiel est la seule source de vérité. Une correction reproduit le texte imprimé, coquilles comprises. Elle ne reformule rien, ne complète rien de mémoire et marque un passage illisible par `[...]`.

## Contrat vérifié dans le code

Les routes sont déclarées dans [`routes/api.php`](../../routes/api.php), le contrôle HTTP dans [`PublishedDocumentExtractionRepairController.php`](../../app/Http/Controllers/Api/V1/Admin/PublishedDocumentExtractionRepairController.php) et la transaction dans [`PublishedDocumentExtractionRepairService.php`](../../app/Services/PublishedDocumentExtractionRepairService.php).

- `GET /api/v1/legal-documents/{id}/extraction-snapshot` produit la cible courante et ses empreintes.
- `POST /api/v1/legal-documents/{id}/replace-extraction` simule avec `execute: false` et applique avec `execute: true`.
- Le `motif` est validé dans les deux cas : il est obligatoire et contient entre 20 et 1 000 caractères.
- Une suppression d'articles exige `confirm_deletions` égal au nombre annoncé par le dry-run ; toute autre valeur provoque un conflit HTTP 409.
- L'`expected_fingerprint` protège contre une modification concurrente entre la mesure et l'application.
- Un article cible portant un `id` est apparié par cet `id`. Un `id` absent autorise une création ou une restauration par numéro ; retirer accidentellement les `id` peut donc recréer des articles et faire perdre la continuité de leur historique.
- Quand `media_files.page_count` est connu, chaque `source_locator.page` et `source_locator.page_end` doit être un entier compris entre `1` et ce nombre de pages.
- Une application conserve la version active des articles réemployés, soft-delete les retraits, invalide l'embedding d'un contenu modifié et ajoute une trace dans `legal_documents.metadata.extraction_repairs`.

Les réponses réussies sont enveloppées dans `{success, message, data}`. Les empreintes, plans et compteurs se lisent donc sous `.data` avec `jq`.

## 1. Prouver que l'environnement est local

Depuis le dépôt Laravel :

```bash
cd /Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord

for key in APP_ENV APP_URL DB_CONNECTION DB_HOST DB_PORT DB_DATABASE FILESYSTEM_DISK AWS_ENDPOINT; do
  value="$(sed -n "s/^${key}=//p" .env | tail -n 1)"
  printf '%s=%s\n' "$key" "$value"
done
```

Arrêter immédiatement si les valeurs ne prouvent pas `APP_ENV=local`, PostgreSQL sur le port local `5433` et MinIO à l'URL locale `http://127.0.0.1:9000`.

Vérifier PostgreSQL sans passer par Laravel :

```bash
PGPASSWORD=root psql \
  -h 127.0.0.1 -p 5433 -U root -d mibeko-db \
  -v ON_ERROR_STOP=1 -P pager=off \
  -c "SELECT current_database(), current_user, inet_server_addr(), inet_server_port(), current_setting('transaction_read_only');"
```

Dans un terminal dédié, démarrer l'API :

```bash
cd /Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord
php artisan serve --host=127.0.0.1 --port=8000
```

Si le port est déjà pris, ne pas lancer un second serveur. Vérifier le processus et son répertoire :

```bash
SERVER_PID="$(lsof -nP -t -iTCP:8000 -sTCP:LISTEN | head -n 1)"
ps -p "$SERVER_PID" -o pid=,etime=,command=
lsof -a -p "$SERVER_PID" -d cwd -Fn
```

## 2. Créer un jeton admin local et jetable

Le shell ci-dessous capture le jeton sans l'afficher et sans l'écrire dans un fichier :

```bash
export MIBEKO_TOKEN_NAME="dossier-travail-cli-$(date +%Y%m%d%H%M%S)"
export MIBEKO_API_TOKEN="$(
  MIBEKO_TOKEN_NAME="$MIBEKO_TOKEN_NAME" php artisan tinker --execute='
    $user = App\Models\User::role("admin")->firstOrFail();
    echo $user->createToken(getenv("MIBEKO_TOKEN_NAME"))->plainTextToken;
  '
)"

if [ -n "$MIBEKO_API_TOKEN" ]; then
  echo "Jeton local chargé (${#MIBEKO_API_TOKEN} caractères)."
else
  echo "Échec de création du jeton." >&2
  exit 1
fi
```

S'il n'existe aucun admin de développement, la commande échoue avec `firstOrFail`. Créer ou promouvoir un compte **uniquement dans la base locale** avant de recommencer. Ne jamais coller le jeton dans le JSON : les commandes curl le lisent depuis `MIBEKO_API_TOKEN`.

## 3. Trouver un défaut candidat par SQL

Cette requête classe les documents qui contiennent un article actif vide ou très court. Elle ne décide pas qu'un texte est faux : le PDF doit encore le prouver.

```bash
PGPASSWORD=root psql \
  -h 127.0.0.1 -p 5433 -U root -d mibeko-db \
  -v ON_ERROR_STOP=1 -P pager=off <<'SQL'
WITH active_articles AS (
    SELECT a.document_id,
           char_length(coalesce(av.contenu_texte, '')) AS chars,
           av.contenu_texte
    FROM articles a
    JOIN article_versions av
      ON av.article_id = a.id
     AND upper_inf(av.validity_period)
    WHERE a.deleted_at IS NULL
), source_pdf AS (
    SELECT DISTINCT ON (document_id)
           document_id, original_filename, page_count
    FROM media_files
    WHERE file_category = 'SOURCE_PDF'
    ORDER BY document_id, created_at DESC
), doc_stats AS (
    SELECT document_id,
           count(*) AS article_count,
           count(*) FILTER (
               WHERE btrim(coalesce(contenu_texte, '')) = ''
           ) AS empty_count,
           count(*) FILTER (WHERE chars BETWEEN 1 AND 20) AS very_short_count,
           sum(chars) AS total_chars
    FROM active_articles
    GROUP BY document_id
)
SELECT d.id, d.curation_status, ds.*, sp.page_count,
       left(d.titre_officiel, 100) AS titre,
       sp.original_filename
FROM doc_stats ds
JOIN legal_documents d
  ON d.id = ds.document_id
 AND d.deleted_at IS NULL
JOIN source_pdf sp ON sp.document_id = d.id
WHERE ds.empty_count > 0 OR ds.very_short_count > 0
ORDER BY ds.empty_count DESC, ds.article_count, ds.very_short_count DESC;
SQL
```

Après arbitrage d'un candidat, exporter son identifiant :

```bash
export MIBEKO_DOCUMENT_ID="<UUID_DU_DOCUMENT_DE_DEVELOPPEMENT>"
```

Voir les lignes suspectes, leur UUID et leur repère de source :

```bash
PGPASSWORD=root psql \
  -h 127.0.0.1 -p 5433 -U root -d mibeko-db \
  -v ON_ERROR_STOP=1 -P pager=off \
  -v document_id="$MIBEKO_DOCUMENT_ID" <<'SQL'
SELECT a.id, a.numero_article, a.ordre_affichage,
       char_length(coalesce(av.contenu_texte, '')) AS chars,
       av.source_locator,
       av.contenu_texte
FROM articles a
JOIN article_versions av
  ON av.article_id = a.id
 AND upper_inf(av.validity_period)
WHERE a.document_id = :'document_id'::uuid
  AND a.deleted_at IS NULL
ORDER BY a.ordre_affichage, a.id;
SQL
```

## 4. Récupérer et lire le PDF depuis MinIO local

Lire d'abord l'emplacement et l'empreinte depuis PostgreSQL :

```bash
PDF_RECORD="$(
  PGPASSWORD=root psql \
    -h 127.0.0.1 -p 5433 -U root -d mibeko-db \
    -v ON_ERROR_STOP=1 -At -F '|' \
    -v document_id="$MIBEKO_DOCUMENT_ID" <<'SQL'
SELECT bucket_name, object_key, checksum_sha256, page_count
FROM media_files
WHERE document_id = :'document_id'::uuid
  AND file_category = 'SOURCE_PDF'
ORDER BY created_at DESC
LIMIT 1;
SQL
)"

IFS='|' read -r PDF_BUCKET PDF_OBJECT_KEY PDF_SHA256 PDF_PAGE_COUNT <<EOF
$PDF_RECORD
EOF
```

Copier l'objet depuis le conteneur MinIO local vers un répertoire temporaire :

```bash
export MIBEKO_PDF_DIR="$(mktemp -d /tmp/mibeko-pdf.XXXXXX)"
MINIO_CONTAINER="mibeko-tableau-de-bord-minio-1"
CONTAINER_TMP_DIR="$(docker exec "$MINIO_CONTAINER" mktemp -d /tmp/mibeko-pdf.XXXXXX)"

docker exec "$MINIO_CONTAINER" \
  mc alias set local http://localhost:9000 root password >/dev/null
docker exec "$MINIO_CONTAINER" \
  mc cp "local/$PDF_BUCKET/$PDF_OBJECT_KEY" "$CONTAINER_TMP_DIR/source.pdf" >/dev/null
docker cp \
  "$MINIO_CONTAINER:$CONTAINER_TMP_DIR/source.pdf" \
  "$MIBEKO_PDF_DIR/source.pdf" >/dev/null
```

Vérifier le fichier avant de l'utiliser :

```bash
ACTUAL_PDF_SHA256="$(shasum -a 256 "$MIBEKO_PDF_DIR/source.pdf" | awk '{print $1}')"
test "$ACTUAL_PDF_SHA256" = "$PDF_SHA256" || {
  echo "Empreinte PDF différente de la base." >&2
  exit 1
}

ACTUAL_PDF_PAGES="$(pdfinfo "$MIBEKO_PDF_DIR/source.pdf" | awk '/^Pages:/ {print $2}')"
test "$ACTUAL_PDF_PAGES" = "$PDF_PAGE_COUNT" || {
  echo "Nombre de pages différent de la base." >&2
  exit 1
}
```

Tester la couche texte :

```bash
pdftotext "$MIBEKO_PDF_DIR/source.pdf" - | tr -d '[:space:]\f' | wc -c
```

Si elle est vide, rendre les pages et lancer Tesseract **uniquement pour localiser** les mots douteux :

```bash
mkdir -p "$MIBEKO_PDF_DIR/pages" "$MIBEKO_PDF_DIR/ocr"
pdftoppm -jpeg -r 180 \
  "$MIBEKO_PDF_DIR/source.pdf" \
  "$MIBEKO_PDF_DIR/pages/page"

(
  cd "$MIBEKO_PDF_DIR/pages"
  for image_name in page-*.jpg; do
    page_name="${image_name%.jpg}"
    tesseract "$image_name" "../ocr/$page_name" -l fra --psm 6 >/dev/null
  done
)

rg -n -i -C 4 '<MOT_OU_NUMERO_DOUTEUX>' "$MIBEKO_PDF_DIR/ocr"
```

Sur cette machine, Tesseract peut échouer à ouvrir certains chemins absolus alors que le JPEG existe. Le sous-shell qui se place dans `pages/` et lui passe des noms relatifs contourne ce problème.

Une fois la page localisée, ouvrir le scan lui-même :

```bash
open "$MIBEKO_PDF_DIR/source.pdf"
```

Le texte OCR n'est jamais recopié sans relecture du scan. Une ligne illisible reste `[...]`.

## 5. Exporter le snapshot par curl

Créer un espace de travail privé et verrouiller l'URL locale :

```bash
umask 077
export MIBEKO_BASE_URL="http://127.0.0.1:8000"
export MIBEKO_WORK_DIR="$(mktemp -d /tmp/mibeko-dossier-travail.XXXXXX)"

case "$MIBEKO_BASE_URL" in
  http://127.0.0.1:8000|http://localhost:8000) ;;
  *) echo "URL non locale refusée : $MIBEKO_BASE_URL" >&2; exit 1 ;;
esac
```

Télécharger le snapshot :

```bash
curl --silent --show-error --fail-with-body \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer $MIBEKO_API_TOKEN" \
  --output "$MIBEKO_WORK_DIR/snapshot-response.json" \
  "$MIBEKO_BASE_URL/api/v1/legal-documents/$MIBEKO_DOCUMENT_ID/extraction-snapshot"

jq '.data | {expected_fingerprint, semantic_fingerprint, counts, target: {document_id: .target.document_id, source_pdf: .target.source_pdf}}' \
  "$MIBEKO_WORK_DIR/snapshot-response.json"
```

Comparer obligatoirement `target.source_pdf.sha256` au SHA-256 du PDF téléchargé.

## 6. Construire une proposition complète

L'exemple suivant corrige le texte et la page d'un article existant, puis retire un faux article. Adapter les UUID, le texte et la page après lecture du PDF. Si aucun article ne doit disparaître, retirer la branche `elif` au lieu de fournir un UUID fictif.

```bash
export CORRECTED_ARTICLE_ID="<UUID_ARTICLE_A_CORRIGER>"
export REMOVED_ARTICLE_ID="<UUID_FAUX_ARTICLE_A_RETIRER>"
export CORRECTED_PAGE="<PAGE_PDF_VERIFIEE>"
export CORRECTED_CONTENT="<TRANSCRIPTION_FIDELE_DU_PDF>"
export REPAIR_MOTIF="<MOTIF_PRECIS_D_AU_MOINS_20_CARACTERES>"

jq \
  --arg corrected_id "$CORRECTED_ARTICLE_ID" \
  --arg removed_id "$REMOVED_ARTICLE_ID" \
  --arg corrected_content "$CORRECTED_CONTENT" \
  --argjson corrected_page "$CORRECTED_PAGE" \
  --arg motif "$REPAIR_MOTIF" '
  {
    execute: false,
    expected_fingerprint: .data.expected_fingerprint,
    motif: $motif,
    target: .data.target
  }
  | .target.articles |= map(
      if .id == $corrected_id then
        .content = $corrected_content
        | .source_locator = {page: $corrected_page}
      elif .id == $removed_id then
        empty
      else
        .
      end
    )
' "$MIBEKO_WORK_DIR/snapshot-response.json" \
  > "$MIBEKO_WORK_DIR/dry-run-request.json"
```

Pour une correction sans ajout d'article, vérifier que la proposition conserve exactement tous les UUID sauf le retrait arbitré :

```bash
jq -n -e \
  --slurpfile snapshot "$MIBEKO_WORK_DIR/snapshot-response.json" \
  --slurpfile request "$MIBEKO_WORK_DIR/dry-run-request.json" \
  --arg removed_id "$REMOVED_ARTICLE_ID" '
  (
    $snapshot[0].data.target.articles
    | map(.id)
    | map(select(. != $removed_id))
    | sort
  ) == (
    $request[0].target.articles
    | map(.id)
    | sort
  )
'
```

Contrôler aussi l'enveloppe avant envoi :

```bash
jq -e \
  --arg document_id "$MIBEKO_DOCUMENT_ID" '
  .execute == false
  and .target.document_id == $document_id
  and (.motif | length) >= 20
  and (([.target.articles[].id] | length) == ([.target.articles[].id] | unique | length))
' "$MIBEKO_WORK_DIR/dry-run-request.json" >/dev/null
```

Les ordres doivent être uniques sur l'ensemble des divisions et articles. Un parent doit désigner la `key` d'une division qui le précède. Laisser le serveur refuser le fichier entier plutôt que contourner ces contrôles.

## 7. Mesurer l'état SQL avant

Exécuter et conserver cette mesure avant le dry-run :

```bash
PGPASSWORD=root psql \
  -h 127.0.0.1 -p 5433 -U root -d mibeko-db \
  -v ON_ERROR_STOP=1 -P pager=off \
  -v document_id="$MIBEKO_DOCUMENT_ID" <<'SQL'
SELECT count(*) FILTER (WHERE a.deleted_at IS NULL) AS active_articles,
       count(*) FILTER (
           WHERE a.deleted_at IS NULL
             AND btrim(coalesce(av.contenu_texte, '')) = ''
       ) AS active_empty_articles,
       sum(char_length(coalesce(av.contenu_texte, '')))
           FILTER (WHERE a.deleted_at IS NULL) AS active_characters,
       count(*) FILTER (WHERE a.deleted_at IS NOT NULL) AS soft_deleted_articles
FROM articles a
JOIN article_versions av
  ON av.article_id = a.id
 AND upper_inf(av.validity_period)
WHERE a.document_id = :'document_id'::uuid;

SELECT curation_status, updated_at,
       coalesce(jsonb_array_length(metadata->'extraction_repairs'), 0)
           AS extraction_repairs
FROM legal_documents
WHERE id = :'document_id'::uuid;
SQL
```

Pour les articles touchés, relever aussi `articles.id`, `article_versions.id`, le contenu, `source_locator`, `deleted_at` et `updated_at`. Les mêmes UUID doivent encore être présents après l'opération.

## 8. Simuler par curl

```bash
curl --silent --show-error --fail-with-body \
  --request POST \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header "Authorization: Bearer $MIBEKO_API_TOKEN" \
  --data-binary @"$MIBEKO_WORK_DIR/dry-run-request.json" \
  --output "$MIBEKO_WORK_DIR/dry-run-response.json" \
  "$MIBEKO_BASE_URL/api/v1/legal-documents/$MIBEKO_DOCUMENT_ID/replace-extraction"

jq '.data | {dry_run, already_applied, before_fingerprint, target_semantic_fingerprint, plan, warnings}' \
  "$MIBEKO_WORK_DIR/dry-run-response.json"
```

La réponse attendue porte `dry_run: true`. Relire chaque compteur et chaque avertissement. Si `articles_soft_deleted` surprend, s'arrêter : une cible tronquée ressemble exactement à une suppression voulue.

Rejouer la mesure SQL de la section précédente. Les compteurs, contenus, repères, timestamps et le nombre de réparations doivent être inchangés après le dry-run.

## 9. Arbitrer puis appliquer par curl

Recopier manuellement le nombre de suppressions lu dans `.data.plan.articles_soft_deleted` :

```bash
export CONFIRM_DELETIONS="<NOMBRE_LU_ET_ARBITRE_DANS_LE_DRY_RUN>"

jq \
  --argjson confirm_deletions "$CONFIRM_DELETIONS" '
  .execute = true
  | .confirm_deletions = $confirm_deletions
' "$MIBEKO_WORK_DIR/dry-run-request.json" \
  > "$MIBEKO_WORK_DIR/execute-request.json"
```

Appliquer :

```bash
curl --silent --show-error --fail-with-body \
  --request POST \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header "Authorization: Bearer $MIBEKO_API_TOKEN" \
  --data-binary @"$MIBEKO_WORK_DIR/execute-request.json" \
  --output "$MIBEKO_WORK_DIR/execute-response.json" \
  "$MIBEKO_BASE_URL/api/v1/legal-documents/$MIBEKO_DOCUMENT_ID/replace-extraction"

jq '.data | {executed, already_applied, operation_id, curation_status, before_fingerprint, after_fingerprint, semantic_fingerprint, plan, warnings, actual}' \
  "$MIBEKO_WORK_DIR/execute-response.json"
```

Vérifier les compteurs communs au plan et à l'exécution :

```bash
jq -e '
  .data.executed == true
  and .data.plan.nodes_soft_deleted == .data.actual.nodes_soft_deleted
  and .data.plan.articles_soft_deleted == .data.actual.articles_soft_deleted
  and .data.plan.article_contents_updated == .data.actual.article_contents_updated
  and .data.plan.article_locators_updated == .data.actual.article_locators_updated
' "$MIBEKO_WORK_DIR/execute-response.json" >/dev/null
```

Refaire le snapshot et comparer l'empreinte sémantique :

```bash
curl --silent --show-error --fail-with-body \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer $MIBEKO_API_TOKEN" \
  --output "$MIBEKO_WORK_DIR/after-snapshot-response.json" \
  "$MIBEKO_BASE_URL/api/v1/legal-documents/$MIBEKO_DOCUMENT_ID/extraction-snapshot"

TARGET_SEMANTIC="$(jq -r '.data.target_semantic_fingerprint' "$MIBEKO_WORK_DIR/dry-run-response.json")"
AFTER_SEMANTIC="$(jq -r '.data.semantic_fingerprint' "$MIBEKO_WORK_DIR/after-snapshot-response.json")"
test "$TARGET_SEMANTIC" = "$AFTER_SEMANTIC"
```

Rejouer enfin les requêtes SQL de mesure. L'écart doit correspondre exactement au plan et à `.data.actual`. Pour une correction sans ajout, comparer également la liste triée des UUID de `.target.articles` aux UUID actifs en base.

Une nouvelle simulation du même fichier doit répondre `already_applied: true` avec des compteurs de changement nuls. Elle reste utile pour vérifier qu'une relance ne réappliquerait rien.

## 10. Révoquer le jeton jetable

Révoquer le jeton exact encore présent dans la variable, puis vider le shell :

```bash
php artisan tinker --execute='
  $token = Laravel\Sanctum\PersonalAccessToken::findToken(getenv("MIBEKO_API_TOKEN"));
  echo "token_revoked=".(($token?->delete()) ? "yes" : "no");
'

unset MIBEKO_API_TOKEN MIBEKO_TOKEN_NAME
```

Les fichiers temporaires de requête ne contiennent pas le jeton. Ils contiennent néanmoins une copie du texte juridique et doivent rester hors du dépôt.

## Refus et pièges utiles

| Symptôme | Cause probable | Action |
| --- | --- | --- |
| HTTP 401 | jeton absent, mal exporté ou révoqué | recréer un jeton admin local et ne jamais le mettre dans le JSON |
| HTTP 403 | rôle insuffisant, notamment pour un document déjà publié | utiliser un admin de développement ; ne pas contourner le contrôleur |
| HTTP 409 sur l'empreinte | le document a changé depuis le snapshot | arrêter, exporter un nouveau snapshot et reconstruire la proposition |
| HTTP 409 sur les suppressions | `confirm_deletions` est absent ou différent du plan | relire le retrait contre le PDF puis recopier le nombre exact ; ce refus est levé avant toute écriture (vérifié par SQL, transaction annulée sans effet de bord) |
| HTTP 422 sur `motif` | motif absent ou trop court, y compris pendant le dry-run | fournir dès la simulation un motif d'au moins 20 caractères |
| HTTP 422 sur une page | `source_locator.page` ou `page_end` sort du PDF mesuré | rouvrir le PDF et corriger le repère ; ne jamais inventer une page |
| HTTP 422 sur l'ordre ou le parent | ordre global dupliqué ou parent absent/mal ordonné | corriger la cible complète, sans neutraliser la validation |
| `jq` renvoie `null` pour le plan | lecture au mauvais niveau de l'enveloppe | lire `.data.plan`, pas `.plan` |
| Tesseract prétend qu'un JPEG absolu n'existe pas | comportement observé avec certains chemins temporaires | lancer Tesseract depuis le dossier des images avec un nom relatif |
| Un motif de recherche trop large désigne un document sain | un filtre sur les caractères non latins accroche l'apostrophe typographique de « SASSOU-N'GUESSO » | éprouver le motif sur un cas connu avant d'en tirer un compte |

## Cycles réellement exécutés

### 29 août 2026 (matin) — Décret n° 59-225 du 31 octobre 1959

Éprouvé de bout en bout sur le **Décret n° 59-225 du 31 octobre 1959** (base de développement, brouillon), dont les articles 4 et 5 portaient des artefacts LaTeX laissés par MinerU : `$\mathbf{n}^{\circ}$` et `$1^{\text{er}}$`.

| Mesure | Valeur |
| --- | --- |
| Plan annoncé par le dry-run | 2 contenus, 2 repères · 0 retrait, 0 ajout · aucun signalement |
| `actual` obtenu à l'application | identique au plan, plus 2 embeddings invalidés |
| Article 4 | 83 → 63 caractères |
| Article 5 | 158 → 145 caractères |
| Articles portant encore du LaTeX | 2 → **0** |

Aucune confirmation de suppression n'était requise : la proposition ne retirait rien.

**Le PDF de ce JO est un scan de 28 pages sans aucune couche texte.** L'acte a été localisé par OCR (page 6, imprimée 674), puis les deux articles **relus sur l'image** avant transcription. L'OCR n'a servi qu'à trouver la page.

### Ce qui n'a délibérément pas été corrigé (premier cycle)

Le préambule et les articles 1 à 3 du même document portent d'autres fautes d'OCR — « éché-nements individiaires », « susviscé », « ordonnage », « replissant », « enumeratedes ». Elles ont été laissées en l'état.

Les corriger demande de relire chaque ligne contre l'image, et l'article 3 contient un sous-article cité entre guillemets dont le rendu en texte brut appelle des choix. Une transcription à moitié vérifiée vaut moins qu'un défaut resté visible : sur un texte juridique, la couverture ne se paie jamais en fidélité.

### 29 août 2026 (après-midi) — Arrêté n° 34758 du 23 novembre 2015

Second cycle, indépendant du premier, sur l'**Arrêté n° 34758 du 23 novembre 2015** (« ouverture et exploitation d'une petite mine d'or à Lounday », base de développement, brouillon). Candidat trouvé par la requête de la section 3 (un article actif de 2 caractères), sur un PDF **natif** (polices Type 1C intégrées, `pdfimages -list` vide sur la page concernée — pas un scan, `pdftotext -layout` fait donc foi sans recours à Tesseract).

Le PDF source (`congo-jo-2015-49.pdf`, Journal officiel n° 49-2015, 20 pages, empreinte vérifiée) montre à la page 17 (page imprimée 1069) une mise en page à deux colonnes : l'arrêté se termine en haut de la colonne de droite, immédiatement suivi, sur la même page, de la rubrique « PARTIE NON OFFICIELLE — ANNONCES » d'actes sans rapport (PwC, Drillship Alonissos, NileDutch Congo, Air Liquide Congo, déclarations d'association).

| Défaut constaté | Cause visible sur le PDF | Correction |
| --- | --- | --- |
| Article « 6 » tronqué juste avant la fin de sa phrase | la citation finale « *…code minier, art.53.2).* » a été coupée au niveau de « art.53.2 » | contenu complété par `\nart.53.2).`, dans le style de retour à la ligne déjà utilisé ailleurs dans le même article |
| Faux article numéroté « 53.2 », contenu `).` | le fragment de citation coupé a été pris pour un nouveau numéro d'article | retiré de la cible (suppression confirmée) |
| Article « 8 » à 7087 caractères, largement supérieur à sa clôture réelle | le texte a continué d'ingérer toute la « PARTIE NON OFFICIELLE » qui suit sur la même page, jusqu'à la fin de page suivante | contenu tronqué juste après « Pierre OBA », fin réelle de l'arrêté |

| Mesure | Valeur |
| --- | --- |
| Plan annoncé par le dry-run | 2 contenus modifiés · 1 retrait · 0 ajout, 0 repère · avertissement `contenu_raccourci` sur l'article 8 (7087 → 148) |
| `actual` obtenu à l'application | identique au plan (`articles_soft_deleted: 1`, `article_contents_updated: 2`), plus 2 embeddings invalidés |
| Articles vivants avant → après | 10 → 9 |
| `curation_status` | inchangé (`draft`) — seul un document `validated` repasse en `review` |
| Empreinte sémantique après application vs. cible | identiques (vérifié par un second `extraction-snapshot`) |

Deux vérifications supplémentaires, réalisées sans risque parce qu'elles n'écrivent rien :

- **Garde-fou de suppression sans confirmation** : rejouer la même cible avec `execute: true` mais sans `confirm_deletions` renvoie bien HTTP 409 (« Cette cible retire 1 article(s)… ») **avant** tout appel à `applyTarget()` — `assertDeletionsConfirmed()` s'exécute dans la transaction mais avant la moindre écriture, donc l'échec est intégralement sans effet de bord. Vérifié par SQL : les 9 articles vivants et l'article 7 (non concerné par ce test) sont restés inchangés.
- **Rejouer une cible déjà appliquée avec `execute: true`** (pas seulement en dry-run) répond `executed: false, already_applied: true`, `before_fingerprint == after_fingerprint`, plan à zéro partout. Le canal est donc sûr à rejouer par erreur.

### Ce qui n'a délibérément pas été corrigé (second cycle)

Le préambule de l'Arrêté n° 34758 et les articles 1 à 5 correspondent fidèlement au PDF (relu intégralement) : aucune autre anomalie n'y a été trouvée. L'article 7 porte deux phrases dans le PDF (« Dans le cadre de la surveillance… » puis « Ils peuvent, à cet effet… ») déjà toutes deux présentes dans la base ; il n'a pas été touché.
