-- ===========================================================
-- INITIALISATION DES EXTENSIONS
-- ===========================================================
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";   -- Pour les IDs uniques
CREATE EXTENSION IF NOT EXISTS "ltree";       -- Pour la hiérarchie performante
-- CREATE EXTENSION IF NOT EXISTS "vector";      -- Pour la recherche sémantique (RAG)
CREATE EXTENSION IF NOT EXISTS "btree_gist";  -- Pour les contraintes de temps (exclude)

-- NETTOYAGE (Attention: supprime les données existantes)
DROP TABLE IF EXISTS document_relations CASCADE;
DROP TABLE IF EXISTS article_versions CASCADE;
DROP TABLE IF EXISTS articles CASCADE;
DROP TABLE IF EXISTS structure_nodes CASCADE;
DROP TABLE IF EXISTS legal_documents CASCADE;
DROP TABLE IF EXISTS institutions CASCADE;
DROP TABLE IF EXISTS document_types CASCADE;

-- ===========================================================
-- 1. TABLE : METADONNÉES DES DOCUMENTS (Le contenant)
-- Inspiré de schema02 pour la richesse
-- ===========================================================
-- Types de textes (Lois, Codes, Décrets...)
CREATE TABLE document_types (
    code VARCHAR(10) PRIMARY KEY, -- LOI, DEC, ORD, CODE, CONST
    nom VARCHAR(50) NOT NULL,
    niveau_hierarchique INT DEFAULT 0 -- 1=Constitution, 2=Loi, etc.
);
-- Institutions (Qui a émis le texte ?)
CREATE TABLE institutions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nom VARCHAR(200) NOT NULL,
    sigle VARCHAR(50)
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
    
    created_at TIMESTAMP DEFAULT NOW()
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
    
    created_at TIMESTAMP DEFAULT NOW()
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
    
    created_at TIMESTAMP DEFAULT NOW(),
    
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
    commentaire TEXT
);