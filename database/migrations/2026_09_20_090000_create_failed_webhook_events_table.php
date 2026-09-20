<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing events this application answered for but could not place against an
 * organization.
 *
 * Stripe stops redelivering once an endpoint answers, so an event dropped here
 * is gone from its side. The whole payload is kept so the work can be replayed
 * after whatever made it unplaceable is fixed.
 *
 * Rows are never updated and carry no organization: not belonging to one is the
 * reason they exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_event_id')->nullable()->index();
            $table->string('type')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->json('payload');
            $table->string('reason');
            $table->text('message');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_webhook_events');
    }
};
