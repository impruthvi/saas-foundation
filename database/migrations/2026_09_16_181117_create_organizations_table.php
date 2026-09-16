<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant, and the subject of every subscription and entitlement (D1).
 *
 * `owner_id` is the single writable fact for ownership; a membership's role is
 * rank and never says Owner (D23). It restricts on delete so the database is a
 * backstop under the application rule that refuses to orphan an organization
 * when its owner deletes their account (D25).
 *
 * Column types stay portable: Postgres is the documented path, SQLite is the
 * local default, and nothing here costs MySQL anything (D3).
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
