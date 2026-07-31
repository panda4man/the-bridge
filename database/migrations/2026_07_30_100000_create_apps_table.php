<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ported from reference/src/db.ts bootstrapSchema() — the `apps` CREATE
     * TABLE plus the health_url, health_check_interval, webhook_secret and
     * deploy_steps columns that the reference adds via best-effort ALTER
     * TABLE statements after boot. Folded into one migration here.
     *
     * `path` gets a real unique index. In the reference, uniqueness is only
     * enforced in application code (see appValidators.ts) — this is an
     * explicit fix, not a carry-forward.
     */
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('repo_url');
            $table->string('branch')->default('main');
            $table->string('path')->unique();
            $table->string('status')->default('idle');
            $table->string('health_url')->nullable();
            $table->unsignedInteger('health_check_interval')->default(60);
            $table->string('webhook_secret')->nullable();
            $table->text('deploy_steps')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
