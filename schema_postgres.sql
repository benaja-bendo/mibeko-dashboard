-- ===========================================================
-- INITIALISATION DES EXTENSIONS
-- ===========================================================
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";   -- Pour les IDs uniques
CREATE EXTENSION IF NOT EXISTS "ltree";       -- Pour la hiérarchie performante
CREATE EXTENSION IF NOT EXISTS "vector";      -- Pour la recherche sémantique (RAG)
CREATE EXTENSION IF NOT EXISTS "btree_gist";  -- Pour les contraintes de temps (exclude)

-- NETTOYAGE (Attention: supprime les données existantes)
DROP TABLE IF EXISTS failed_jobs CASCADE;
DROP TABLE IF EXISTS job_batches CASCADE;
DROP TABLE IF EXISTS jobs CASCADE;
DROP TABLE IF EXISTS cache_locks CASCADE;
DROP TABLE IF EXISTS cache CASCADE;
DROP TABLE IF EXISTS sessions CASCADE;
DROP TABLE IF EXISTS password_reset_tokens CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS migrations CASCADE;

DROP TABLE IF EXISTS curation_flags CASCADE;
DROP TABLE IF EXISTS document_relations CASCADE;
DROP TABLE IF EXISTS article_versions CASCADE;
DROP TABLE IF EXISTS articles CASCADE;
DROP TABLE IF EXISTS structure_nodes CASCADE;
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
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
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

-- ===========================================================
-- 1. TABLE : METADONNÉES DES DOCUMENTS (Le contenant)
-- Inspiré de schema02 pour la richesse
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

    source_url TEXT,              -- Lien PDF original
    statut VARCHAR(20) CHECK (statut IN ('vigueur', 'abroge', 'projet')) DEFAULT 'vigueur',
    curation_status VARCHAR(50) DEFAULT 'draft', -- draft, curated, published

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

    -- Le numéro/titre (ex: "I", "Dispositions Générales")
    numero VARCHAR(50),
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
    
    validation_status VARCHAR(20) DEFAULT 'pending',

    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- ===========================================================
-- 4. TABLE : VERSIONS D'ARTICLES (Le contenu & RAG)
-- Gère l'historique et la recherche vectorielle
-- ===========================================================
CREATE TABLE article_versions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    article_id UUID NOT NULL REFERENCES articles(id) ON DELETE CASCADE,

    -- Période de validité (Daterange est natif PG)
    -- '[2020-01-01, 2024-01-01)' veut dire valide de 2020 inclus à 2024 exclu.
    validity_period DATERANGE NOT NULL,

    -- Contenu
    contenu_texte TEXT NOT NULL,

    -- Recherche : Hybride (Full Text + Vector)
    search_tsv TSVECTOR GENERATED ALWAYS AS (to_tsvector('french', contenu_texte)) STORED,
    -- embedding VECTOR(1536), -- Dimension 1536 pour OpenAI ada-002 (à adapter selon votre modèle)

    -- Métadonnées de modification
    modifie_par_document_id UUID REFERENCES legal_documents(id), -- Quelle loi a créé cette version ?
    
    validation_status VARCHAR(50) DEFAULT 'pending' CHECK (validation_status IN ('pending', 'in_progress', 'validated', 'rejected')),

    is_verified BOOLEAN DEFAULT FALSE, -- Document validé (QA Status)

    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),

    -- CONTRAINTE D'INTEGRITÉ TEMPORELLE
    -- Empêche d'avoir deux versions valides en même temps pour le même article
    EXCLUDE USING GIST (
        article_id WITH =,
        validity_period WITH &&
    )
);

CREATE INDEX idx_versions_search ON article_versions USING GIN(search_tsv);
-- Index HNSW pour la recherche vectorielle rapide
-- CREATE INDEX idx_versions_embedding ON article_versions USING hnsw (embedding vector_cosine_ops);

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
