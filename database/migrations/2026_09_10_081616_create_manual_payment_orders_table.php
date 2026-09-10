<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('manual_payment_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('created_by');
            $table->uuid('verification_started_by')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->uuid('plan_grant_id')->nullable()->unique();
            $table->uuid('idempotency_key')->unique();
            $table->string('reference', 40)->unique();
            $table->string('offer_code', 40)->default('pro');
            $table->unsignedInteger('amount_fcfa');
            $table->unsignedSmallInteger('duration_months');
            $table->string('channel', 40);
            $table->text('payment_instructions');
            $table->string('status', 30)->default('awaiting_payment')->index();
            $table->string('payment_reference', 255)->nullable()->unique();
            $table->timestampTz('payment_declared_at')->nullable();
            $table->timestampTz('verification_started_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('verification_started_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('plan_grant_id')->references('id')->on('plan_grants')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_payment_orders');
    }
};
