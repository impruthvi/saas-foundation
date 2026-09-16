<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link between a user and an organization, carrying role and status.
 *
 * Never a seat: a seat is a billed quantity, not a person (CONTEXT.md).
 *
 * The table is tenant-owned like any other, and the one question it answers
 * that cannot be scoped — which organizations does this user belong to, asked
 * before any organization is resolved — goes through a single audited
 * repository (D22). That query drives the `user_id` index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('status');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
