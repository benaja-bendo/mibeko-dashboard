<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX onboarding_journeys_one_draft_per_key ON onboarding_journeys (key) WHERE status = 'draft'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS onboarding_journeys_one_draft_per_key');
    }
};
