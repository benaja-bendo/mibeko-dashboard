# Processus : création d’un document → extraction PDF → import DB → embeddings (RAG)

Ce document décrit, de bout en bout, ce qui se passe quand on crée un nouveau **LegalDocument** dans le backoffice Laravel, jusqu’à l’extraction PDF (service Python), l’import structuré en base (PostgreSQL), puis la génération des embeddings (RAG).

## Éléments obligatoires (et où ils vont)

### 1) Métadonnées du document (table `legal_documents`)

Lors de la création via le backoffice, l’endpoint de création valide au minimum :

- `type_code` (obligatoire) → `legal_documents.type_code` (FK vers `document_types.code`)
- `institution_id` (obligatoire) → `legal_documents.institution_id` (FK vers `institutions.id`)
- `titre_officiel` (obligatoire) → `legal_documents.titre_officiel`
- `curation_status` (obligatoire, enum) → `legal_documents.curation_status` (`draft|review|validated|published`)

Optionnel :

- `reference_nor` → `legal_documents.reference_nor`
- `date_publication` → `legal_documents.date_publication`
- `file` (PDF) → stockage MinIO + ligne dans `media_files`

Référence code : [CurationController.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Http/Controllers/CurationController.php)

### 2) Fichiers média (table `media_files`)

Un document peut avoir plusieurs fichiers rattachés :

- PDF original (upload) : `mime_type = application/pdf`
- Markdown extrait : `mime_type = text/markdown`
- JSON structuré : `mime_type = application/json`

`media_files.file_path` contient un chemin objet S3/MinIO (ex: `documents/pdfs/...` ou `documents/jsons/...`).

Référence schéma : [pgsql-schema.sql](../database/schema/pgsql-schema.sql)

## Contrats d’échange (RabbitMQ)

### File “tâches” (Laravel → Worker extraction)

Queue : `pdf_extraction_tasks`\
Payload :

```json
{
  "task_id": "<uuid du legal_documents.id>",
  "filename": "<chemin objet MinIO du PDF>"
}
```

Références :

- Publication Laravel : [CurationController.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Http/Controllers/CurationController.php#L81-L127)
- Consommation Worker : [worker.py](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-extraction/app/worker.py#L123-L176)

### File “statuts” (Worker extraction → Laravel)

Queue : `pdf_extraction_status`\
Payload :

```json
{
  "task_id": "<uuid du legal_documents.id>",
  "status": "processing|completed|failed",
  "message": "<texte libre>",
  "result_paths": {
    "markdown": "documents/extractions/<base>.md",
    "json": "documents/jsons/<base>.json",
    "bucket": "<bucket destination>"
  }
}
```

Références :

- Publication Worker : [rabbitmq\_client.py](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-extraction/app/services/rabbitmq_client.py#L51-L83)
- Consommation Laravel : [ConsumeExtractionStatus.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Console/Commands/ConsumeExtractionStatus.php#L34-L118)

## Vue globale (diagramme de séquence)

```mermaid
sequenceDiagram
    autonumber
    actor Curateur as Curateur (UI Inertia)
    participant Laravel as Laravel (Backoffice)
    participant DB as PostgreSQL
    participant MinIO as MinIO (S3)
    participant MQ as RabbitMQ
    participant Worker as Service extraction (Python)
    participant MinerU as MinerU API
    participant RAG as Génération embeddings (Laravel AI)

    Curateur->>Laravel: POST /curation (type, institution, titre, PDF?)
    Laravel->>DB: INSERT legal_documents (curation_status=draft/...)
    opt PDF fourni
        Laravel->>MinIO: Upload PDF (S3 disk) → documents/pdfs/<fichier>.pdf
        Laravel->>DB: INSERT media_files (pdf)
        Laravel->>DB: UPDATE legal_documents.extraction_status = "processing"
        Laravel->>MQ: publish pdf_extraction_tasks {task_id, filename}
    end

    Worker->>MQ: consume pdf_extraction_tasks
    Worker->>MQ: publish pdf_extraction_status {status="processing"}
    Worker->>MinIO: download PDF (bucket source)
    Worker->>MinerU: extract_pdf(PDF)
    MinerU-->>Worker: markdown + metadata
    Worker->>Worker: clean MD + transform → JSON structuré
    Worker->>MinIO: upload MD (bucket dest) → documents/extractions/<base>.md
    Worker->>MinIO: upload JSON (bucket dest) → documents/jsons/<base>.json
    Worker->>MQ: publish pdf_extraction_status {status="completed", result_paths}

    Laravel->>MQ: consume pdf_extraction_status
    Laravel->>DB: UPDATE legal_documents.extraction_status = status
    alt status == completed
        Laravel->>DB: INSERT media_files (md + json)
        Laravel->>MinIO: GET documents/jsons/<base>.json
        Laravel->>DB: INSERT structure_nodes + articles + article_versions
        note over Laravel,DB: Import en transaction + embeddings désactivés pendant l’import
        Laravel-->>Curateur: Broadcast (DocumentExtractionUpdated)
    else status == failed
        Laravel-->>Curateur: Broadcast (DocumentExtractionUpdated)
    end

    Note over RAG: Plus tard (scheduler / manuel)
    RAG->>DB: SELECT article_versions WHERE embedding IS NULL
    RAG->>RAG: Embeddings::for(texts)->generate()
    RAG->>DB: UPDATE article_versions.embedding
```

## Détail : création côté Laravel (flowchart)

```mermaid
flowchart TD
    A[Requête création document] --> B[Validation champs obligatoires]
    B --> C[CREATE legal_documents]
    C --> D{PDF uploadé ?}
    D -- Non --> Z["Redirection vers workstation (sans extraction)"]
    D -- Oui --> E[Upload PDF vers MinIO via disk S3]
    E --> F["CREATE media_files (pdf)"]
    F --> G[SET legal_documents.extraction_status = processing]
    G --> H[Publish RabbitMQ: pdf_extraction_tasks]
    H --> I[Redirection vers workstation]

```

Point clé : sans PDF, le document existe mais il n’y a pas de pipeline d’extraction automatique déclenché.

Référence : [CurationController.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Http/Controllers/CurationController.php#L81-L127)

## Détail : consommation des statuts + import structuré (flowchart)

```mermaid
flowchart TD
    A[Message RabbitMQ pdf_extraction_status] --> B[Lire task_id + status]
    B --> C{LegalDocument existe ?}
    C -- Non --> X[Log warning + ack]
    C -- Oui --> D[UPDATE legal_documents.extraction_status = status]
    D --> E{status == completed ?}
    E -- Non --> H[Broadcast DocumentExtractionUpdated]
    E -- Oui --> F[Créer media_files MD/JSON si présent]
    F --> G[GET JSON sur MinIO]
    G --> I{JSON valide ?}
    I -- Non --> J[Log error]
    I -- Oui --> K[Désactiver embeddings pendant import]
    K --> L["Transaction: importContent(document, json)"]
    L --> M[Réactiver embeddings]
    M --> H[Broadcast DocumentExtractionUpdated]
```

Références :

- Consommation + import : [ConsumeExtractionStatus.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Console/Commands/ConsumeExtractionStatus.php#L34-L118)
- Import JSON → tables : [DocumentImportService.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Services/DocumentImportService.php)
- Skip embeddings (bulk import) : [ArticleVersionObserver.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Observers/ArticleVersionObserver.php#L11-L31)

## Contrat JSON d’extraction (ce que Laravel sait importer)

Laravel sait importer plusieurs formes, mais le service Python produit principalement une structure de type :

- racine : `{"textes": [...]}`
- chaque texte contient : `numero_texte`, `intitule_long`, `contenu`
- `contenu` est une hiérarchie de divisions (Titre/Chapitre/Section/...) et d’articles

Références :

- Production JSON : [json\_transformer.py](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-extraction/app/services/json_transformer.py#L473-L503)
- Import (branche `textes`) : [DocumentImportService.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Services/DocumentImportService.php#L24-L46)

## États (diagramme d’états)

### `legal_documents.extraction_status`

```mermaid
stateDiagram-v2
    [*] --> no_pdf: Document sans PDF
    no_pdf --> processing: PDF uploadé + task publiée
    processing --> completed: Worker OK + status MQ
    processing --> failed: Worker KO + status MQ
    failed --> processing: Relance manuelle (re-publish task)
    completed --> [*]
```

### `legal_documents.curation_status`

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> review
    review --> validated
    validated --> published
    published --> [*]
    note right of published
        Blocage: publication d'un document vide (0 articles)
        sauf titres "Codes Populaires"
    end note
```

Référence règle de publication : [CurationController.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Http/Controllers/CurationController.php#L200-L212)

## Modèle de données minimal (ERD)

```mermaid
erDiagram
    DOCUMENT_TYPES ||--o{ LEGAL_DOCUMENTS : "type_code"
    INSTITUTIONS ||--o{ LEGAL_DOCUMENTS : "institution_id"
    LEGAL_DOCUMENTS ||--o{ MEDIA_FILES : "document_id"
    LEGAL_DOCUMENTS ||--o{ STRUCTURE_NODES : "document_id"
    LEGAL_DOCUMENTS ||--o{ ARTICLES : "document_id"
    STRUCTURE_NODES ||--o{ ARTICLES : "parent_node_id"
    ARTICLES ||--o{ ARTICLE_VERSIONS : "article_id"

    DOCUMENT_TYPES {
      varchar code PK
      varchar nom
    }
    INSTITUTIONS {
      uuid id PK
      varchar nom
    }
    LEGAL_DOCUMENTS {
      uuid id PK
      varchar type_code FK
      uuid institution_id FK
      text titre_officiel
      varchar curation_status
      varchar extraction_status
      date date_publication
    }
    MEDIA_FILES {
      uuid id PK
      uuid document_id FK
      varchar file_path
      varchar mime_type
    }
    STRUCTURE_NODES {
      uuid id PK
      uuid document_id FK
      varchar type_unite
      ltree tree_path
      int sort_order
      varchar validation_status
    }
    ARTICLES {
      uuid id PK
      uuid document_id FK
      uuid parent_node_id FK
      varchar numero_article
      int ordre_affichage
      varchar validation_status
    }
    ARTICLE_VERSIONS {
      uuid id PK
      uuid article_id FK
      daterange validity_period
      text contenu_texte
      vector embedding
    }
```

## Où interviennent les embeddings (RAG) ?

Il y a deux mécanismes complémentaires :

1. **Auto-embedding** lors des éditions unitaires (observer)\
   Quand un `article_versions.contenu_texte` change, l’observer génère un embedding et le sauvegarde.

Référence : [ArticleVersionObserver.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Observers/ArticleVersionObserver.php#L18-L58)

1. **Batch embeddings** (commande)\
   Après un import massif (où l’observer est temporairement désactivé), la commande `mibeko:process-rag` rattrape les versions manquantes.

Référence : [GenerateEmbeddingsCommand.php](file:///Users/benji_mac/Desktop/Mibeko/mibeko/mibeko-tableau-de-bord/app/Console/Commands/GenerateEmbeddingsCommand.php)
