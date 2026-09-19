<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `owner_id` is the single ownership fact and restricts deletion so an
 * organization cannot be orphaned below the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('personal')->default(false);
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['owner_id', 'personal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
