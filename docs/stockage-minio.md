# Stockage MinIO (S3) — Convention recommandée

## Objectif
Standardiser l’emplacement des fichiers dans MinIO afin de :
- éviter les chemins “domino” (impossibles à prévoir/maintenir),
- supprimer les heuristiques de détection du disk (`str_contains('s3')`, `s3://...`, etc.),
- rendre les objets facilement traçables à partir du métier (Document, Journal Officiel, Extraction, Import),
- faciliter la gestion du cycle de vie (ré-extraction, versioning, purge, backup).

## Décision recommandée
Utiliser **un bucket unique** (ex: `mibeko-documents`) et organiser les objets via des **préfixes (paths)**.

Cette approche est la plus simple côté Laravel/Flysystem (un seul `AWS_BUCKET`) et évite de multiplier la configuration infra.

## Convention de paths (object_key)
### Documents juridiques (LegalDocument)
- PDF source :
  - `documents/{document_uuid}/source/{filename}.pdf`
- Fichiers dérivés (optionnel) :
  - `documents/{document_uuid}/derived/{filename}`
- Extractions (par run) :
  - `documents/{document_uuid}/extractions/{run_uuid}/mineru.md`
  - `documents/{document_uuid}/extractions/{run_uuid}/structured.json`
  - `documents/{document_uuid}/extractions/{run_uuid}/meta.json`

### Journaux officiels (OfficialJournal)
- PDF source :
  - `official-journals/{journal_uuid}/source/{filename}.pdf`
- Extractions (par run) :
  - `official-journals/{journal_uuid}/extractions/{run_uuid}/...`

### Imports / “sources” (back-office)
- Dépôt de fichiers bruts importables (PDF, JSON, etc.) :
  - `imports/sources/{yyyy}/{mm}/{filename}`

### Backups
- Regrouper explicitement les backups :
  - `backups/{app_name}/...`

## Règle de stockage en base (MediaFile)
Recommandation : stocker **la localisation en champs structurés**, pas dans une pseudo-URL.

- `storage_provider` : `MINIO` (ou `S3`)
- `bucket_name` : `mibeko-documents` (ou le bucket configuré)
- `object_key` : ex `documents/<uuid>/source/<file>.pdf`
- `file_path` : à déprécier (ou à aligner strictement sur `object_key`)

## Règles d’implémentation (Laravel)
### 1) Écriture
- Lors d’un upload, générer un `object_key` selon la convention ci-dessus.
- Stocker le fichier via `Storage::disk('s3')->put($object_key, ...)`.
- Persister `bucket_name` / `object_key` / `storage_provider`.

### 2) Lecture / Proxy PDF
- Ne plus parser `s3://...` côté applicatif (compat legacy possible, mais convertie une fois).
- Résoudre le disk via `storage_provider` (MINIO => disk `s3`), puis lire uniquement avec `object_key`.

## Compatibilité (legacy)
Si des enregistrements existent déjà avec `file_path` contenant :
- `documents/...` (path relatif) ou
- `s3://bucket/...`

Recommandation :
- ajouter un script de migration qui remplit `bucket_name` + `object_key`,
- modifier le proxy PDF pour privilégier `object_key` (et fallback sur `file_path` en dernier recours).

## Checklist de validation
- Les nouveaux uploads créent des `object_key` conformes.
- Le proxy PDF lit via `object_key` sans heuristique.
- Aucun nouveau `s3://...` n’est stocké en base.
- Les back-ups sont rangés sous `backups/...`.
