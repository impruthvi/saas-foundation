<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people allowed into the admin console.
 *
 * A table of its own rather than a flag on `users`, so the grant cannot be set
 * through a profile form, and so it leaves with the console when the console is
 * removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('granted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
