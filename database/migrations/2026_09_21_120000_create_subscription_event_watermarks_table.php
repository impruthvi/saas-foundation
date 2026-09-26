<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier writes whatever arrives, so an active update after a deleted one restores an
 * ended subscription. Separate from subscriptions because the row may not exist yet: a
 * deletion arriving before its creation needs something to compare against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_event_watermarks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->unsignedBigInteger('event_created_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_event_watermarks');
    }
};
