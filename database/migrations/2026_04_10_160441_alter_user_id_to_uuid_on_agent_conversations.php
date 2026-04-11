<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE agent_conversations ALTER COLUMN user_id TYPE uuid USING user_id::text::uuid');
        DB::statement('ALTER TABLE agent_conversation_messages ALTER COLUMN user_id TYPE uuid USING user_id::text::uuid');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE agent_conversations ALTER COLUMN user_id TYPE bigint USING NULL');
        DB::statement('ALTER TABLE agent_conversation_messages ALTER COLUMN user_id TYPE bigint USING NULL');
    }
};
