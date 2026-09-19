<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An offer of membership, made to an email address rather than to a user.
 *
 * The address is the subject because the invitee usually has no account yet;
 * that is the whole reason the flow exists. `user_id` appears only once the
 * offer is taken, as `accepted_by_user_id`.
 *
 * `token_hash` is unique and carries no companion index — the unique constraint
 * is the index. The column holds a sha-256 digest, never the token itself: a
 * readable token column is a password column nobody calls one, and read access
 * to this table would otherwise be membership of every organization with a live
 * invitation.
 *
 * `(organization_id, email)` is unique, so an address has at most one
 * invitation per organization and re-inviting rotates that row. The tempting
 * alternative, a partial unique index over pending rows only, is not portable
 * across the supported databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash')->unique();
            $table->string('status');
            $table->timestamp('expires_at');

            // The inviter is history, not a dependency: deleting their account
            // must not delete an invitation somebody is about to accept.
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'email']);

            // The members screen lists one organization's pending invitations,
            // newest first. Every other read arrives by token.
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
