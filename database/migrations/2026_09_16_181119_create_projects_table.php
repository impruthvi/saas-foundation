<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first genuinely tenant-owned model, written here on purpose (D21).
 *
 * Minimal by intent: M5 adds the `projects` limit, the usage meter and the
 * upgrade prompt. It exists now so the tenancy boundary has a consumer in the
 * milestone that builds it, rather than meeting its first real one at M5 with
 * four milestones stacked on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
