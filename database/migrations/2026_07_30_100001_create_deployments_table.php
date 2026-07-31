<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ported from reference/src/db.ts bootstrapSchema() — the `deployments`
     * CREATE TABLE plus the commit_sha, commit_message and rollback_sha
     * columns added via ALTER TABLE in the reference. Folded into one
     * migration here.
     *
     * `app_id` cascades on delete, matching `REFERENCES apps(id) ON DELETE
     * CASCADE` in the reference schema.
     */
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('commit_sha')->nullable();
            $table->text('commit_message')->nullable();
            $table->string('rollback_sha')->nullable();
            $table->timestamps();

            $table->index('app_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
