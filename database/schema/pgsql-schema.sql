--
-- PostgreSQL database dump
--

\restrict 0tsx5cVhsiSjObk2g59QVzrZdFxzuwXpwDrdFpCNCgyYBrTxFwFnJsT5ecrtrXY

-- Dumped from database version 16.11 (Debian 16.11-1.pgdg12+1)
-- Dumped by pg_dump version 18.1

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: btree_gist; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public;


--
-- Name: EXTENSION btree_gist; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION btree_gist IS 'support for indexing common datatypes in GiST';


--
-- Name: ltree; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS ltree WITH SCHEMA public;


--
-- Name: EXTENSION ltree; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION ltree IS 'data type for hierarchical tree-like structures';


--
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;


--
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


--
-- Name: vector; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;


--
-- Name: EXTENSION vector; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION vector IS 'vector data type and ivfflat and hnsw access methods';


--
-- Name: fn_enforce_article_parent_same_document(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_enforce_article_parent_same_document() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    parent_document_id UUID;
BEGIN
    IF NEW.parent_node_id IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT document_id INTO parent_document_id
    FROM structure_nodes
    WHERE id = NEW.parent_node_id;

    IF parent_document_id IS NULL THEN
        RAISE EXCEPTION 'parent_node_id % inexistant', NEW.parent_node_id;
    END IF;

    IF parent_document_id <> NEW.document_id THEN
        RAISE EXCEPTION 'parent_node_id % appartient au document %, mais l''article appartient au document %', NEW.parent_node_id, parent_document_id, NEW.document_id;
    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: FUNCTION fn_enforce_article_parent_same_document(); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.fn_enforce_article_parent_same_document() IS 'Empêche de référencer un structure_nodes d''un autre document via articles.parent_node_id (source d''incohérences lors de l''import PDF).';


--
-- Name: fn_refresh_article_version_tsv(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_refresh_article_version_tsv() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    article_id_to_update UUID;
    tags_text TEXT;
BEGIN
    IF (TG_RELNAME = 'article_versions') THEN
        article_id_to_update := NEW.article_id;
    ELSE
        IF (TG_OP = 'DELETE') THEN
            IF (OLD.taggable_type != 'App\Models\Article') THEN RETURN NULL; END IF;
            article_id_to_update := OLD.taggable_id;
        ELSE
            IF (NEW.taggable_type != 'App\Models\Article') THEN RETURN NEW; END IF;
            article_id_to_update := NEW.taggable_id;
        END IF;
    END IF;

    SELECT COALESCE(string_agg(name, ' '), '') INTO tags_text
    FROM tags
             JOIN taggables ON tags.id = taggables.tag_id
    WHERE taggables.taggable_id = article_id_to_update
      AND taggables.taggable_type = 'App\Models\Article';

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
$$;


--
-- Name: FUNCTION fn_refresh_article_version_tsv(); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.fn_refresh_article_version_tsv() IS 'Met à jour search_tsv sur article_versions lors des INSERT/UPDATE de contenu_texte et lors des changements de tags pour les entités Article.';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: agent_conversation_messages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agent_conversation_messages (
    id character varying(36) NOT NULL,
    conversation_id character varying(36),
    user_id uuid,
    agent character varying(255),
    role character varying(25),
    content text,
    attachments text,
    tool_calls text,
    tool_results text,
    usage text,
    meta text,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: agent_conversations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agent_conversations (
    id character varying(36) NOT NULL,
    user_id uuid,
    title character varying(255),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: article_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.article_versions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    article_id uuid NOT NULL,
    validity_period daterange DEFAULT daterange(CURRENT_DATE, 'infinity'::date, '[)'::text) NOT NULL,
    contenu_texte text NOT NULL,
    embedding_context text,
    embedding public.vector(1024),
    search_tsv tsvector,
    modifie_par_document_id uuid,
    source_run_id uuid,
    source_media_file_id uuid,
    source_locator jsonb DEFAULT '{}'::jsonb,
    validation_status character varying(255) DEFAULT 'pending'::character varying,
    is_verified boolean DEFAULT false,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_article_versions_validity_not_empty CHECK ((NOT isempty(validity_period)))
);


--
-- Name: COLUMN article_versions.validity_period; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.article_versions.validity_period IS 'Période de validité (daterange) : si inconnue au moment de l''ingestion PDF/OCR, l''application peut laisser la valeur par défaut daterange(CURRENT_DATE, ''infinity'', ''[)'') afin que Postgres accepte l''insert. La période doit ensuite être affinée/validée (juriste/IA) en UPDATE sur la version concernée pour éviter des conflits avec la contrainte EXCLUDE (chevauchements).';


--
-- Name: articles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.articles (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    document_id uuid NOT NULL,
    parent_node_id uuid,
    numero_article character varying(50) NOT NULL,
    ordre_affichage integer DEFAULT 0,
    validation_status character varying(20) DEFAULT 'pending'::character varying,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: audits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audits (
    id bigint NOT NULL,
    user_type character varying(255),
    user_id uuid,
    event character varying(255) NOT NULL,
    auditable_type character varying(255) NOT NULL,
    auditable_id uuid NOT NULL,
    old_values text,
    new_values text,
    url text,
    ip_address inet,
    user_agent character varying(1023),
    tags character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: audits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audits_id_seq OWNED BY public.audits.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: curation_flags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.curation_flags (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    document_id uuid,
    article_id uuid,
    type_probleme character varying(50),
    description text,
    resolved boolean DEFAULT false,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: devices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.devices (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    device_id character varying(255) NOT NULL,
    push_token character varying(255),
    platform character varying(255),
    status character varying(255),
    last_registered_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: document_relations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_relations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    source_doc_id uuid,
    target_doc_id uuid,
    source_article_id uuid,
    target_article_id uuid,
    relation_type character varying(50),
    commentaire text,
    effective_date date,
    confidence numeric(5,4),
    meta jsonb DEFAULT '{}'::jsonb,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_document_relations_endpoints CHECK ((((source_doc_id IS NOT NULL) OR (source_article_id IS NOT NULL)) AND ((target_doc_id IS NOT NULL) OR (target_article_id IS NOT NULL)))),
    CONSTRAINT document_relations_confidence_check CHECK (((confidence >= (0)::numeric) AND (confidence <= (1)::numeric))),
    CONSTRAINT document_relations_relation_type_check CHECK (((relation_type)::text = ANY ((ARRAY['CREE'::character varying, 'MODIFIE'::character varying, 'ABROGE'::character varying, 'CITE'::character varying, 'COMPLETE'::character varying, 'RENUMEROTE'::character varying])::text[])))
);


--
-- Name: document_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_types (
    code character varying(10) NOT NULL,
    nom character varying(50) NOT NULL,
    niveau_hierarchique integer DEFAULT 0,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: extraction_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.extraction_runs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    document_id uuid NOT NULL,
    source character varying(50) DEFAULT 'MINERU'::character varying NOT NULL,
    status character varying(20) DEFAULT 'queued'::character varying NOT NULL,
    started_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    finished_at timestamp(0) without time zone,
    source_media_file_id uuid,
    markdown_media_file_id uuid,
    json_media_file_id uuid,
    meta jsonb DEFAULT '{}'::jsonb,
    CONSTRAINT extraction_runs_source_check CHECK (((source)::text = ANY ((ARRAY['MINERU'::character varying, 'MANUAL_UPLOAD'::character varying, 'PARSING'::character varying])::text[]))),
    CONSTRAINT extraction_runs_status_check CHECK (((status)::text = ANY ((ARRAY['queued'::character varying, 'running'::character varying, 'succeeded'::character varying, 'failed'::character varying, 'partial'::character varying])::text[])))
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: institutions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.institutions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    nom character varying(200) NOT NULL,
    sigle character varying(50),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: legal_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_documents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    type_code character varying(10),
    institution_id uuid,
    official_journal_id uuid,
    document_key text,
    document_role character varying(20) DEFAULT 'FLUX'::character varying NOT NULL,
    consolidation_as_of date,
    stock_code character varying(100),
    titre_officiel text NOT NULL,
    reference_nor character varying(50),
    date_signature date,
    date_publication date,
    date_entree_vigueur date,
    statut character varying(20) DEFAULT 'vigueur'::character varying,
    curation_status character varying(255) DEFAULT 'draft'::character varying,
    extraction_status character varying(20),
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT chk_legal_documents_role_logic CHECK (((((document_role)::text = 'STOCK'::text) AND (consolidation_as_of IS NOT NULL) AND (official_journal_id IS NULL) AND (stock_code IS NOT NULL)) OR (((document_role)::text = 'FLUX'::text) AND (consolidation_as_of IS NULL)))),
    CONSTRAINT legal_documents_document_role_check CHECK (((document_role)::text = ANY ((ARRAY['STOCK'::character varying, 'FLUX'::character varying])::text[]))),
    CONSTRAINT legal_documents_statut_check CHECK (((statut)::text = ANY ((ARRAY['vigueur'::character varying, 'abroge'::character varying, 'projet'::character varying])::text[])))
);


--
-- Name: media_files; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media_files (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    document_id uuid NOT NULL,
    file_path character varying(512) NOT NULL,
    storage_provider character varying(20) DEFAULT 'MINIO'::character varying NOT NULL,
    bucket_name character varying(100) DEFAULT 'mibeko-documents'::character varying NOT NULL,
    object_key character varying(512) NOT NULL,
    original_filename character varying(255),
    mime_type character varying(100),
    file_category character varying(50) NOT NULL,
    file_size bigint,
    checksum_sha256 character varying(64),
    description character varying(255),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT media_files_file_category_check CHECK (((file_category)::text = ANY ((ARRAY['SOURCE_PDF'::character varying, 'EXTRACTION_MARKDOWN'::character varying, 'EXTRACTION_JSON'::character varying])::text[])))
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: mobile_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mobile_profiles (
    id bigint NOT NULL,
    user_id uuid,
    phone character varying(255),
    dob date,
    gender character varying(255),
    profession character varying(255),
    company character varying(255),
    legal_interests text,
    app_preferences json,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: mobile_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.mobile_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: mobile_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.mobile_profiles_id_seq OWNED BY public.mobile_profiles.id;


--
-- Name: model_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id uuid NOT NULL
);


--
-- Name: model_has_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id uuid NOT NULL
);


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    user_id uuid,
    title character varying(255) NOT NULL,
    message text NOT NULL,
    type character varying(255),
    data json,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: official_journals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.official_journals (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    title character varying(255) NOT NULL,
    publication_date date NOT NULL,
    file_path character varying(255) NOT NULL,
    transcription_status character varying(255),
    is_published boolean DEFAULT false,
    number character varying(255),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0) without time zone
);


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id uuid NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: role_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id uuid,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: structure_nodes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.structure_nodes (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    document_id uuid NOT NULL,
    type_unite character varying(50) NOT NULL,
    numero character varying(50),
    titre text,
    tree_path public.ltree NOT NULL,
    validation_status character varying(255) DEFAULT 'pending'::character varying,
    sort_order integer DEFAULT 0,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: taggables; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.taggables (
    tag_id uuid NOT NULL,
    taggable_id uuid NOT NULL,
    taggable_type character varying(255) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: tags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tags (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    two_factor_secret text,
    two_factor_recovery_codes text,
    two_factor_confirmed_at timestamp(0) without time zone,
    remember_token character varying(100),
    status character varying(255),
    last_seen_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: audits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audits ALTER COLUMN id SET DEFAULT nextval('public.audits_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: mobile_profiles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mobile_profiles ALTER COLUMN id SET DEFAULT nextval('public.mobile_profiles_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: agent_conversation_messages agent_conversation_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_conversation_messages
    ADD CONSTRAINT agent_conversation_messages_pkey PRIMARY KEY (id);


--
-- Name: agent_conversations agent_conversations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_conversations
    ADD CONSTRAINT agent_conversations_pkey PRIMARY KEY (id);


--
-- Name: article_versions article_versions_article_id_validity_period_excl; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.article_versions
    ADD CONSTRAINT article_versions_article_id_validity_period_excl EXCLUDE USING gist (article_id WITH =, validity_period WITH &&);


--
-- Name: article_versions article_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.article_versions
    ADD CONSTRAINT article_versions_pkey PRIMARY KEY (id);


--
-- Name: articles articles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.articles
    ADD CONSTRAINT articles_pkey PRIMARY KEY (id);


--
-- Name: audits audits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audits
    ADD CONSTRAINT audits_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: curation_flags curation_flags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curation_flags
    ADD CONSTRAINT curation_flags_pkey PRIMARY KEY (id);


--
-- Name: devices devices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.devices
    ADD CONSTRAINT devices_pkey PRIMARY KEY (id);


--
-- Name: document_relations document_relations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_relations
    ADD CONSTRAINT document_relations_pkey PRIMARY KEY (id);


--
-- Name: document_types document_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_types
    ADD CONSTRAINT document_types_pkey PRIMARY KEY (code);


--
-- Name: extraction_runs extraction_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.extraction_runs
    ADD CONSTRAINT extraction_runs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_key UNIQUE (uuid);


--
-- Name: institutions institutions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.institutions
    ADD CONSTRAINT institutions_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: legal_documents legal_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_documents
    ADD CONSTRAINT legal_documents_pkey PRIMARY KEY (id);


--
-- Name: media_files media_files_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_files
    ADD CONSTRAINT media_files_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: mobile_profiles mobile_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mobile_profiles
    ADD CONSTRAINT mobile_profiles_pkey PRIMARY KEY (id);


--
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: official_journals official_journals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.official_journals
    ADD CONSTRAINT official_journals_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_key UNIQUE (token);


--
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: structure_nodes structure_nodes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.structure_nodes
    ADD CONSTRAINT structure_nodes_pkey PRIMARY KEY (id);


--
-- Name: taggables taggables_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.taggables
    ADD CONSTRAINT taggables_pkey PRIMARY KEY (tag_id, taggable_id, taggable_type);


--
-- Name: tags tags_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tags
    ADD CONSTRAINT tags_name_key UNIQUE (name);


--
-- Name: tags tags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tags
    ADD CONSTRAINT tags_pkey PRIMARY KEY (id);


--
-- Name: tags tags_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tags
    ADD CONSTRAINT tags_slug_key UNIQUE (slug);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: idx_articles_updated_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_articles_updated_at ON public.articles USING btree (updated_at);


--
-- Name: idx_audits_auditable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audits_auditable ON public.audits USING btree (auditable_type, auditable_id);


--
-- Name: idx_audits_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audits_user ON public.audits USING btree (user_id, user_type);


--
-- Name: idx_document_relations_meta; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_document_relations_meta ON public.document_relations USING gin (meta);


--
-- Name: idx_extraction_runs_meta; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_extraction_runs_meta ON public.extraction_runs USING gin (meta);


--
-- Name: idx_jobs_queue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_jobs_queue ON public.jobs USING btree (queue);


--
-- Name: idx_legal_docs_metadata; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_legal_docs_metadata ON public.legal_documents USING gin (metadata);


--
-- Name: idx_pat_expires_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pat_expires_at ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: idx_pat_tokenable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pat_tokenable ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: idx_sessions_last_activity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sessions_last_activity ON public.sessions USING btree (last_activity);


--
-- Name: idx_sessions_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sessions_user_id ON public.sessions USING btree (user_id);


--
-- Name: idx_structure_doc; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_structure_doc ON public.structure_nodes USING btree (document_id);


--
-- Name: idx_structure_path; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_structure_path ON public.structure_nodes USING gist (tree_path);


--
-- Name: idx_taggables_item; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_taggables_item ON public.taggables USING btree (taggable_id, taggable_type);


--
-- Name: idx_versions_embedding; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_versions_embedding ON public.article_versions USING hnsw (embedding public.vector_cosine_ops) WITH (m='16', ef_construction='64');


--
-- Name: idx_versions_search; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_versions_search ON public.article_versions USING gin (search_tsv);


--
-- Name: uq_articles_document_numero; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_articles_document_numero ON public.articles USING btree (document_id, numero_article) WHERE (deleted_at IS NULL);


--
-- Name: uq_legal_documents_document_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_legal_documents_document_key ON public.legal_documents USING btree (document_key) WHERE ((document_key IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: uq_legal_documents_reference_nor; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_legal_documents_reference_nor ON public.legal_documents USING btree (reference_nor) WHERE ((reference_nor IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: uq_legal_documents_stock_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_legal_documents_stock_code ON public.legal_documents USING btree (stock_code) WHERE ((stock_code IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: uq_media_files_document_object_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_media_files_document_object_key ON public.media_files USING btree (document_id, object_key);


--
-- Name: uq_official_journals_pubdate_number; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_official_journals_pubdate_number ON public.official_journals USING btree (publication_date, number) WHERE ((number IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: uq_structure_nodes_document_path; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_structure_nodes_document_path ON public.structure_nodes USING btree (document_id, tree_path);


--
-- Name: articles trg_articles_parent_node_same_document; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_articles_parent_node_same_document BEFORE INSERT OR UPDATE OF parent_node_id, document_id ON public.articles FOR EACH ROW EXECUTE FUNCTION public.fn_enforce_article_parent_same_document();


--
-- Name: taggables trg_refresh_tsv_on_tags; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_refresh_tsv_on_tags AFTER INSERT OR DELETE OR UPDATE ON public.taggables FOR EACH ROW EXECUTE FUNCTION public.fn_refresh_article_version_tsv();


--
-- Name: article_versions trg_refresh_tsv_on_version; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_refresh_tsv_on_version BEFORE INSERT OR UPDATE OF contenu_texte ON public.article_versions FOR EACH ROW EXECUTE FUNCTION public.fn_refresh_article_version_tsv();


--
-- Name: agent_conversation_messages agent_conversation_messages_conversation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_conversation_messages
    ADD CONSTRAINT agent_conversation_messages_conversation_id_fkey FOREIGN KEY (conversation_id) REFERENCES public.agent_conversations(id) ON DELETE CASCADE;


--
-- Name: agent_conversation_messages agent_conversation_messages_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_conversation_messages
    ADD CONSTRAINT agent_conversation_messages_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: agent_conversations agent_conversations_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_conversations
    ADD CONSTRAINT agent_conversations_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: article_versions article_versions_article_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.article_versions
    ADD CONSTRAINT article_versions_article_id_fkey FOREIGN KEY (article_id) REFERENCES public.articles(id) ON DELETE CASCADE;


--
-- Name: article_versions article_versions_modifie_par_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.article_versions
    ADD CONSTRAINT article_versions_modifie_par_document_id_fkey FOREIGN KEY (modifie_par_document_id) REFERENCES public.legal_documents(id);


--
-- Name: article_versions article_versions_source_media_file_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.article_versions
    ADD CONSTRAINT article_versions_source_media_file_id_fkey FOREIGN KEY (source_media_file_id) REFERENCES public.media_files(id) ON DELETE SET NULL;


--
-- Name: article_versions article_versions_source_run_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.article_versions
    ADD CONSTRAINT article_versions_source_run_id_fkey FOREIGN KEY (source_run_id) REFERENCES public.extraction_runs(id) ON DELETE SET NULL;


--
-- Name: articles articles_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.articles
    ADD CONSTRAINT articles_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.legal_documents(id) ON DELETE CASCADE;


--
-- Name: articles articles_parent_node_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.articles
    ADD CONSTRAINT articles_parent_node_id_fkey FOREIGN KEY (parent_node_id) REFERENCES public.structure_nodes(id);


--
-- Name: curation_flags curation_flags_article_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curation_flags
    ADD CONSTRAINT curation_flags_article_id_fkey FOREIGN KEY (article_id) REFERENCES public.articles(id);


--
-- Name: curation_flags curation_flags_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curation_flags
    ADD CONSTRAINT curation_flags_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.legal_documents(id);


--
-- Name: document_relations document_relations_source_article_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_relations
    ADD CONSTRAINT document_relations_source_article_id_fkey FOREIGN KEY (source_article_id) REFERENCES public.articles(id);


--
-- Name: document_relations document_relations_source_doc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_relations
    ADD CONSTRAINT document_relations_source_doc_id_fkey FOREIGN KEY (source_doc_id) REFERENCES public.legal_documents(id);


--
-- Name: document_relations document_relations_target_article_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_relations
    ADD CONSTRAINT document_relations_target_article_id_fkey FOREIGN KEY (target_article_id) REFERENCES public.articles(id);


--
-- Name: document_relations document_relations_target_doc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_relations
    ADD CONSTRAINT document_relations_target_doc_id_fkey FOREIGN KEY (target_doc_id) REFERENCES public.legal_documents(id);


--
-- Name: extraction_runs extraction_runs_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.extraction_runs
    ADD CONSTRAINT extraction_runs_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.legal_documents(id) ON DELETE CASCADE;


--
-- Name: extraction_runs extraction_runs_json_media_file_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.extraction_runs
    ADD CONSTRAINT extraction_runs_json_media_file_id_fkey FOREIGN KEY (json_media_file_id) REFERENCES public.media_files(id) ON DELETE SET NULL;


--
-- Name: extraction_runs extraction_runs_markdown_media_file_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.extraction_runs
    ADD CONSTRAINT extraction_runs_markdown_media_file_id_fkey FOREIGN KEY (markdown_media_file_id) REFERENCES public.media_files(id) ON DELETE SET NULL;


--
-- Name: extraction_runs extraction_runs_source_media_file_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.extraction_runs
    ADD CONSTRAINT extraction_runs_source_media_file_id_fkey FOREIGN KEY (source_media_file_id) REFERENCES public.media_files(id) ON DELETE SET NULL;


--
-- Name: legal_documents legal_documents_institution_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_documents
    ADD CONSTRAINT legal_documents_institution_id_fkey FOREIGN KEY (institution_id) REFERENCES public.institutions(id);


--
-- Name: legal_documents legal_documents_official_journal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_documents
    ADD CONSTRAINT legal_documents_official_journal_id_fkey FOREIGN KEY (official_journal_id) REFERENCES public.official_journals(id);


--
-- Name: legal_documents legal_documents_type_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_documents
    ADD CONSTRAINT legal_documents_type_code_fkey FOREIGN KEY (type_code) REFERENCES public.document_types(code);


--
-- Name: media_files media_files_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_files
    ADD CONSTRAINT media_files_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.legal_documents(id) ON DELETE CASCADE;


--
-- Name: mobile_profiles mobile_profiles_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mobile_profiles
    ADD CONSTRAINT mobile_profiles_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: model_has_permissions model_has_permissions_permission_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: model_has_roles model_has_roles_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: notifications notifications_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_permission_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: structure_nodes structure_nodes_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.structure_nodes
    ADD CONSTRAINT structure_nodes_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.legal_documents(id) ON DELETE CASCADE;


--
-- Name: taggables taggables_tag_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.taggables
    ADD CONSTRAINT taggables_tag_id_fkey FOREIGN KEY (tag_id) REFERENCES public.tags(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict 0tsx5cVhsiSjObk2g59QVzrZdFxzuwXpwDrdFpCNCgyYBrTxFwFnJsT5ecrtrXY

--
-- PostgreSQL database dump
--

\restrict QYYYCb1jBDh2NCfFxsTkss2y3ijRWCfNzu0RWpwDiALwC1RLfV4vngbTQ9VcNJl

-- Dumped from database version 16.11 (Debian 16.11-1.pgdg12+1)
-- Dumped by pg_dump version 18.1

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 1, false);


--
-- PostgreSQL database dump complete
--

\unrestrict QYYYCb1jBDh2NCfFxsTkss2y3ijRWCfNzu0RWpwDiALwC1RLfV4vngbTQ9VcNJl

