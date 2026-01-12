-- ===========================================================
-- INITIALISATION DES EXTENSIONS
-- ===========================================================
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";   -- Pour les IDs uniques
CREATE EXTENSION IF NOT EXISTS "ltree";       -- Pour la hiérarchie performante
CREATE EXTENSION IF NOT EXISTS "vector";      -- Pour la recherche sémantique (RAG)
CREATE EXTENSION IF NOT EXISTS "btree_gist";  -- Pour les contraintes de temps (exclude)

-- NETTOYAGE (Attention: supprime les données existantes)
DROP TABLE IF EXISTS audits CASCADE;
DROP TABLE IF EXISTS personal_access_tokens CASCADE;
DROP TABLE IF EXISTS failed_jobs CASCADE;
DROP TABLE IF EXISTS job_batches CASCADE;
DROP TABLE IF EXISTS jobs CASCADE;
DROP TABLE IF EXISTS cache_locks CASCADE;
DROP TABLE IF EXISTS cache CASCADE;
DROP TABLE IF EXISTS sessions CASCADE;
DROP TABLE IF EXISTS password_reset_tokens CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS migrations CASCADE;

DROP TABLE IF EXISTS taggables CASCADE;
DROP TABLE IF EXISTS article_tag CASCADE; -- Old table
DROP TABLE IF EXISTS tags CASCADE;
DROP TABLE IF EXISTS curation_flags CASCADE;
DROP TABLE IF EXISTS document_relations CASCADE;
DROP TABLE IF EXISTS article_versions CASCADE;
DROP TABLE IF EXISTS articles CASCADE;
DROP TABLE IF EXISTS structure_nodes CASCADE;
DROP TABLE IF EXISTS media_files CASCADE;
DROP TABLE IF EXISTS legal_documents CASCADE;
DROP TABLE IF EXISTS institutions CASCADE;
DROP TABLE IF EXISTS document_types CASCADE;


-- ===========================================================
-- 0. TABLES SYSTÈME (LARAVEL DEFAULT)
-- Ces tables sont nécessaires pour le fonctionnement de Laravel,
-- l'authentification (Fortify), les sessions, le cache, etc.
-- ===========================================================

-- Table de suivi des migrations Laravel
CREATE TABLE IF NOT EXISTS migrations (
    id SERIAL PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INTEGER NOT NULL
);

-- Utilisateurs (Authentification + 2FA Fortify)
CREATE TABLE IF NOT EXISTS users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE,
    password VARCHAR(255) NOT NULL,
    two_factor_secret TEXT,
    two_factor_recovery_codes TEXT,
    two_factor_confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE,
    remember_token VARCHAR(100),
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    deleted_at TIMESTAMP(0) WITHOUT TIME ZONE
);

-- Réinitialisation de mot de passe
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    email VARCHAR(255) PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE
);

-- Sessions (si driver database utilisé)
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(255) PRIMARY KEY,
    user_id UUID,
    ip_address VARCHAR(45),
    user_agent TEXT,
    payload TEXT NOT NULL,
    last_activity INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_last_activity ON sessions(last_activity);

-- Cache (si driver database utilisé)
CREATE TABLE IF NOT EXISTS cache (
    key VARCHAR(255) PRIMARY KEY,
    value TEXT NOT NULL,
    expiration INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS cache_locks (
    key VARCHAR(255) PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    expiration INTEGER NOT NULL
);

-- Jobs / Files d'attente
CREATE TABLE IF NOT EXISTS jobs (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL,
    reserved_at INTEGER,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_jobs_queue ON jobs(queue);

CREATE TABLE IF NOT EXISTS job_batches (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    total_jobs INTEGER NOT NULL,
    pending_jobs INTEGER NOT NULL,
    failed_jobs INTEGER NOT NULL,
    failed_job_ids TEXT NOT NULL,
    options TEXT,
    cancelled_at INTEGER,
    created_at INTEGER NOT NULL,
    finished_at INTEGER
);

CREATE TABLE IF NOT EXISTS failed_jobs (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Personal Access Tokens (Sanctum)
CREATE TABLE IF NOT EXISTS personal_access_tokens (
    id BIGSERIAL PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id UUID NOT NULL,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    abilities TEXT,
    last_used_at TIMESTAMP(0) WITHOUT TIME ZONE,
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX IF NOT EXISTS idx_pat_tokenable ON personal_access_tokens(tokenable_type, tokenable_id);
CREATE INDEX IF NOT EXISTS idx_pat_expires_at ON personal_access_tokens(expires_at);

-- Audits (Laravel Auditing)
CREATE TABLE IF NOT EXISTS audits (
    id BIGSERIAL PRIMARY KEY,
    user_type VARCHAR(255),
    user_id UUID,
    event VARCHAR(255) NOT NULL,
    auditable_type VARCHAR(255) NOT NULL,
    auditable_id UUID NOT NULL,
    old_values TEXT,
    new_values TEXT,
    url TEXT,
    ip_address INET,
    user_agent VARCHAR(1023),
    tags VARCHAR(255),
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX IF NOT EXISTS idx_audits_user ON audits(user_id, user_type);
CREATE INDEX IF NOT EXISTS idx_audits_auditable ON audits(auditable_type, auditable_id);

-- ===========================================================
-- 1. TABLE : METADONNÉES DES DOCUMENTS (Le contenant)
-- ===========================================================
-- Types de textes (Lois, Codes, Décrets...)
CREATE TABLE document_types (
    code VARCHAR(10) PRIMARY KEY, -- LOI, DEC, ORD, CODE, CONST
    nom VARCHAR(50) NOT NULL,
    niveau_hierarchique INT DEFAULT 0, -- 1=Constitution, 2=Loi, etc.
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Institutions (Qui a émis le texte ?)
CREATE TABLE institutions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nom VARCHAR(200) NOT NULL,
    sigle VARCHAR(50),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Textes officiels (Les documents eux-mêmes)
CREATE TABLE legal_documents (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    type_code VARCHAR(10) REFERENCES document_types(code),
    institution_id UUID REFERENCES institutions(id),

    titre_officiel TEXT NOT NULL, -- "Loi n° 2024-..."
    reference_nor VARCHAR(50),    -- Numéro unique administratif si dispo

    date_signature DATE,
    date_publication DATE,
    date_entree_vigueur DATE,

    -- source_url supprimé, déplacé vers media_files

    statut VARCHAR(20) CHECK (statut IN ('vigueur', 'abroge', 'projet')) DEFAULT 'vigueur',
    curation_status VARCHAR(50) DEFAULT 'draft', -- draft, curated, published

    -- METADONNÉES FLEXIBLES (Spécifique Jurisprudence ou autre)
    -- Ex: {"parties": ["X vs Y"], "chambre": "Civile", "avocats": ["Me Dupont"], "numero_pourvoi": "12-34.567"}
    metadata JSONB DEFAULT '{}'::jsonb,

    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    deleted_at TIMESTAMP -- Soft Delete
);

-- Index pour rechercher rapidement dans le JSONB (ex: trouver un avocat ou une partie)
CREATE INDEX idx_legal_docs_metadata ON legal_documents USING GIN (metadata);

-- ===========================================================
-- 1.1 TABLE : FICHIERS MÉDIAS (PDFs, etc.)
-- ===========================================================
CREATE TABLE media_files (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    document_id UUID NOT NULL REFERENCES legal_documents(id) ON DELETE CASCADE,
    file_path VARCHAR(255) NOT NULL, -- Chemin S3 ou local
    mime_type VARCHAR(100),          -- application/pdf
    file_size BIGINT,
    description VARCHAR(255),        -- "Original signé", "Annexe 1"
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);


-- ===========================================================
-- 2. TABLE : SQUELETTE STRUCTUREL (Materialized Path)
-- C'est ici qu'on gère "Livre > Titre > Chapitre"
-- ===========================================================
CREATE TABLE structure_nodes (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    document_id UUID NOT NULL REFERENCES legal_documents(id) ON DELETE CASCADE,

    -- Le "Label" du noeud (ex: "Livre", "Titre", "Chapitre")
    type_unite VARCHAR(50) NOT NULL,

    -- Le numéro/titre (ex: "I", "Dispositions Générales", "Chapitre I", "Article 1", "Alinéa 1")
    numero VARCHAR(50),

    -- Le titre officiel (ex: "Dispositions Générales", "Chapitre I", "Article 1", "Alinéa 1")
    titre TEXT,

    -- LA MAGIE LTREE : Chemin matérialisé
    -- Exemple de path : "root.livre1.titre2.chap1"
    tree_path ltree NOT NULL,

    validation_status VARCHAR(50) DEFAULT 'pending', -- pending, in_progress, validated
    sort_order INTEGER DEFAULT 0,

    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Index pour rechercher instantanément tous les enfants d'un noeud
CREATE INDEX idx_structure_path ON structure_nodes USING GIST (tree_path);
CREATE INDEX idx_structure_doc ON structure_nodes(document_id);

-- ===========================================================
-- 3. TABLE : ARTICLES (L'identité stable)
-- L'article "12" existe toujours, même si son texte change.
-- ===========================================================
CREATE TABLE articles (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    document_id UUID NOT NULL REFERENCES legal_documents(id) ON DELETE CASCADE,

    -- Rattachement à la structure (peut être NULL si l'article est orphelin/préliminaire)
    parent_node_id UUID REFERENCES structure_nodes(id),

    numero_article VARCHAR(50) NOT NULL, -- "1", "2 bis", "Art. 4"
    ordre_affichage INT DEFAULT 0,

    validation_status VARCHAR(20) DEFAULT 'pending', -- pending, in_progress, validated

    deleted_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_articles_updated_at ON articles(updated_at);


-- ===========================================================
-- 4. TABLE : VERSIONS D'ARTICLES (Le contenu & RAG)
-- Gère l'historique et la recherche vectorielle
-- ===========================================================
CREATE TABLE article_versions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    article_id UUID NOT NULL REFERENCES articles(id) ON DELETE CASCADE,

    -- Période de validité (ex: version active de 2020 à 2024)
    validity_period DATERANGE NOT NULL,

    -- 1. Le contenu brut (affiché à l'utilisateur)
    contenu_texte TEXT NOT NULL,

    -- 2. Le contexte enrichi (ce qui a été vectorisé)
    -- Contient : "Titre Code > Livre > Chapitre > Contenu"
    -- C'est utile pour vérifier ce que l'IA "voit" vraiment.
    embedding_context TEXT,

    -- 3. Le Vecteur (Ada-002 ou text-embedding-3-small = 1536 dim)
    embedding VECTOR(1536),

    -- Recherche Full Text (Lexicale)
    search_tsv TSVECTOR,

    -- Métadonnées
    modifie_par_document_id UUID REFERENCES legal_documents(id), -- Document qui a modifié l'article
    validation_status VARCHAR(50) DEFAULT 'pending', -- pending, in_progress, validated
    is_verified BOOLEAN DEFAULT FALSE,

    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),

    -- Empêche le chevauchement de dates pour un même article
    EXCLUDE USING GIST (
        article_id WITH =,
        validity_period WITH &&
    )
);

-- Index pour la recherche textuelle classique (mots-clés)
CREATE INDEX idx_versions_search ON article_versions USING GIN(search_tsv);

-- Index pour la recherche vectorielle (IA) - CRITIQUE POUR LA VITESSE
-- lists = 100 est une bonne valeur par défaut pour < 1M lignes
CREATE INDEX idx_versions_embedding ON article_versions
USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);

-- ===========================================================
-- 5. TABLE : LIENS ET CITATIONS (Le Graph Juridique)
-- ===========================================================
CREATE TABLE document_relations (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    source_doc_id UUID REFERENCES legal_documents(id),
    target_doc_id UUID REFERENCES legal_documents(id),

    -- Si le lien est précis au niveau article
    source_article_id UUID REFERENCES articles(id),
    target_article_id UUID REFERENCES articles(id),

    relation_type VARCHAR(50) CHECK (relation_type IN ('MODIFIE', 'ABROGE', 'CITE', 'COMPLETE')),
    commentaire TEXT,

    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- ===========================================================
-- 6. TABLE : DRAPEAUX DE CURATION (Signalement d'erreurs)
-- ===========================================================
CREATE TABLE curation_flags (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),

    document_id UUID REFERENCES legal_documents(id),
    article_id UUID REFERENCES articles(id),

    type_probleme VARCHAR(50), -- 'scan_illisible', 'structure_cassee', 'doublon'
    description TEXT,
    resolved BOOLEAN DEFAULT FALSE,

    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- ===========================================================
-- 7. TAGS POLYMORPHES (Pour Sandrine / Simplification)
-- ===========================================================
CREATE TABLE tags (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL UNIQUE,
    slug VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE taggables (
    tag_id UUID NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    taggable_id UUID NOT NULL,
    taggable_type VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    PRIMARY KEY (tag_id, taggable_id, taggable_type)
);

CREATE INDEX idx_taggables_item ON taggables(taggable_id, taggable_type);

-- ===========================================================
-- 8. TRIGGERS : REFRESH SEARCH TSV (Full Text Search)
-- ===========================================================
CREATE OR REPLACE FUNCTION fn_refresh_article_version_tsv()
RETURNS TRIGGER AS $$
DECLARE
    article_id_to_update UUID;
    tags_text TEXT;
BEGIN
    -- Identify which article to update
    IF (TG_RELNAME = 'article_versions') THEN
        article_id_to_update := NEW.article_id;
    ELSE
        -- We are in taggables
        IF (TG_OP = 'DELETE') THEN
            IF (OLD.taggable_type != 'App\Models\Article') THEN RETURN NULL; END IF;
            article_id_to_update := OLD.taggable_id;
        ELSE
            IF (NEW.taggable_type != 'App\Models\Article') THEN RETURN NEW; END IF;
            article_id_to_update := NEW.taggable_id;
        END IF;
    END IF;

    -- Get all tags for this article
    SELECT COALESCE(string_agg(name, ' '), '') INTO tags_text
    FROM tags
    JOIN taggables ON tags.id = taggables.tag_id
    WHERE taggables.taggable_id = article_id_to_update
      AND taggables.taggable_type = 'App\Models\Article';

    -- Update search index
    IF (TG_RELNAME = 'article_versions') THEN
        NEW.search_tsv := (
            setweight(to_tsvector('french', COALESCE(NEW.contenu_texte, '')), 'A') ||
            setweight(to_tsvector('french', tags_text), 'B')
        );
        RETURN NEW;
    ELSE
        UPDATE article_versions
        SET search_tsv = (
            setweight(to_tsvector('french', COALESCE(contenu_texte, '')), 'A') ||
            setweight(to_tsvector('french', tags_text), 'B')
        )
        WHERE article_id = article_id_to_update;
        RETURN NULL;
    END IF;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_refresh_tsv_on_version
BEFORE INSERT OR UPDATE OF contenu_texte ON article_versions
FOR EACH ROW EXECUTE FUNCTION fn_refresh_article_version_tsv();

CREATE TRIGGER trg_refresh_tsv_on_tags
AFTER INSERT OR DELETE OR UPDATE ON taggables
FOR EACH ROW EXECUTE FUNCTION fn_refresh_article_version_tsv();
