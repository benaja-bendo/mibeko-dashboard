--
-- PostgreSQL database dump
--

\restrict 5AfYdVSWeLOKtnMQ202tcGqaDQN43r5PqAdAxJOMxONmmjBKiJQ6vIY10mDL9fz

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
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


--
-- Name: unaccent; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public;


--
-- Name: EXTENSION unaccent; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION unaccent IS 'text search dictionary that removes accents';


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
-- Name: f_unaccent(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.f_unaccent(text) RETURNS text
    LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $_$ SELECT public.unaccent('public.unaccent', $1) $_$;


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
-- Name: agent_message_feedback; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agent_message_feedback (
    id uuid NOT NULL,
    message_id character varying(36) NOT NULL,
    user_id uuid NOT NULL,
    rating character varying(4) NOT NULL,
    comment text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ai_quota_tier_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_quota_tier_settings (
    id uuid NOT NULL,
    tier character varying(20) NOT NULL,
    "limit" integer NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ai_usage_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_usage_logs (
    id uuid NOT NULL,
    user_id uuid,
    route character varying(40) NOT NULL,
    status character varying(20) NOT NULL,
    provider character varying(40),
    model character varying(60),
    tokens_input integer DEFAULT 0 NOT NULL,
    tokens_output integer DEFAULT 0 NOT NULL,
    cost_estimated_fcfa numeric(10,4),
    conversation_id character varying(36),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    error_class character varying(255),
    error_message character varying(500),
    tool_calls_count smallint,
    has_citation boolean
);


--
-- Name: app_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.app_settings (
    key character varying(255) NOT NULL,
    value text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
    reviewed_by uuid,
    reviewed_at timestamp(0) without time zone,
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
    auditable_id character varying(255) NOT NULL,
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
-- Name: contact_messages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.contact_messages (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    profile character varying(255),
    message text NOT NULL,
    ip_address character varying(45),
    user_agent character varying(255),
    handled boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: credit_ledger_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.credit_ledger_entries (
    id uuid NOT NULL,
    user_id uuid,
    type character varying(20) NOT NULL,
    amount integer NOT NULL,
    reason character varying(255),
    reference_id character varying(36),
    created_by uuid,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT credit_ledger_entries_amount_not_zero_check CHECK ((amount <> 0)),
    CONSTRAINT credit_ledger_entries_type_check CHECK (((type)::text = ANY (ARRAY[('purchase'::character varying)::text, ('consumption'::character varying)::text, ('correction'::character varying)::text])))
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
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    resolved_at timestamp(0) without time zone,
    resolved_by uuid,
    source character varying(20) DEFAULT 'human'::character varying NOT NULL,
    severity character varying(20) DEFAULT 'blocking'::character varying NOT NULL,
    node_id uuid,
    suggestion jsonb,
    anchor jsonb,
    confidence numeric(5,4),
    run_id uuid,
    created_by uuid
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
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    user_id uuid,
    app_version character varying(20)
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
    CONSTRAINT document_relations_relation_type_check CHECK (((relation_type)::text = ANY (ARRAY[('CREE'::character varying)::text, ('MODIFIE'::character varying)::text, ('ABROGE'::character varying)::text, ('CITE'::character varying)::text, ('COMPLETE'::character varying)::text, ('RENUMEROTE'::character varying)::text])))
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
-- Name: dossier_articles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dossier_articles (
    dossier_id uuid NOT NULL,
    article_id uuid NOT NULL,
    personal_note text,
    added_at bigint DEFAULT '0'::bigint NOT NULL
);


--
-- Name: dossier_echeances; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dossier_echeances (
    id uuid NOT NULL,
    dossier_id uuid NOT NULL,
    type character varying(255) DEFAULT 'autre'::character varying NOT NULL,
    title character varying(255) NOT NULL,
    due_date date,
    status character varying(255) DEFAULT 'a_venir'::character varying NOT NULL,
    trigger_event character varying(255),
    trigger_date date,
    rule_id character varying(255),
    basis_article_id uuid,
    is_confirmed boolean DEFAULT false NOT NULL,
    reminders json,
    note text,
    client_created_at bigint DEFAULT '0'::bigint NOT NULL,
    client_updated_at bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: dossier_generated_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dossier_generated_documents (
    id uuid NOT NULL,
    dossier_id uuid NOT NULL,
    template_id character varying(255) NOT NULL,
    template_name character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    html text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dossier_pieces; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dossier_pieces (
    id uuid NOT NULL,
    dossier_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    size bigint NOT NULL,
    mime character varying(255) NOT NULL,
    note text,
    added_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dossier_references; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dossier_references (
    id uuid NOT NULL,
    dossier_id uuid NOT NULL,
    target_id uuid NOT NULL,
    type character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    breadcrumb character varying(255),
    number character varying(255),
    note text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dossiers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dossiers (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    legal_domain character varying(255) DEFAULT 'Général'::character varying NOT NULL,
    tag character varying(32) DEFAULT 'EN_COURS'::character varying NOT NULL,
    description text,
    color character varying(9) DEFAULT '#1B3D2F'::character varying NOT NULL,
    client_created_at bigint DEFAULT '0'::bigint NOT NULL,
    client_updated_at bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    type character varying(255) DEFAULT 'contentieux'::character varying NOT NULL,
    status character varying(255) DEFAULT 'ouvert'::character varying NOT NULL,
    internal_reference character varying(255),
    client_name character varying(255),
    client_role character varying(255),
    adverse_party character varying(255),
    jurisdiction character varying(255),
    nature character varying(255)
);


--
-- Name: echeance_reminders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.echeance_reminders (
    id bigint NOT NULL,
    echeance_id uuid NOT NULL,
    offset_days smallint NOT NULL,
    sent_on date NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: echeance_reminders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.echeance_reminders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: echeance_reminders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.echeance_reminders_id_seq OWNED BY public.echeance_reminders.id;


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
    CONSTRAINT extraction_runs_source_check CHECK (((source)::text = ANY (ARRAY[('MINERU'::character varying)::text, ('MANUAL_UPLOAD'::character varying)::text, ('PARSING'::character varying)::text, ('STRUCTURATION_LLM'::character varying)::text]))),
    CONSTRAINT extraction_runs_status_check CHECK (((status)::text = ANY (ARRAY[('queued'::character varying)::text, ('running'::character varying)::text, ('succeeded'::character varying)::text, ('failed'::character varying)::text, ('partial'::character varying)::text, ('needs_review'::character varying)::text, ('discarded'::character varying)::text])))
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
-- Name: jurisprudence_citations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisprudence_citations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    decision_id uuid NOT NULL,
    cited_article_id uuid,
    reference_brute text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


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
    legal_scope character varying(20) DEFAULT 'national'::character varying NOT NULL,
    slug character varying(255),
    watch_notified_at timestamp(0) without time zone,
    date_entree_vigueur_inconnue boolean DEFAULT false NOT NULL,
    statut_verifie_le timestamp(0) without time zone,
    statut_verifie_par uuid,
    libelle_descriptif text,
    libelle_descriptif_source character varying(20),
    assigned_to uuid,
    assigned_at timestamp(0) without time zone,
    curation_status_changed_at timestamp(0) without time zone,
    provenance_inconnue boolean DEFAULT false NOT NULL,
    CONSTRAINT chk_legal_documents_role_logic CHECK (((((document_role)::text = 'STOCK'::text) AND (consolidation_as_of IS NOT NULL) AND (official_journal_id IS NULL) AND (stock_code IS NOT NULL)) OR (((document_role)::text = 'FLUX'::text) AND (consolidation_as_of IS NULL)))),
    CONSTRAINT legal_documents_curation_status_check CHECK (((curation_status)::text = ANY (ARRAY[('draft'::character varying)::text, ('review'::character varying)::text, ('validated'::character varying)::text, ('published'::character varying)::text]))),
    CONSTRAINT legal_documents_document_role_check CHECK (((document_role)::text = ANY (ARRAY[('STOCK'::character varying)::text, ('FLUX'::character varying)::text]))),
    CONSTRAINT legal_documents_legal_scope_check CHECK (((legal_scope)::text = ANY (ARRAY[('national'::character varying)::text, ('ohada'::character varying)::text, ('communautaire'::character varying)::text]))),
    CONSTRAINT legal_documents_libelle_descriptif_source_check CHECK ((((libelle_descriptif IS NULL) AND (libelle_descriptif_source IS NULL)) OR ((libelle_descriptif IS NOT NULL) AND ((libelle_descriptif_source)::text = ANY (ARRAY[('article'::character varying)::text, ('manuel'::character varying)::text]))))),
    CONSTRAINT legal_documents_statut_check CHECK (((statut)::text = ANY (ARRAY[('vigueur'::character varying)::text, ('abroge'::character varying)::text, ('projet'::character varying)::text])))
);


--
-- Name: legal_watch_dispatches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_watch_dispatches (
    id uuid NOT NULL,
    document_ids json NOT NULL,
    document_count integer NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    in_app_written_at timestamp(0) without time zone,
    pushes_dispatched_at timestamp(0) without time zone,
    delivered_at timestamp(0) without time zone,
    attempts smallint DEFAULT '0'::smallint NOT NULL,
    last_error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: manual_payment_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.manual_payment_orders (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    created_by uuid NOT NULL,
    verification_started_by uuid,
    resolved_by uuid,
    plan_grant_id uuid,
    idempotency_key uuid NOT NULL,
    reference character varying(40) NOT NULL,
    offer_code character varying(40) DEFAULT 'pro'::character varying NOT NULL,
    amount_fcfa integer NOT NULL,
    duration_months smallint NOT NULL,
    channel character varying(40) NOT NULL,
    payment_instructions text NOT NULL,
    status character varying(30) DEFAULT 'awaiting_payment'::character varying NOT NULL,
    payment_reference character varying(255),
    payment_declared_at timestamp(0) with time zone,
    verification_started_at timestamp(0) with time zone,
    activated_at timestamp(0) with time zone,
    rejected_at timestamp(0) with time zone,
    rejection_reason text,
    internal_notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
    page_count integer,
    CONSTRAINT media_files_file_category_check CHECK (((file_category)::text = ANY (ARRAY[('SOURCE_PDF'::character varying)::text, ('EXTRACTION_MARKDOWN'::character varying)::text, ('EXTRACTION_JSON'::character varying)::text]))),
    CONSTRAINT media_files_page_count_check CHECK (((page_count IS NULL) OR (page_count > 0)))
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
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    usage_context character varying(255),
    job_title character varying(255),
    CONSTRAINT mobile_profiles_profession_check CHECK (((profession IS NULL) OR ((profession)::text = ANY (ARRAY[('Citoyen'::character varying)::text, ('Étudiant'::character varying)::text, ('Professionnel du droit'::character varying)::text, ('Autre'::character varying)::text])))),
    CONSTRAINT mobile_profiles_usage_context_check CHECK (((usage_context IS NULL) OR ((usage_context)::text = ANY ((ARRAY['personal'::character varying, 'studies'::character varying, 'professional'::character varying, 'other'::character varying])::text[]))))
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
-- Name: newsletter_subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.newsletter_subscriptions (
    id uuid NOT NULL,
    email character varying(255) NOT NULL,
    source character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    dedupe_key character varying(191)
);


--
-- Name: official_journals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.official_journals (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    title character varying(255) NOT NULL,
    publication_date date NOT NULL,
    file_path character varying(512) NOT NULL,
    transcription_status character varying(255),
    is_published boolean DEFAULT false,
    number character varying(255),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0) without time zone
);


--
-- Name: onboarding_enrollments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.onboarding_enrollments (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    journey_id uuid NOT NULL,
    journey_key character varying(60) NOT NULL,
    status character varying(20) DEFAULT 'not_started'::character varying NOT NULL,
    started_at timestamp(0) with time zone,
    last_started_at timestamp(0) with time zone,
    completed_at timestamp(0) with time zone,
    postponed_at timestamp(0) with time zone,
    last_activity_at timestamp(0) with time zone,
    replay_count integer DEFAULT 0 NOT NULL,
    last_client_mutation_id character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT onboarding_enrollments_status_check CHECK (((status)::text = ANY ((ARRAY['not_started'::character varying, 'in_progress'::character varying, 'postponed'::character varying, 'completed'::character varying])::text[])))
);


--
-- Name: onboarding_journeys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.onboarding_journeys (
    id uuid NOT NULL,
    key character varying(60) NOT NULL,
    version integer NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    is_active boolean DEFAULT false NOT NULL,
    definition jsonb NOT NULL,
    published_at timestamp(0) with time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT onboarding_journeys_active_implies_published_check CHECK (((NOT is_active) OR ((status)::text = 'published'::text))),
    CONSTRAINT onboarding_journeys_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'published'::character varying, 'archived'::character varying])::text[])))
);


--
-- Name: onboarding_step_progress; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.onboarding_step_progress (
    id uuid NOT NULL,
    enrollment_id uuid NOT NULL,
    step_key character varying(100) NOT NULL,
    viewed_at timestamp(0) with time zone,
    skipped_at timestamp(0) with time zone,
    completed_at timestamp(0) with time zone,
    value jsonb,
    last_client_mutation_id character varying(255),
    client_updated_at bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
-- Name: plan_grant_movements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plan_grant_movements (
    id uuid NOT NULL,
    plan_grant_id uuid NOT NULL,
    manual_payment_order_id uuid,
    type character varying(20) NOT NULL,
    amount_fcfa integer NOT NULL,
    occurred_at timestamp(0) without time zone NOT NULL,
    reference_id character varying(64),
    reason text,
    created_by uuid,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT plan_grant_movements_amount_not_zero_check CHECK ((amount_fcfa <> 0)),
    CONSTRAINT plan_grant_movements_sign_check CHECK (((((type)::text = 'collected'::text) AND (amount_fcfa > 0)) OR (((type)::text = 'refund'::text) AND (amount_fcfa < 0)) OR ((type)::text = 'correction'::text))),
    CONSTRAINT plan_grant_movements_type_check CHECK (((type)::text = ANY (ARRAY[('collected'::character varying)::text, ('refund'::character varying)::text, ('correction'::character varying)::text])))
);


--
-- Name: plan_grant_reminders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plan_grant_reminders (
    id bigint NOT NULL,
    plan_grant_id uuid NOT NULL,
    offset_days smallint NOT NULL,
    sent_on date NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: plan_grant_reminders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.plan_grant_reminders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_grant_reminders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.plan_grant_reminders_id_seq OWNED BY public.plan_grant_reminders.id;


--
-- Name: plan_grants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plan_grants (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    plan character varying(20) DEFAULT 'pro'::character varying NOT NULL,
    starts_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    ends_at timestamp(0) without time zone NOT NULL,
    amount_fcfa integer,
    channel character varying(40),
    reference character varying(255),
    notes text,
    created_by uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    revoked_at timestamp(0) without time zone
);


--
-- Name: product_activation_cohort_stats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_activation_cohort_stats (
    id bigint NOT NULL,
    cohort_week date NOT NULL,
    cohort_size integer NOT NULL,
    reached_search_useful integer NOT NULL,
    reached_success_reply integer NOT NULL,
    reached_activation_candidate integer NOT NULL,
    median_days_to_activation numeric(6,2),
    d7_eligible integer NOT NULL,
    d7_returned integer NOT NULL,
    computed_at timestamp(0) without time zone NOT NULL
);


--
-- Name: product_activation_cohort_stats_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_activation_cohort_stats_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_activation_cohort_stats_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_activation_cohort_stats_id_seq OWNED BY public.product_activation_cohort_stats.id;


--
-- Name: product_activation_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_activation_events (
    id uuid NOT NULL,
    user_id uuid,
    event_type character varying(30) NOT NULL,
    surface character varying(10) NOT NULL,
    usage_context character varying(20),
    onboarding_journey_version integer,
    reference_type character varying(20) NOT NULL,
    reference_id uuid NOT NULL,
    duration_ms integer,
    client_event_id character varying(100) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT product_activation_events_event_type_check CHECK (((event_type)::text = ANY ((ARRAY['search_useful'::character varying, 'source_opened_after_answer'::character varying])::text[]))),
    CONSTRAINT product_activation_events_surface_check CHECK (((surface)::text = ANY ((ARRAY['web'::character varying, 'mobile'::character varying])::text[])))
);


--
-- Name: publication_checklists; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.publication_checklists (
    id uuid NOT NULL,
    document_id uuid NOT NULL,
    actor_id uuid,
    target_status character varying(20) NOT NULL,
    outcome character varying(20) NOT NULL,
    criteria jsonb NOT NULL,
    document_snapshot_updated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT publication_checklists_outcome_check CHECK (((outcome)::text = ANY (ARRAY[('passed'::character varying)::text, ('blocked'::character varying)::text, ('forced'::character varying)::text])))
);


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
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0) without time zone
);


--
-- Name: subscription_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_items (
    id bigint NOT NULL,
    subscription_id bigint NOT NULL,
    stripe_id character varying(255) NOT NULL,
    stripe_product character varying(255) NOT NULL,
    stripe_price character varying(255) NOT NULL,
    quantity integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    meter_id character varying(255),
    meter_event_name character varying(255)
);


--
-- Name: subscription_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscription_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscription_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscription_items_id_seq OWNED BY public.subscription_items.id;


--
-- Name: subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscriptions (
    id bigint NOT NULL,
    user_id uuid NOT NULL,
    type character varying(255) NOT NULL,
    stripe_id character varying(255) NOT NULL,
    stripe_status character varying(255) NOT NULL,
    stripe_price character varying(255),
    quantity integer,
    trial_ends_at timestamp(0) without time zone,
    ends_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: subscriptions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscriptions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscriptions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscriptions_id_seq OWNED BY public.subscriptions.id;


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
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP,
    icon character varying(255),
    description character varying(255),
    display_order integer DEFAULT 0 NOT NULL
);


--
-- Name: user_invitations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_invitations (
    id uuid NOT NULL,
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    roles json NOT NULL,
    invited_by uuid,
    accepted_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: user_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_settings (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    locale character varying(8) DEFAULT 'fr'::character varying NOT NULL,
    timezone character varying(64) DEFAULT 'Africa/Brazzaville'::character varying NOT NULL,
    date_format character varying(20) DEFAULT 'd/m/Y'::character varying NOT NULL,
    notification_preferences json,
    marketing_consent boolean DEFAULT false NOT NULL,
    marketing_consent_at timestamp(0) without time zone,
    analytics_consent boolean DEFAULT false NOT NULL,
    analytics_consent_at timestamp(0) without time zone,
    billing_info json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    theme character varying(255) DEFAULT 'mibeko-classic'::character varying NOT NULL,
    ai_quota_override_limit integer,
    ai_quota_override_note character varying(255)
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
    status character varying(255) DEFAULT 'active'::character varying,
    last_seen_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    stripe_id character varying(255),
    pm_type character varying(255),
    pm_last_four character varying(4),
    trial_ends_at timestamp(0) without time zone,
    suspended_at timestamp(0) without time zone,
    suspension_reason character varying(255),
    email_verification_required boolean DEFAULT false NOT NULL
);


--
-- Name: audits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audits ALTER COLUMN id SET DEFAULT nextval('public.audits_id_seq'::regclass);


--
-- Name: echeance_reminders id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.echeance_reminders ALTER COLUMN id SET DEFAULT nextval('public.echeance_reminders_id_seq'::regclass);


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
-- Name: plan_grant_reminders id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grant_reminders ALTER COLUMN id SET DEFAULT nextval('public.plan_grant_reminders_id_seq'::regclass);


--
-- Name: product_activation_cohort_stats id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_activation_cohort_stats ALTER COLUMN id SET DEFAULT nextval('public.product_activation_cohort_stats_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: subscription_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_items ALTER COLUMN id SET DEFAULT nextval('public.subscription_items_id_seq'::regclass);


--
-- Name: subscriptions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions ALTER COLUMN id SET DEFAULT nextval('public.subscriptions_id_seq'::regclass);


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
-- Name: agent_message_feedback agent_message_feedback_message_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_message_feedback
    ADD CONSTRAINT agent_message_feedback_message_id_user_id_unique UNIQUE (message_id, user_id);


--
-- Name: agent_message_feedback agent_message_feedback_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_message_feedback
    ADD CONSTRAINT agent_message_feedback_pkey PRIMARY KEY (id);


--
-- Name: ai_quota_tier_settings ai_quota_tier_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_quota_tier_settings
    ADD CONSTRAINT ai_quota_tier_settings_pkey PRIMARY KEY (id);


--
-- Name: ai_quota_tier_settings ai_quota_tier_settings_tier_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_quota_tier_settings
    ADD CONSTRAINT ai_quota_tier_settings_tier_unique UNIQUE (tier);


--
-- Name: ai_usage_logs ai_usage_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT ai_usage_logs_pkey PRIMARY KEY (id);


--
-- Name: app_settings app_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_settings
    ADD CONSTRAINT app_settings_pkey PRIMARY KEY (key);


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
-- Name: contact_messages contact_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contact_messages
    ADD CONSTRAINT contact_messages_pkey PRIMARY KEY (id);


--
-- Name: credit_ledger_entries credit_ledger_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_ledger_entries
    ADD CONSTRAINT credit_ledger_entries_pkey PRIMARY KEY (id);


--
-- Name: curation_flags curation_flags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curation_flags
    ADD CONSTRAINT curation_flags_pkey PRIMARY KEY (id);


--
-- Name: devices devices_device_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.devices
    ADD CONSTRAINT devices_device_id_unique UNIQUE (device_id);


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
-- Name: dossier_articles dossier_articles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_articles
    ADD CONSTRAINT dossier_articles_pkey PRIMARY KEY (dossier_id, article_id);


--
-- Name: dossier_echeances dossier_echeances_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_echeances
    ADD CONSTRAINT dossier_echeances_pkey PRIMARY KEY (id);


--
-- Name: dossier_generated_documents dossier_generated_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_generated_documents
    ADD CONSTRAINT dossier_generated_documents_pkey PRIMARY KEY (id);


--
-- Name: dossier_pieces dossier_pieces_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_pieces
    ADD CONSTRAINT dossier_pieces_pkey PRIMARY KEY (id);


--
-- Name: dossier_references dossier_references_dossier_id_target_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_references
    ADD CONSTRAINT dossier_references_dossier_id_target_id_unique UNIQUE (dossier_id, target_id);


--
-- Name: dossier_references dossier_references_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_references
    ADD CONSTRAINT dossier_references_pkey PRIMARY KEY (id);


--
-- Name: dossiers dossiers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossiers
    ADD CONSTRAINT dossiers_pkey PRIMARY KEY (id);


--
-- Name: echeance_reminders echeance_reminders_echeance_id_offset_days_sent_on_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.echeance_reminders
    ADD CONSTRAINT echeance_reminders_echeance_id_offset_days_sent_on_unique UNIQUE (echeance_id, offset_days, sent_on);


--
-- Name: echeance_reminders echeance_reminders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.echeance_reminders
    ADD CONSTRAINT echeance_reminders_pkey PRIMARY KEY (id);


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
-- Name: jurisprudence_citations jurisprudence_citations_decision_id_reference_brute_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisprudence_citations
    ADD CONSTRAINT jurisprudence_citations_decision_id_reference_brute_unique UNIQUE (decision_id, reference_brute);


--
-- Name: jurisprudence_citations jurisprudence_citations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisprudence_citations
    ADD CONSTRAINT jurisprudence_citations_pkey PRIMARY KEY (id);


--
-- Name: legal_documents legal_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_documents
    ADD CONSTRAINT legal_documents_pkey PRIMARY KEY (id);


--
-- Name: legal_documents legal_documents_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_documents
    ADD CONSTRAINT legal_documents_slug_unique UNIQUE (slug);


--
-- Name: legal_watch_dispatches legal_watch_dispatches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_watch_dispatches
    ADD CONSTRAINT legal_watch_dispatches_pkey PRIMARY KEY (id);


--
-- Name: manual_payment_orders manual_payment_orders_idempotency_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.manual_payment_orders
    ADD CONSTRAINT manual_payment_orders_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: manual_payment_orders manual_payment_orders_payment_reference_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.manual_payment_orders
    ADD CONSTRAINT manual_payment_orders_payment_reference_unique UNIQUE (payment_reference);


--
-- Name: manual_payment_orders manual_payment_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.manual_payment_orders
    ADD CONSTRAINT manual_payment_orders_pkey PRIMARY KEY (id);


--
-- Name: manual_payment_orders manual_payment_orders_plan_grant_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.manual_payment_orders
    ADD CONSTRAINT manual_payment_orders_plan_grant_id_unique UNIQUE (plan_grant_id);


--
-- Name: manual_payment_orders manual_payment_orders_reference_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.manual_payment_orders
    ADD CONSTRAINT manual_payment_orders_reference_unique UNIQUE (reference);


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
-- Name: mobile_profiles mobile_profiles_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mobile_profiles
    ADD CONSTRAINT mobile_profiles_user_id_unique UNIQUE (user_id);


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
-- Name: newsletter_subscriptions newsletter_subscriptions_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.newsletter_subscriptions
    ADD CONSTRAINT newsletter_subscriptions_email_unique UNIQUE (email);


--
-- Name: newsletter_subscriptions newsletter_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.newsletter_subscriptions
    ADD CONSTRAINT newsletter_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_user_dedupe_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_user_dedupe_unique UNIQUE (user_id, dedupe_key);


--
-- Name: official_journals official_journals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.official_journals
    ADD CONSTRAINT official_journals_pkey PRIMARY KEY (id);


--
-- Name: onboarding_enrollments onboarding_enrollments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.onboarding_enrollments
    ADD CONSTRAINT onboarding_enrollments_pkey PRIMARY KEY (id);


--
-- Name: onboarding_enrollments onboarding_enrollments_user_id_journey_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.onboarding_enrollments
    ADD CONSTRAINT onboarding_enrollments_user_id_journey_key_unique UNIQUE (user_id, journey_key);


--
-- Name: onboarding_journeys onboarding_journeys_key_version_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.onboarding_journeys
    ADD CONSTRAINT onboarding_journeys_key_version_unique UNIQUE (key, version);


--
-- Name: onboarding_journeys onboarding_journeys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.onboarding_journeys
    ADD CONSTRAINT onboarding_journeys_pkey PRIMARY KEY (id);


--
-- Name: onboarding_step_progress onboarding_step_progress_enrollment_id_step_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.onboarding_step_progress
    ADD CONSTRAINT onboarding_step_progress_enrollment_id_step_key_unique UNIQUE (enrollment_id, step_key);


--
-- Name: onboarding_step_progress onboarding_step_progress_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.onboarding_step_progress
    ADD CONSTRAINT onboarding_step_progress_pkey PRIMARY KEY (id);


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
-- Name: plan_grant_movements plan_grant_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grant_movements
    ADD CONSTRAINT plan_grant_movements_pkey PRIMARY KEY (id);


--
-- Name: plan_grant_reminders plan_grant_reminders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grant_reminders
    ADD CONSTRAINT plan_grant_reminders_pkey PRIMARY KEY (id);


--
-- Name: plan_grant_reminders plan_grant_reminders_plan_grant_id_offset_days_sent_on_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grant_reminders
    ADD CONSTRAINT plan_grant_reminders_plan_grant_id_offset_days_sent_on_unique UNIQUE (plan_grant_id, offset_days, sent_on);


--
-- Name: plan_grants plan_grants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grants
    ADD CONSTRAINT plan_grants_pkey PRIMARY KEY (id);


--
-- Name: product_activation_cohort_stats product_activation_cohort_stats_cohort_week_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_activation_cohort_stats
    ADD CONSTRAINT product_activation_cohort_stats_cohort_week_unique UNIQUE (cohort_week);


--
-- Name: product_activation_cohort_stats product_activation_cohort_stats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_activation_cohort_stats
    ADD CONSTRAINT product_activation_cohort_stats_pkey PRIMARY KEY (id);


--
-- Name: product_activation_events product_activation_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_activation_events
    ADD CONSTRAINT product_activation_events_pkey PRIMARY KEY (id);


--
-- Name: product_activation_events product_activation_events_user_id_event_type_client_event_id_un; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_activation_events
    ADD CONSTRAINT product_activation_events_user_id_event_type_client_event_id_un UNIQUE (user_id, event_type, client_event_id);


--
-- Name: publication_checklists publication_checklists_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.publication_checklists
    ADD CONSTRAINT publication_checklists_pkey PRIMARY KEY (id);


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
-- Name: subscription_items subscription_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_items
    ADD CONSTRAINT subscription_items_pkey PRIMARY KEY (id);


--
-- Name: subscription_items subscription_items_stripe_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_items
    ADD CONSTRAINT subscription_items_stripe_id_unique UNIQUE (stripe_id);


--
-- Name: subscriptions subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pkey PRIMARY KEY (id);


--
-- Name: subscriptions subscriptions_stripe_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_stripe_id_unique UNIQUE (stripe_id);


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
-- Name: user_invitations user_invitations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_invitations
    ADD CONSTRAINT user_invitations_pkey PRIMARY KEY (id);


--
-- Name: user_settings user_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_settings
    ADD CONSTRAINT user_settings_pkey PRIMARY KEY (id);


--
-- Name: user_settings user_settings_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_settings
    ADD CONSTRAINT user_settings_user_id_unique UNIQUE (user_id);


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
-- Name: agent_message_feedback_message_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_message_feedback_message_id_index ON public.agent_message_feedback USING btree (message_id);


--
-- Name: ai_usage_logs_conversation_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_usage_logs_conversation_id_index ON public.ai_usage_logs USING btree (conversation_id);


--
-- Name: ai_usage_logs_route_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_usage_logs_route_created_at_index ON public.ai_usage_logs USING btree (route, created_at);


--
-- Name: ai_usage_logs_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_usage_logs_user_id_created_at_index ON public.ai_usage_logs USING btree (user_id, created_at);


--
-- Name: contact_messages_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX contact_messages_created_at_index ON public.contact_messages USING btree (created_at);


--
-- Name: contact_messages_handled_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX contact_messages_handled_index ON public.contact_messages USING btree (handled);


--
-- Name: credit_ledger_entries_reference_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX credit_ledger_entries_reference_id_index ON public.credit_ledger_entries USING btree (reference_id);


--
-- Name: credit_ledger_entries_type_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX credit_ledger_entries_type_created_at_index ON public.credit_ledger_entries USING btree (type, created_at);


--
-- Name: credit_ledger_entries_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX credit_ledger_entries_user_id_created_at_index ON public.credit_ledger_entries USING btree (user_id, created_at);


--
-- Name: dossier_articles_article_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dossier_articles_article_id_index ON public.dossier_articles USING btree (article_id);


--
-- Name: dossier_echeances_dossier_id_deleted_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dossier_echeances_dossier_id_deleted_at_index ON public.dossier_echeances USING btree (dossier_id, deleted_at);


--
-- Name: dossier_echeances_due_date_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dossier_echeances_due_date_status_index ON public.dossier_echeances USING btree (due_date, status);


--
-- Name: dossier_generated_documents_dossier_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dossier_generated_documents_dossier_id_index ON public.dossier_generated_documents USING btree (dossier_id);


--
-- Name: dossier_pieces_dossier_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dossier_pieces_dossier_id_index ON public.dossier_pieces USING btree (dossier_id);


--
-- Name: dossiers_client_updated_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dossiers_client_updated_at_index ON public.dossiers USING btree (client_updated_at);


--
-- Name: dossiers_user_id_deleted_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dossiers_user_id_deleted_at_index ON public.dossiers USING btree (user_id, deleted_at);


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
-- Name: idx_curation_flags_doc_resolved_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_curation_flags_doc_resolved_source ON public.curation_flags USING btree (document_id, resolved, source);


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
-- Name: idx_legal_documents_libelle_descriptif_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_legal_documents_libelle_descriptif_trgm ON public.legal_documents USING gin (libelle_descriptif public.gin_trgm_ops);


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
-- Name: idx_versions_content_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_versions_content_trgm ON public.article_versions USING gin (public.f_unaccent(contenu_texte) public.gin_trgm_ops);


--
-- Name: idx_versions_embedding; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_versions_embedding ON public.article_versions USING hnsw (embedding public.vector_cosine_ops) WITH (m='16', ef_construction='64');


--
-- Name: idx_versions_search; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_versions_search ON public.article_versions USING gin (search_tsv);


--
-- Name: jurisprudence_citations_cited_article_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jurisprudence_citations_cited_article_id_index ON public.jurisprudence_citations USING btree (cited_article_id);


--
-- Name: legal_documents_legal_scope_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legal_documents_legal_scope_index ON public.legal_documents USING btree (legal_scope);


--
-- Name: legal_documents_watch_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legal_documents_watch_idx ON public.legal_documents USING btree (curation_status, watch_notified_at);


--
-- Name: legal_watch_dispatches_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX legal_watch_dispatches_status_idx ON public.legal_watch_dispatches USING btree (status, created_at);


--
-- Name: manual_payment_orders_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX manual_payment_orders_status_index ON public.manual_payment_orders USING btree (status);


--
-- Name: manual_payment_orders_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX manual_payment_orders_user_id_created_at_index ON public.manual_payment_orders USING btree (user_id, created_at);


--
-- Name: onboarding_journeys_one_active_per_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX onboarding_journeys_one_active_per_key ON public.onboarding_journeys USING btree (key) WHERE is_active;


--
-- Name: onboarding_journeys_one_draft_per_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX onboarding_journeys_one_draft_per_key ON public.onboarding_journeys USING btree (key) WHERE ((status)::text = 'draft'::text);


--
-- Name: plan_grant_movements_plan_grant_id_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX plan_grant_movements_plan_grant_id_type_index ON public.plan_grant_movements USING btree (plan_grant_id, type);


--
-- Name: plan_grant_movements_reference_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX plan_grant_movements_reference_id_index ON public.plan_grant_movements USING btree (reference_id);


--
-- Name: plan_grants_user_id_plan_starts_at_ends_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX plan_grants_user_id_plan_starts_at_ends_at_index ON public.plan_grants USING btree (user_id, plan, starts_at, ends_at);


--
-- Name: product_activation_events_event_type_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_activation_events_event_type_created_at_index ON public.product_activation_events USING btree (event_type, created_at);


--
-- Name: product_activation_events_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_activation_events_user_id_created_at_index ON public.product_activation_events USING btree (user_id, created_at);


--
-- Name: publication_checklists_document_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX publication_checklists_document_id_created_at_index ON public.publication_checklists USING btree (document_id, created_at);


--
-- Name: structure_nodes_deleted_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX structure_nodes_deleted_at_index ON public.structure_nodes USING btree (deleted_at);


--
-- Name: subscription_items_subscription_id_stripe_price_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX subscription_items_subscription_id_stripe_price_index ON public.subscription_items USING btree (subscription_id, stripe_price);


--
-- Name: subscriptions_user_id_stripe_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX subscriptions_user_id_stripe_status_index ON public.subscriptions USING btree (user_id, stripe_status);


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
-- Name: user_invitations_email_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_invitations_email_index ON public.user_invitations USING btree (email);


--
-- Name: users_stripe_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_stripe_id_index ON public.users USING btree (stripe_id);


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
-- Name: agent_message_feedback agent_message_feedback_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_message_feedback
    ADD CONSTRAINT agent_message_feedback_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: ai_usage_logs ai_usage_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT ai_usage_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: article_versions article_versions_article_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.article_versions
    ADD CONSTRAINT article_versions_article_id_fkey FOREIGN KEY (article_id) REFERENCES public.articles(id) ON DELETE CASCADE;


--
-- Name: article_versions article_versions_modifie_par_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.article_versions
    ADD CONSTRAINT article_versions_modifie_par_document_id_fkey FOREIGN KEY (modifie_par_document_id) REFERENCES public.legal_documents(id) ON DELETE SET NULL;


--
-- Name: article_versions article_versions_reviewed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.article_versions
    ADD CONSTRAINT article_versions_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES public.users(id) ON DELETE SET NULL;


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
-- Name: credit_ledger_entries credit_ledger_entries_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_ledger_entries
    ADD CONSTRAINT credit_ledger_entries_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: credit_ledger_entries credit_ledger_entries_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_ledger_entries
    ADD CONSTRAINT credit_ledger_entries_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: curation_flags curation_flags_article_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curation_flags
    ADD CONSTRAINT curation_flags_article_id_fkey FOREIGN KEY (article_id) REFERENCES public.articles(id) ON DELETE CASCADE;


--
-- Name: curation_flags curation_flags_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curation_flags
    ADD CONSTRAINT curation_flags_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: curation_flags curation_flags_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curation_flags
    ADD CONSTRAINT curation_flags_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.legal_documents(id) ON DELETE CASCADE;


--
-- Name: curation_flags curation_flags_node_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curation_flags
    ADD CONSTRAINT curation_flags_node_id_foreign FOREIGN KEY (node_id) REFERENCES public.structure_nodes(id) ON DELETE CASCADE;


--
-- Name: curation_flags curation_flags_resolved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curation_flags
    ADD CONSTRAINT curation_flags_resolved_by_foreign FOREIGN KEY (resolved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: devices devices_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.devices
    ADD CONSTRAINT devices_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: document_relations document_relations_source_article_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_relations
    ADD CONSTRAINT document_relations_source_article_id_fkey FOREIGN KEY (source_article_id) REFERENCES public.articles(id) ON DELETE CASCADE;


--
-- Name: document_relations document_relations_source_doc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_relations
    ADD CONSTRAINT document_relations_source_doc_id_fkey FOREIGN KEY (source_doc_id) REFERENCES public.legal_documents(id) ON DELETE CASCADE;


--
-- Name: document_relations document_relations_target_article_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_relations
    ADD CONSTRAINT document_relations_target_article_id_fkey FOREIGN KEY (target_article_id) REFERENCES public.articles(id) ON DELETE CASCADE;


--
-- Name: document_relations document_relations_target_doc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_relations
    ADD CONSTRAINT document_relations_target_doc_id_fkey FOREIGN KEY (target_doc_id) REFERENCES public.legal_documents(id) ON DELETE CASCADE;


--
-- Name: dossier_articles dossier_articles_article_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_articles
    ADD CONSTRAINT dossier_articles_article_id_foreign FOREIGN KEY (article_id) REFERENCES public.articles(id) ON DELETE CASCADE;


--
-- Name: dossier_articles dossier_articles_dossier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_articles
    ADD CONSTRAINT dossier_articles_dossier_id_foreign FOREIGN KEY (dossier_id) REFERENCES public.dossiers(id) ON DELETE CASCADE;


--
-- Name: dossier_echeances dossier_echeances_basis_article_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_echeances
    ADD CONSTRAINT dossier_echeances_basis_article_id_foreign FOREIGN KEY (basis_article_id) REFERENCES public.articles(id) ON DELETE SET NULL;


--
-- Name: dossier_echeances dossier_echeances_dossier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_echeances
    ADD CONSTRAINT dossier_echeances_dossier_id_foreign FOREIGN KEY (dossier_id) REFERENCES public.dossiers(id) ON DELETE CASCADE;


--
-- Name: dossier_generated_documents dossier_generated_documents_dossier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_generated_documents
    ADD CONSTRAINT dossier_generated_documents_dossier_id_foreign FOREIGN KEY (dossier_id) REFERENCES public.dossiers(id) ON DELETE CASCADE;


--
-- Name: dossier_pieces dossier_pieces_dossier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_pieces
    ADD CONSTRAINT dossier_pieces_dossier_id_foreign FOREIGN KEY (dossier_id) REFERENCES public.dossiers(id) ON DELETE CASCADE;


--
-- Name: dossier_references dossier_references_dossier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossier_references
    ADD CONSTRAINT dossier_references_dossier_id_foreign FOREIGN KEY (dossier_id) REFERENCES public.dossiers(id) ON DELETE CASCADE;


--
-- Name: dossiers dossiers_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dossiers
    ADD CONSTRAINT dossiers_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: echeance_reminders echeance_reminders_echeance_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.echeance_reminders
    ADD CONSTRAINT echeance_reminders_echeance_id_foreign FOREIGN KEY (echeance_id) REFERENCES public.dossier_echeances(id) ON DELETE CASCADE;


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
-- Name: jurisprudence_citations jurisprudence_citations_cited_article_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisprudence_citations
    ADD CONSTRAINT jurisprudence_citations_cited_article_id_foreign FOREIGN KEY (cited_article_id) REFERENCES public.articles(id) ON DELETE SET NULL;


--
-- Name: jurisprudence_citations jurisprudence_citations_decision_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisprudence_citations
    ADD CONSTRAINT jurisprudence_citations_decision_id_foreign FOREIGN KEY (decision_id) REFERENCES public.legal_documents(id) ON DELETE CASCADE;


--
-- Name: legal_documents legal_documents_assigned_to_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_documents
    ADD CONSTRAINT legal_documents_assigned_to_foreign FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


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
-- Name: legal_documents legal_documents_statut_verifie_par_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_documents
    ADD CONSTRAINT legal_documents_statut_verifie_par_foreign FOREIGN KEY (statut_verifie_par) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: legal_documents legal_documents_type_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_documents
    ADD CONSTRAINT legal_documents_type_code_fkey FOREIGN KEY (type_code) REFERENCES public.document_types(code);


--
-- Name: manual_payment_orders manual_payment_orders_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.manual_payment_orders
    ADD CONSTRAINT manual_payment_orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: manual_payment_orders manual_payment_orders_plan_grant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.manual_payment_orders
    ADD CONSTRAINT manual_payment_orders_plan_grant_id_foreign FOREIGN KEY (plan_grant_id) REFERENCES public.plan_grants(id) ON DELETE SET NULL;


--
-- Name: manual_payment_orders manual_payment_orders_resolved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.manual_payment_orders
    ADD CONSTRAINT manual_payment_orders_resolved_by_foreign FOREIGN KEY (resolved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: manual_payment_orders manual_payment_orders_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.manual_payment_orders
    ADD CONSTRAINT manual_payment_orders_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: manual_payment_orders manual_payment_orders_verification_started_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.manual_payment_orders
    ADD CONSTRAINT manual_payment_orders_verification_started_by_foreign FOREIGN KEY (verification_started_by) REFERENCES public.users(id) ON DELETE SET NULL;


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
-- Name: onboarding_enrollments onboarding_enrollments_journey_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.onboarding_enrollments
    ADD CONSTRAINT onboarding_enrollments_journey_id_foreign FOREIGN KEY (journey_id) REFERENCES public.onboarding_journeys(id) ON DELETE RESTRICT;


--
-- Name: onboarding_enrollments onboarding_enrollments_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.onboarding_enrollments
    ADD CONSTRAINT onboarding_enrollments_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: onboarding_step_progress onboarding_step_progress_enrollment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.onboarding_step_progress
    ADD CONSTRAINT onboarding_step_progress_enrollment_id_foreign FOREIGN KEY (enrollment_id) REFERENCES public.onboarding_enrollments(id) ON DELETE CASCADE;


--
-- Name: plan_grant_movements plan_grant_movements_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grant_movements
    ADD CONSTRAINT plan_grant_movements_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: plan_grant_movements plan_grant_movements_manual_payment_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grant_movements
    ADD CONSTRAINT plan_grant_movements_manual_payment_order_id_foreign FOREIGN KEY (manual_payment_order_id) REFERENCES public.manual_payment_orders(id) ON DELETE SET NULL;


--
-- Name: plan_grant_movements plan_grant_movements_plan_grant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grant_movements
    ADD CONSTRAINT plan_grant_movements_plan_grant_id_foreign FOREIGN KEY (plan_grant_id) REFERENCES public.plan_grants(id) ON DELETE CASCADE;


--
-- Name: plan_grant_reminders plan_grant_reminders_plan_grant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grant_reminders
    ADD CONSTRAINT plan_grant_reminders_plan_grant_id_foreign FOREIGN KEY (plan_grant_id) REFERENCES public.plan_grants(id) ON DELETE CASCADE;


--
-- Name: plan_grants plan_grants_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grants
    ADD CONSTRAINT plan_grants_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: plan_grants plan_grants_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_grants
    ADD CONSTRAINT plan_grants_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: product_activation_events product_activation_events_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_activation_events
    ADD CONSTRAINT product_activation_events_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: publication_checklists publication_checklists_actor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.publication_checklists
    ADD CONSTRAINT publication_checklists_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: publication_checklists publication_checklists_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.publication_checklists
    ADD CONSTRAINT publication_checklists_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.legal_documents(id) ON DELETE CASCADE;


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
-- Name: user_invitations user_invitations_invited_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_invitations
    ADD CONSTRAINT user_invitations_invited_by_foreign FOREIGN KEY (invited_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: user_settings user_settings_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_settings
    ADD CONSTRAINT user_settings_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict 5AfYdVSWeLOKtnMQ202tcGqaDQN43r5PqAdAxJOMxONmmjBKiJQ6vIY10mDL9fz

--
-- PostgreSQL database dump
--

\restrict YIy6wfBCxYT16XERrNH9dTcoFhLrvXWkWbKELKuFiSd4GfCa4vc3l93LKHA5Kh5

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
1	2022_08_03_000000_create_vector_extension	1
2	2026_06_08_225744_add_legal_scope_to_legal_documents_table	2
3	2026_06_09_101540_add_premium_settings_tables_and_user_profile_fields	2
4	2026_06_09_110026_create_customer_columns	2
5	2026_06_09_110027_create_subscriptions_table	2
6	2026_06_09_110028_create_subscription_items_table	2
7	2026_06_09_110029_add_meter_id_to_subscription_items_table	2
8	2026_06_09_110030_add_meter_event_name_to_subscription_items_table	2
9	2026_06_10_125649_add_theme_to_user_settings_table	3
10	2026_06_11_182831_create_dossiers_table	4
11	2026_06_14_114320_add_resolution_tracking_to_curation_flags_table	5
12	2026_06_15_123659_add_web_fields_to_dossiers_table	6
13	2026_06_15_123659_create_dossier_echeances_table	6
14	2026_06_15_132207_create_echeance_reminders_table	7
15	2026_06_15_140000_add_suspension_tracking_to_users_table	7
16	2026_06_15_140100_create_user_invitations_table	7
17	2026_06_15_150000_change_audits_auditable_id_to_string	8
18	2026_06_16_100000_add_theme_fields_to_tags_table	9
19	2026_06_20_100000_widen_official_journals_file_path	10
21	2026_06_21_192045_enable_trigram_fuzzy_article_search	11
23	2026_06_25_100000_add_slug_to_legal_documents_table	12
24	2026_06_25_120000_create_contact_messages_table	13
25	2026_06_26_100000_fix_document_deletion_cascades	14
26	2026_06_26_110000_extend_curation_flags_for_detection	15
27	2026_06_27_173818_create_agent_message_feedback_table	16
28	2026_07_03_191423_create_newsletter_subscriptions_table	17
29	2026_07_03_202911_add_jurist_review_to_article_versions_table	18
30	2026_07_03_205603_create_dossier_annexes_tables	18
32	2026_07_04_144711_extend_extraction_runs_status_check	19
33	2026_07_20_121602_extend_extraction_runs_source_check	20
34	2026_07_21_124041_fix_default_timezone_to_brazzaville	21
35	2026_07_30_153610_add_user_and_unique_constraint_to_devices_table	22
36	2026_07_30_153611_add_watch_notified_at_to_legal_documents_table	22
37	2026_07_30_211054_add_dedupe_key_to_notifications_table	22
38	2026_07_30_211055_create_legal_watch_dispatches_table	22
40	2026_07_30_213330_enable_push_for_new_document_preference	23
41	2026_08_01_183510_create_app_settings_table	24
43	2026_08_02_150000_add_date_entree_vigueur_inconnue_to_legal_documents_table	25
44	2026_07_30_224500_add_app_version_to_devices_table	26
46	2026_08_03_100000_normalise_curation_status_and_add_check	27
47	2026_08_10_120000_add_statut_verification_to_legal_documents_table	28
48	2026_08_11_211931_add_soft_deletes_to_structure_nodes_table	29
49	2026_08_16_133440_add_libelle_descriptif_to_legal_documents_table	30
51	2026_08_29_140000_add_page_count_to_media_files_table	31
52	2026_09_03_060000_create_ai_usage_logs_table	32
54	2026_09_04_190000_create_credit_ledger_entries_table	33
56	2026_09_05_120000_add_error_context_to_ai_usage_logs_table	34
57	2026_09_03_101500_set_default_active_on_users_status	35
60	2026_09_05_040254_create_ai_quota_tier_settings_table	36
61	2026_09_05_040255_add_ai_quota_override_to_user_settings_table	36
62	2026_09_06_001951_create_plan_grants_table	37
63	2026_09_06_090606_normalize_mobile_profiles_profession_values	38
64	2026_09_06_110216_add_tool_calls_count_to_ai_usage_logs_table	39
65	2026_09_06_153252_create_jurisprudence_citations_table	40
66	2026_09_10_081616_create_manual_payment_orders_table	41
67	2026_09_10_120000_add_revoked_at_to_plan_grants_table	41
68	2026_09_10_120001_create_plan_grant_reminders_table	41
69	2026_09_11_090000_create_plan_grant_movements_table	42
70	2026_09_11_140000_add_review_assignment_to_legal_documents_table	43
71	2026_09_11_140001_add_created_by_to_curation_flags_table	43
72	2026_09_11_162230_create_publication_checklists_table	44
73	2026_09_11_162231_add_provenance_inconnue_to_legal_documents_table	44
74	2026_09_12_075936_add_usage_context_and_job_title_to_mobile_profiles_table	45
75	2026_09_12_075937_add_unique_user_id_to_mobile_profiles_table	45
79	2026_09_12_094450_create_onboarding_journeys_table	46
80	2026_09_12_094451_create_onboarding_enrollments_table	46
81	2026_09_12_094452_create_onboarding_step_progress_table	46
88	2026_09_12_122609_add_has_citation_to_ai_usage_logs_table	47
89	2026_09_12_122611_create_product_activation_events_table	47
90	2026_09_12_122612_create_product_activation_cohort_stats_table	47
91	2026_09_13_120000_add_email_verification_required_to_users_table	48
92	2026_09_13_130000_use_light_theme_as_default_for_user_settings	48
93	2026_09_13_081200_enforce_one_draft_per_onboarding_journey_key	49
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 93, true);


--
-- PostgreSQL database dump complete
--

\unrestrict YIy6wfBCxYT16XERrNH9dTcoFhLrvXWkWbKELKuFiSd4GfCa4vc3l93LKHA5Kh5

