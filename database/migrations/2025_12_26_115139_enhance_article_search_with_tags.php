<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop the generated column
        DB::statement('ALTER TABLE article_versions DROP COLUMN search_tsv');

        // 2. Add as a regular column
        DB::statement('ALTER TABLE article_versions ADD COLUMN search_tsv TSVECTOR');
        DB::statement('CREATE INDEX idx_versions_search ON article_versions USING GIN (search_tsv)');

        // 3. Create the refresh function
        DB::unprepared("
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
                    -- We are in article_tag
                    IF (TG_OP = 'DELETE') THEN
                        article_id_to_update := OLD.article_id;
                    ELSE
                        article_id_to_update := NEW.article_id;
                    END IF;
                END IF;

                -- Get all tags for this article
                SELECT COALESCE(string_agg(name, ' '), '') INTO tags_text
                FROM tags
                JOIN article_tag ON tags.id = article_tag.tag_id
                WHERE article_tag.article_id = article_id_to_update;

                -- Update all versions of this article (or just the one being changed)
                -- For article_versions trigger, we can just update NEW.search_tsv
                -- For article_tag trigger, we must update all versions of that article
                
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
        ");

        // 4. Attach triggers
        DB::unprepared('
            CREATE TRIGGER trg_refresh_tsv_on_version
            BEFORE INSERT OR UPDATE OF contenu_texte ON article_versions
            FOR EACH ROW EXECUTE FUNCTION fn_refresh_article_version_tsv();

            CREATE TRIGGER trg_refresh_tsv_on_tags
            AFTER INSERT OR DELETE OR UPDATE ON article_tag
            FOR EACH ROW EXECUTE FUNCTION fn_refresh_article_version_tsv();
        ');

        // 5. Initial update for existing data
        DB::statement("
            UPDATE article_versions v
            SET search_tsv = (
                setweight(to_tsvector('french', COALESCE(v.contenu_texte, '')), 'A') ||
                setweight(to_tsvector('french', COALESCE((
                    SELECT string_agg(t.name, ' ')
                    FROM tags t
                    JOIN article_tag at ON t.id = at.tag_id
                    WHERE at.article_id = v.article_id
                ), '')), 'B')
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_refresh_tsv_on_version ON article_versions');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_refresh_tsv_on_tags ON article_tag');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_refresh_article_version_tsv()');

        DB::statement('ALTER TABLE article_versions DROP COLUMN search_tsv');
        DB::statement("ALTER TABLE article_versions ADD COLUMN search_tsv TSVECTOR GENERATED ALWAYS AS (to_tsvector('french', contenu_texte)) STORED");
        DB::statement('CREATE INDEX idx_versions_search ON article_versions USING GIN (search_tsv)');
    }
};
