<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periods in which an operator acted as a user.
 *
 * A platform record, not a tenant one: a user belongs to several organizations
 * and an impersonation moves between them. Rows outlive both people so the
 * history stays readable after an account is closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by')->nullable();

            $table->index(['user_id', 'ended_at']);
            $table->index(['operator_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonations');
    }
};
