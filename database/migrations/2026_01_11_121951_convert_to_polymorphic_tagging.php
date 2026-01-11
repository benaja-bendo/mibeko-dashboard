<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop old trigger and function that depends on article_tag
        DB::unprepared('DROP TRIGGER IF EXISTS trg_refresh_tsv_on_tags ON article_tag');

        // 2. Create taggables table
        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignUuid('tag_id')->constrained('tags')->onDelete('cascade');
            $table->uuid('taggable_id');
            $table->string('taggable_type');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['tag_id', 'taggable_id', 'taggable_type']);
        });

        // Index for performance on inverse lookups
        Schema::table('taggables', function (Blueprint $table) {
            $table->index(['taggable_id', 'taggable_type'], 'idx_taggables_item');
        });

        // 3. Migrate data from article_tag to taggables
        DB::statement("
            INSERT INTO taggables (tag_id, taggable_id, taggable_type, created_at)
            SELECT tag_id, article_id, 'App\Models\Article', NOW() FROM article_tag
        ");

        // 4. Drop article_tag table
        Schema::dropIfExists('article_tag');

        // 5. Update the Postgres function to use taggables
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
                    -- We are in taggables
                    IF (TG_OP = 'DELETE') THEN
                        IF (OLD.taggable_type != 'App\Models\Article') THEN RETURN NULL; END IF;
                        article_id_to_update := OLD.taggable_id;
                    ELSE
                        IF (NEW.taggable_type != 'App\Models\Article') THEN RETURN NEW; END IF;
                        article_id_to_update := NEW.taggable_id;
                    END IF;
                END IF;

                -- Get all tags for this article using the new taggables table
                SELECT COALESCE(string_agg(name, ' '), '') INTO tags_text
                FROM tags
                JOIN taggables ON tags.id = taggables.tag_id
                WHERE taggables.taggable_id = article_id_to_update 
                  AND taggables.taggable_type = 'App\Models\Article';

                -- Update the search_tsv
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

        // 6. Attach trigger to taggables
        DB::unprepared('
            CREATE TRIGGER trg_refresh_tsv_on_tags
            AFTER INSERT OR DELETE OR UPDATE ON taggables
            FOR EACH ROW EXECUTE FUNCTION fn_refresh_article_version_tsv();
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop trigger and function
        DB::unprepared('DROP TRIGGER IF EXISTS trg_refresh_tsv_on_tags ON taggables');
        
        // Re-create article_tag
        Schema::create('article_tag', function (Blueprint $table) {
            $table->foreignUuid('article_id')->constrained('articles')->onDelete('cascade');
            $table->foreignUuid('tag_id')->constrained('tags')->onDelete('cascade');
            $table->primary(['article_id', 'tag_id']);
        });

        // Re-migrate data
        DB::statement("
            INSERT INTO article_tag (article_id, tag_id)
            SELECT taggable_id, tag_id FROM taggables WHERE taggable_type = 'App\Models\Article'
        ");

        // Drop taggables
        Schema::dropIfExists('taggables');

        // Restore original function (simplified for reverse)
        DB::unprepared("
            CREATE OR REPLACE FUNCTION fn_refresh_article_version_tsv()
            RETURNS TRIGGER AS $$
            DECLARE
                article_id_to_update UUID;
                tags_text TEXT;
            BEGIN
                IF (TG_RELNAME = 'article_versions') THEN
                    article_id_to_update := NEW.article_id;
                ELSE
                    IF (TG_OP = 'DELETE') THEN
                        article_id_to_update := OLD.article_id;
                    ELSE
                        article_id_to_update := NEW.article_id;
                    END IF;
                END IF;

                SELECT COALESCE(string_agg(name, ' '), '') INTO tags_text
                FROM tags
                JOIN article_tag ON tags.id = article_tag.tag_id
                WHERE article_tag.article_id = article_id_to_update;

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

        // Re-attach original trigger
        DB::unprepared('
            CREATE TRIGGER trg_refresh_tsv_on_tags
            AFTER INSERT OR DELETE OR UPDATE ON article_tag
            FOR EACH ROW EXECUTE FUNCTION fn_refresh_article_version_tsv();
        ');
    }
};
