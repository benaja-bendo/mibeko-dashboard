<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contenu versionné et immuable une fois publié — mibeko-dashboard#136.
 *
 * Une nouvelle version = une nouvelle ligne, jamais un UPDATE du contenu
 * d'une version `published` (même doctrine que `Article`/`ArticleVersion` :
 * cf. `Article::activeVersion()`). `is_active` distingue LA version
 * couramment servie parmi toutes celles jamais publiées pour une `key` :
 * un index unique partiel arbitre l'unicité (une seule active par clé),
 * pas de contrainte Blueprint standard pour ça.
 *
 * `key` permet d'ajouter d'autres familles de parcours plus tard sans
 * nouvelle table ("onboarding" est la seule valeur utilisée par #136).
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const STATUSES = ['draft', 'published', 'archived'];

    public function up(): void
    {
        Schema::create('onboarding_journeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 60);
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_active')->default(false);
            $table->jsonb('definition');
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->unique(['key', 'version']);
        });

        DB::statement(
            'ALTER TABLE onboarding_journeys ADD CONSTRAINT onboarding_journeys_status_check '.
            "CHECK (status IN ('".implode("', '", self::STATUSES)."'))"
        );

        // Défense en profondeur en plus de la garde applicative
        // (OnboardingJourney::publish()) : une ligne active est forcément publiée.
        DB::statement(
            'ALTER TABLE onboarding_journeys ADD CONSTRAINT onboarding_journeys_active_implies_published_check '.
            "CHECK (NOT is_active OR status = 'published')"
        );

        // Une seule version active par famille de parcours.
        DB::statement(
            'CREATE UNIQUE INDEX onboarding_journeys_one_active_per_key '.
            'ON onboarding_journeys (key) WHERE is_active'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_journeys');
    }
};
