<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The newest Stripe event already applied for a subscription.
 *
 * Stripe delivers at least once and in no guaranteed order, and Cashier writes
 * whatever arrives, so an `active` update delivered after a `deleted` restores a
 * subscription the provider has already ended.
 *
 * This lives beside `subscriptions` rather than on it because the row may not
 * exist when the watermark is needed: Cashier's deletion handler updates an
 * existing subscription and writes nothing when there is none, so a deletion
 * arriving before its creation would otherwise leave nothing to compare against.
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
