<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier keeps no event history, so this is what names the event behind an
 * organization's plan. No organization column: events arrive before one is known, so
 * rows are keyed by Stripe customer. applied_at is set once so a superseded redelivery
 * does not undo the first application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_event_id')->nullable()->unique();
            $table->string('type')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_object_id')->nullable();
            $table->unsignedBigInteger('stripe_created_at')->nullable();
            $table->string('outcome');
            $table->string('outcome_reason')->nullable();
            $table->text('outcome_message')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('deliveries')->default(1);
            $table->json('payload');
            $table->timestamp('first_received_at');
            $table->timestamp('last_received_at');

            $table->index(['stripe_customer_id', 'stripe_created_at']);
            $table->index(['outcome', 'last_received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
