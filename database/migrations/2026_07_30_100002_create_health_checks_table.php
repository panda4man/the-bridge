<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ported from reference/src/db.ts bootstrapSchema() `health_checks`
     * CREATE TABLE. Note: this table intentionally has no
     * created_at/updated_at — only `checked_at`, matching the reference.
     */
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('status')->default('unknown');
            $table->integer('http_status_code')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->timestamp('checked_at')->useCurrent();

            $table->index('app_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
